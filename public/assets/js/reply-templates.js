/* «Готова відповідь»: вставляє вибраний шаблон (текст уже підготовлено сервером з підстановкою) у поле відповіді в позицію курсора. */
(function () {
  var sel = document.getElementById('reply-template-select');
  if (!sel) { return; }
  var ta = document.getElementById(sel.getAttribute('data-target'));
  if (!ta) { return; }
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    var text = opt ? opt.getAttribute('data-body') : null;
    sel.selectedIndex = 0;
    if (!text) { return; }
    var start = ta.selectionStart || 0, end = ta.selectionEnd || 0;
    var before = ta.value.slice(0, start), after = ta.value.slice(end);
    var sep = before && !/\n$/.test(before) ? '\n' : '';
    ta.value = before + sep + text + after;
    var pos = (before + sep + text).length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    ta.rows = Math.max(ta.rows, Math.min(12, text.split('\n').length + 1));
  });
})();
