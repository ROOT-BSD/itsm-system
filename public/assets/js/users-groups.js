/*
 * Сторінка «Користувачі»: групи підрозділів згорнуті; після дії з користувачем (редагування, пароль, активація,
 * блокування…) сервер повертає на /admin/users#u<id> — тут відповідну групу розгортаємо, прокручуємо до рядка й
 * ставимо на нього фокус. Кнопки «Розгорнути всі / Згорнути всі». Без скрипта групи просто клікаються (<details>).
 */
(function () {
    'use strict';
    function focusHash() {
        var m = /^#(u\d+)$/.exec(location.hash);
        if (!m) { return; }
        var row = document.getElementById(m[1]);
        if (!row) { return; }
        var d = row.closest('details');
        if (d) { d.open = true; }
        row.scrollIntoView({ block: 'center' });
        row.focus({ preventScroll: true });
        row.classList.add('table-warning');
        window.setTimeout(function () { row.classList.remove('table-warning'); }, 2500);
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-users-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                var open = b.getAttribute('data-users-toggle') === 'open';
                document.querySelectorAll('details.users-group').forEach(function (d) { d.open = open; });
            });
        });
        focusHash();
    });
    window.addEventListener('hashchange', focusHash);
})();
