/* Картка тікета: кнопка «↪ у відповідь» біля статті бази знань вставляє «Назва: посилання» в поле відповіді (у позицію курсора). */
(function () {
    'use strict';
    var box = document.getElementById('reply-body');
    if (!box) { return; }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-kb-insert]') : null;
        if (!b) { return; }
        e.preventDefault();
        var text = b.getAttribute('data-kb-insert');
        var start = box.selectionStart, end = box.selectionEnd, v = box.value;
        var pre = v.slice(0, start), post = v.slice(end);
        var ins = (pre && !/\n$/.test(pre) ? '\n' : '') + text + '\n';
        box.value = pre + ins + post;
        box.focus();
        box.selectionStart = box.selectionEnd = (pre + ins).length;
    });
})();
