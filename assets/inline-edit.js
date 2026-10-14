/**
 * Generic inline table-cell editor.
 * Any <td> inside the given table with class "editable-cell" becomes
 * click-to-edit: contenteditable, saved via AJAX on blur/Enter, reverted
 * on Escape or on save failure.
 *
 * Required data attributes on each editable cell:
 *   data-id    -> row's database id
 *   data-field -> database column name (must be in the endpoint's whitelist)
 *   data-type  -> "number" or "text" (optional, defaults to "text")
 *
 * Usage:
 *   <td class="editable-cell" data-id="12" data-field="dinein" data-type="number">150.00</td>
 *   <script>enableInlineEdit('#myTable', 'update_field.php');</script>
 */
// Shows a save error right on the cell instead of a native alert().
// Native alert() is easy to lose track of -- browsers let people
// permanently mute a page's alerts after dismissing a few of them,
// which made past save failures look like the edit was silently
// getting thrown away. This is always visible.
function showInlineEditError(cell, message) {
    console.error('Inline edit save failed:', message);
    cell.classList.add('save-error');
    cell.setAttribute('title', message);

    const bubble = document.createElement('div');
    bubble.className = 'inline-edit-error-bubble';
    bubble.textContent = message;
    cell.style.position = cell.style.position || 'relative';
    cell.appendChild(bubble);

    setTimeout(function () {
        bubble.remove();
        cell.classList.remove('save-error');
        cell.setAttribute('title', 'Click to edit');
    }, 4000);
}

function enableInlineEdit(tableSelector, endpoint) {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    table.querySelectorAll('.editable-cell').forEach(function (cell) {
        if (cell.dataset.inlineEditBound === '1') return; // avoid double-binding
        cell.dataset.inlineEditBound = '1';

        cell.setAttribute('contenteditable', 'true');
        cell.setAttribute('title', 'Click to edit');
        cell.classList.add('inline-editable');

        let originalValue = cell.textContent.trim();

        cell.addEventListener('focus', function () {
            originalValue = cell.textContent.trim();
            cell.classList.add('editing');
        });

        cell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                cell.blur();
            } else if (e.key === 'Escape') {
                cell.textContent = originalValue;
                cell.blur();
            }
        });

        cell.addEventListener('blur', function () {
            cell.classList.remove('editing');
            // Contenteditable can insert non-breaking spaces (\u00A0) when
            // text is retyped in the browser; normalize before comparing
            // and before sending, or an edit that looks unchanged (or a
            // date string) can silently fail to register/parse.
            let newValue = cell.textContent.replace(/[\u00A0\s]+/g, ' ').trim();

            if (newValue === originalValue) return;

            const id = cell.getAttribute('data-id');
            const field = cell.getAttribute('data-field');
            const type = cell.getAttribute('data-type') || 'text';

            if (!id || !field) return;

            if (type === 'number') {
                const cleaned = newValue.replace(/[₱,\s]/g, '');
                if (cleaned !== '' && isNaN(parseFloat(cleaned))) {
                    showInlineEditError(cell, 'Please enter a valid number.');
                    cell.textContent = originalValue;
                    return;
                }
            }

            cell.classList.add('saving');

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ id: id, field: field, value: newValue })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    cell.classList.remove('saving');
                    if (data.success) {
                        if (data.display !== undefined) {
                            cell.textContent = data.display;
                        }
                        originalValue = cell.textContent.trim();
                        cell.classList.add('saved-flash');
                        setTimeout(function () { cell.classList.remove('saved-flash'); }, 900);
                    } else {
                        showInlineEditError(cell, data.error || 'Failed to save. Please try again.');
                        cell.textContent = originalValue;
                    }
                })
                .catch(function (err) {
                    cell.classList.remove('saving');
                    showInlineEditError(cell, 'Save failed: ' + err.message);
                    cell.textContent = originalValue;
                });
        });
    });
}
