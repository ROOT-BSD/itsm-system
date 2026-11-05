// Перехід до збігу після глобального пошуку: адреса містить ?hl=<запит>. Виділяє збіги в <main>, розгортає
// згорнуті блоки, прокручує до першого й показує панель «попередній / наступний / закрити».
(function () {
    'use strict';
    var q = new URLSearchParams(location.search).get('hl');
    var main = document.querySelector('main');
    if (!q || !main) { return; }
    q = q.trim().slice(0, 100);
    if (q.length < 2) { return; }

    function esc(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    var alts = [esc(q)];
    (q.match(/[\p{L}\p{N}]+/gu) || []).forEach(function (t) {
        if (t.length >= 2 && t.toLowerCase() !== q.toLowerCase()) { alts.push(esc(t)); }
    });
    var re = new RegExp(alts.join('|'), 'giu');
    var SKIP = {SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, MARK: 1, NOSCRIPT: 1, BUTTON: 1};

    var nodes = [];
    var walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT, {
        acceptNode: function (n) {
            if (!n.nodeValue || !n.nodeValue.trim()) { return NodeFilter.FILTER_REJECT; }
            for (var p = n.parentNode; p && p !== main; p = p.parentNode) {
                if (SKIP[p.nodeName] || (p.classList && p.classList.contains('search-bar'))) { return NodeFilter.FILTER_REJECT; }
            }
            return NodeFilter.FILTER_ACCEPT;
        }
    });
    while (walker.nextNode()) { nodes.push(walker.currentNode); }

    var hits = [];
    nodes.forEach(function (n) {
        var text = n.nodeValue, m, last = 0, frag = null;
        re.lastIndex = 0;
        while ((m = re.exec(text)) !== null) {
            if (m[0] === '') { re.lastIndex++; continue; }
            frag = frag || document.createDocumentFragment();
            if (m.index > last) { frag.appendChild(document.createTextNode(text.slice(last, m.index))); }
            var mk = document.createElement('mark');
            mk.className = 'search-hit';
            mk.textContent = m[0];
            frag.appendChild(mk);
            hits.push(mk);
            last = m.index + m[0].length;
        }
        if (frag) {
            if (last < text.length) { frag.appendChild(document.createTextNode(text.slice(last))); }
            n.parentNode.replaceChild(frag, n);
        }
    });
    if (!hits.length) { return; }

    var cur = -1;
    var bar = document.createElement('div');
    bar.className = 'search-bar shadow';
    var label = document.createElement('span');
    function btn(text, title, fn) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary'; b.textContent = text; b.title = title;
        b.addEventListener('click', fn);
        return b;
    }
    function go(d) {
        if (cur >= 0) { hits[cur].classList.remove('search-hit-current'); }
        cur = (cur + d + hits.length) % hits.length;
        var el = hits[cur];
        el.classList.add('search-hit-current');
        for (var p = el.parentElement; p && p !== main; p = p.parentElement) {
            if (p.tagName === 'DETAILS') { p.open = true; }
            if (p.classList.contains('collapse') && !p.classList.contains('show')) { p.classList.add('show'); }
            if (p.classList.contains('tab-pane') && window.bootstrap) {
                var t = document.querySelector('[data-bs-target="#' + p.id + '"],[href="#' + p.id + '"]');
                if (t) { window.bootstrap.Tab.getOrCreateInstance(t).show(); }
            }
        }
        el.scrollIntoView({block: 'center', behavior: 'smooth'});
        label.textContent = 'Збіг ' + (cur + 1) + ' з ' + hits.length + ' · «' + q + '»';
    }
    bar.appendChild(label);
    bar.appendChild(btn('▲', 'Попередній збіг', function () { go(-1); }));
    bar.appendChild(btn('▼', 'Наступний збіг', function () { go(1); }));
    bar.appendChild(btn('✕', 'Прибрати виділення', function () {
        hits.forEach(function (m) { m.replaceWith(document.createTextNode(m.textContent)); });
        bar.remove();
    }));
    document.body.appendChild(bar);
    go(1);
})();
