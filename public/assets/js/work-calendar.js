/* Адмін → Черги тікетів: кнопка «Додати типові свята України» дописує у список свят відсутні фіксовані дати. */
(function () {
    'use strict';
    var btn = document.getElementById('wc_fill_ua'), box = document.getElementById('wc_holidays');
    var self = document.currentScript;
    if (!btn || !box || !self) { return; }
    btn.addEventListener('click', function () {
        var have = box.value.split(/[\s,;]+/).filter(Boolean);
        (self.getAttribute('data-ua') || '').split(',').forEach(function (d) {
            if (d && have.indexOf(d) === -1) { have.push(d); }
        });
        box.value = have.join('\n');
    });
})();
