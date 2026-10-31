(function () {
    var body = document.getElementById('project-access-body');
    var btn = document.getElementById('project-access-toggle');
    if (!body || !btn) return;
    var key = 'projectAccessCollapsed';
    function sync() {
        var open = body.classList.contains('show');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.classList.toggle('is-collapsed', !open);
    }
    // Повідомлення про результат дії (успіх/помилка) показуємо завжди, тож блок лишається розгорнутим.
    var hasFlash = !!body.querySelector('.alert-success, .alert-danger');
    try {
        if (!hasFlash && localStorage.getItem(key) === '1') body.classList.remove('show');
    } catch (e) {}
    sync();
    body.addEventListener('shown.bs.collapse', function () { try { localStorage.setItem(key, '0'); } catch (e) {} sync(); });
    body.addEventListener('hidden.bs.collapse', function () { try { localStorage.setItem(key, '1'); } catch (e) {} sync(); });
})();
