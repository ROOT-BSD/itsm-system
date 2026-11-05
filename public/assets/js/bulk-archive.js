(function () {
    var form = document.getElementById('bulk-archive-form');
    if (!form) return;
    var all = document.getElementById('bulk-archive-all');
    var btn = document.getElementById('bulk-archive-btn');
    var cnt = document.getElementById('bulk-archive-count');
    var items = form.querySelectorAll('.bulk-archive-item');
    function sync() {
        var n = 0;
        items.forEach(function (c) { if (c.checked) n++; });
        cnt.textContent = n;
        btn.disabled = n === 0;
        if (all) { all.checked = n > 0 && n === items.length; all.indeterminate = n > 0 && n < items.length; }
    }
    items.forEach(function (c) { c.addEventListener('change', sync); });
    if (all) all.addEventListener('change', function () { items.forEach(function (c) { c.checked = all.checked; }); sync(); });
    sync();
})();
