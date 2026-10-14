/**
 * Generic table search filter.
 * Attaches an "input" listener to the given search box and hides/shows
 * <tbody> rows in the given table based on whether the row's text
 * contains the typed query (case-insensitive).
 *
 * Usage:
 *   <input type="text" id="tableSearch" placeholder="Search...">
 *   <table id="myTable">...</table>
 *   <script>attachTableSearch('tableSearch', '#myTable');</script>
 */
function attachTableSearch(inputId, tableSelector, options) {
    options = options || {};
    const input = document.getElementById(inputId);
    const table = document.querySelector(tableSelector);
    if (!input || !table) return;

    const emptyMessage = options.emptyMessage || 'No matching results.';
    let noResultsRow = null;

    function applyFilter() {
        const query = input.value.trim().toLowerCase();
        const tbody = table.querySelector('tbody') || table;
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r !== noResultsRow);
        let visibleCount = 0;

        rows.forEach(function (row) {
            const text = row.textContent.toLowerCase();
            const matches = query === '' || text.includes(query);
            row.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        if (visibleCount === 0 && query !== '') {
            if (!noResultsRow) {
                noResultsRow = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = 50;
                td.style.textAlign = 'center';
                td.style.padding = '16px';
                td.style.opacity = '0.7';
                td.textContent = emptyMessage;
                noResultsRow.appendChild(td);
                tbody.appendChild(noResultsRow);
            }
            noResultsRow.style.display = '';
        } else if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }

    input.addEventListener('input', applyFilter);
}
