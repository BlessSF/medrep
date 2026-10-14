/**
 * Shared AJAX save/refresh helpers.
 *
 * Problem this solves: several pages used to save data with a plain
 * <form> POST (full browser navigation) or called window.location.reload()
 * after a fetch() just to show the new data. Both cause a full-page
 * flash/flicker, lose scroll position, and briefly show a blank screen.
 *
 * This file gives every page three small building blocks instead:
 *   - showToast()      -> consistent success/error banner (no reload)
 *   - ajaxForm()        -> hijacks a <form> submit, posts it via fetch,
 *                          and hands the JSON response to a callback
 *   - ajaxAction()       -> same thing for a single click (delete/block/
 *                          unblock links that used to be full navigations)
 *   - refreshContainer() -> re-fetches the CURRENT page in the background
 *                          and swaps just one container's HTML in place,
 *                          for saves that change server-computed totals
 *                          (balances, charts, grand totals) where a plain
 *                          DOM patch isn't enough. This is still much
 *                          smoother than window.location.reload() because
 *                          it never shows a blank/white page.
 *
 * All backing PHP endpoints keep working exactly as before for
 * non-JS/no-JS fallback: they only take the AJAX/JSON branch when the
 * request carries the X-Requested-With: XMLHttpRequest header, which
 * these helpers always add.
 */

/**
 * Turn a fetch Response into the {success, message, ...} shape every
 * caller here expects, whether the endpoint sent real JSON or not.
 *
 * Guards against a failure mode where the request never reaches our
 * PHP at all -- a dead session/proxy/host-level error page, a WAF
 * block, a 404 from a misconfigured path, etc. -- and the browser
 * gets back a full HTML document instead of JSON. Dumping that raw
 * HTML into a toast via textContent used to render the *entire page
 * markup* as one giant wall of text on screen. Now any non-JSON
 * response that looks like HTML/a document (or is just too long to
 * be a real inline message) is swapped for a short, honest error
 * instead of being shown verbatim.
 */
function parseAjaxResponse(r) {
    const contentType = r.headers.get('content-type') || '';
    if (contentType.indexOf('application/json') !== -1) {
        return r.json();
    }
    return r.text().then(function (text) {
        const trimmed = text.trim();
        const looksLikeDocument = /^<(!doctype|html)/i.test(trimmed) || /<\/html\s*>/i.test(trimmed);
        if (!r.ok || looksLikeDocument || trimmed.length > 300) {
            return {
                success: false,
                message: 'Server error (HTTP ' + r.status + '). Please try again, or reload the page if this keeps happening.'
            };
        }
        // Short, plain-text legacy response (e.g. "Error updating record: ...") -
        // safe to show as-is.
        return { success: r.ok, message: trimmed };
    });
}

/**
 * Show a small toast banner, styled like the existing
 * .success-message / .error-message flash messages, and auto-dismiss it.
 * Re-implements the fade-out-then-remove snippet that used to be
 * copy-pasted at the bottom of several pages.
 */
function showToast(message, type = 'success', duration = 3000) {
    if (!message) return;
    document.querySelectorAll('.ajax-toast').forEach(function (el) { el.remove(); });

    const el = document.createElement('div');
    el.className = 'ajax-toast ' + (type === 'error' ? 'error-message' : 'success-message');
    el.textContent = message;
    document.body.appendChild(el);

    setTimeout(function () {
        el.style.transition = 'opacity 0.5s ease';
        el.style.opacity = 0;
        setTimeout(function () { el.remove(); }, 500);
    }, duration);
}

/**
 * Re-fetch the current page in the background and swap ONE container's
 * innerHTML with the freshly-rendered version, instead of navigating
 * the whole browser window. Use this after a save that changes
 * server-computed values (totals, balances, charts) that would be too
 * error-prone to patch by hand in JS.
 *
 * @param {string} selector - CSS selector present on this page AND in
 *   the response HTML (e.g. '.table-scroll', '#txnTable').
 * @param {string} [url] - defaults to the current URL (same filters/query).
 * @returns {Promise<boolean>} true if the swap happened.
 */
function refreshContainer(selector, url) {
    const target = document.querySelector(selector);
    if (!target) return Promise.resolve(false);

    target.classList.add('ajax-refreshing');

    return fetch(url || window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
    })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.querySelector(selector);
            if (fresh) {
                target.innerHTML = fresh.innerHTML;
            }
            return !!fresh;
        })
        .finally(function () {
            target.classList.remove('ajax-refreshing');
        });
}

/**
 * Hijack a <form>'s submit so it saves via fetch() instead of a full
 * page navigation. The endpoint is expected to respond with JSON:
 *   { "success": true,  "message": "..." , ...extra fields }
 *   { "success": false, "message": "..." }
 * (falls back gracefully to a plain-text body if the endpoint doesn't
 * return JSON, e.g. legacy "Error updating record: ..." strings.)
 *
 * @param {HTMLFormElement|string} form  - form element or selector
 * @param {Object} [opts]
 * @param {string}   [opts.confirmMessage] - confirm() text before sending
 * @param {Function} [opts.onSuccess]      - (data, form) => void
 * @param {Function} [opts.onError]        - (message, form) => void
 * @param {boolean}  [opts.resetOnSuccess] - reset() the form after success (default true)
 */
function ajaxForm(form, opts) {
    opts = opts || {};
    if (typeof form === 'string') form = document.querySelector(form);
    if (!form || form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (opts.confirmMessage && !window.confirm(opts.confirmMessage)) {
            return;
        }

        const submitBtn = form.querySelector('[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.textContent : null;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.dataset.originalText = originalBtnText;
            submitBtn.textContent = 'Saving...';
        }
        form.classList.add('ajax-saving');

        // Belt-and-suspenders AJAX signal: some hosting setups (proxies,
        // certain shared-hosting configs) strip the X-Requested-With
        // header before PHP ever sees it, which makes the backend think
        // this is a plain form submit and redirect instead of returning
        // JSON -- fetch() then silently follows that redirect and hands
        // back a full HTML page as a "200 OK", which used to surface as
        // a broken/blank save. A form field survives that stripping.
        const body = new FormData(form);
        body.set('ajax', '1');

        fetch(form.action, {
            method: form.method || 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
            .then(parseAjaxResponse)
            .then(function (data) {
                if (data.success) {
                    showToast(data.message || 'Saved.', 'success');
                    if (opts.resetOnSuccess !== false) form.reset();
                    if (opts.onSuccess) opts.onSuccess(data, form);
                } else {
                    showToast(data.message || 'Save failed. Please try again.', 'error');
                    if (opts.onError) opts.onError(data.message, form);
                }
            })
            .catch(function (err) {
                showToast('Request failed: ' + err.message, 'error');
                if (opts.onError) opts.onError(err.message, form);
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText;
                }
                form.classList.remove('ajax-saving');
            });
    });
}

/**
 * Fire a one-off GET/POST action (the kind that used to be a plain
 * <a href="delete.php?id=..."> or window.location.href navigation) via
 * fetch instead, and hand the JSON result to a callback -- no page
 * navigation at all.
 *
 * @param {Object} config
 * @param {string} config.url
 * @param {string} [config.method='POST']
 * @param {string} [config.confirmMessage]
 * @param {Function} [config.onSuccess] - (data) => void
 * @param {Function} [config.onError]   - (message) => void
 */
function ajaxAction(config) {
    if (config.confirmMessage && !window.confirm(config.confirmMessage)) {
        return Promise.resolve(false);
    }

    // Same fallback as ajaxForm() -- see comment there. Appended as a
    // query param since ajaxAction() calls don't carry a body.
    const ajaxUrl = config.url + (config.url.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';

    return fetch(ajaxUrl, {
        method: config.method || 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(parseAjaxResponse)
        .then(function (data) {
            if (data.success) {
                showToast(data.message || 'Done.', 'success');
                if (config.onSuccess) config.onSuccess(data);
            } else {
                showToast(data.message || 'Action failed. Please try again.', 'error');
                if (config.onError) config.onError(data.message);
            }
            return data.success;
        })
        .catch(function (err) {
            showToast('Request failed: ' + err.message, 'error');
            if (config.onError) config.onError(err.message);
            return false;
        });
}
