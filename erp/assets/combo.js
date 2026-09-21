/*
 * 검색되는 선택칸 — 거래처(company_id) 선택칸과 data-search 가 붙은 선택칸을
 * "코드 또는 이름을 치면 걸러지는 입력칸" 으로 바꿉니다.
 *
 *   · 원래 <select> 는 화면에서만 숨기고 그대로 둡니다. 저장되는 값도 그 select 의 값이라
 *     화면(PHP)은 한 줄도 바꿀 필요가 없습니다. 고르면 change 이벤트를 그대로 보내서
 *     onchange="this.form.submit()" 같은 기존 동작도 그대로 됩니다.
 *   · 옵션 글자가 "이름 (코드)" 이면 목록에는 "코드  이름" 으로 보여줍니다.
 *   · 띄어쓰기 · 대소문자는 무시하고 찾습니다 ("웰더스 스마트" = "웰더스스마트").
 */
(function () {
  var MAX = 60;

  function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ''); }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }
  function isEmptyVal(v) { return v === '' || v === '0'; }

  // "이름 (코드) — 3명" → {code, name, extra}
  function split(text) {
    var m = /^(.*?)\s*\(([^()]*)\)\s*(.*)$/.exec(text);
    if (!m) { return {code: '', name: text, extra: ''}; }
    return {code: m[2], name: m[1], extra: m[3]};
  }

  function enhance(sel) {
    if (sel.getAttribute('data-combo') === '1') { return; }
    sel.setAttribute('data-combo', '1');

    var opts = [];
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i], t = o.text.trim();
      opts.push({v: o.value, t: t, n: norm(t), empty: isEmptyVal(o.value), p: split(t)});
    }
    var blank = null;
    opts.forEach(function (o) { if (o.empty && !blank) { blank = o; } });

    var wrap = document.createElement('div');
    wrap.className = 'combo';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'combo-in';
    inp.autocomplete = 'off';
    inp.spellcheck = false;
    inp.setAttribute('role', 'combobox');
    inp.setAttribute('aria-autocomplete', 'list');
    inp.setAttribute('aria-expanded', 'false');
    inp.placeholder = sel.getAttribute('data-search') || '거래처 코드 또는 이름으로 검색';
    var list = document.createElement('div');
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    wrap.appendChild(inp);
    wrap.appendChild(list);
    sel.classList.add('combo-sel');
    sel.tabIndex = -1;

    // <label for="원래 select id"> 를 누르면 입력칸으로 가게
    if (sel.id) {
      var lab = document.querySelector('label[for="' + sel.id + '"]');
      inp.id = sel.id + '__q';
      if (lab) { lab.htmlFor = inp.id; }
    }
    // 필수 표시는 입력칸이 대신 갖습니다 (숨긴 select 에 있으면 브라우저가 경고를 못 띄웁니다)
    if (sel.required) { sel.required = false; inp.required = true; }

    function label() {
      var o = sel.options[sel.selectedIndex];
      return o && !isEmptyVal(o.value) ? o.text.trim() : '';
    }
    inp.value = label();
    if (blank && !inp.value) { inp.placeholder = inp.placeholder + ' · ' + blank.t; }

    var hits = [], active = -1;

    function row(o, idx) {
      var body = o.empty
        ? '<span class="combo-blank">' + esc(o.t) + '</span>'
        : (o.p.code ? '<b>' + esc(o.p.code) + '</b>' : '') + '<span>' + esc(o.p.name) + '</span>'
          + (o.p.extra ? '<small>' + esc(o.p.extra) + '</small>' : '');
      return '<div class="combo-item' + (idx === active ? ' on' : '') + '" role="option" data-i="' + idx + '">'
           + body + '</div>';
    }

    function render(q) {
      var nq = norm(q);
      var found = opts.filter(function (o) { return !o.empty && (nq === '' || o.n.indexOf(nq) >= 0); });
      // 코드가 그대로 맞는 것, 이름이 그 글자로 시작하는 것을 위로
      if (nq) {
        found.sort(function (a, b) {
          var sa = (norm(a.p.code) === nq ? 0 : norm(a.p.code).indexOf(nq) === 0 ? 1 : norm(a.p.name).indexOf(nq) === 0 ? 2 : 3);
          var sb = (norm(b.p.code) === nq ? 0 : norm(b.p.code).indexOf(nq) === 0 ? 1 : norm(b.p.name).indexOf(nq) === 0 ? 2 : 3);
          return sa - sb;
        });
      }
      var more = found.length - MAX;
      hits = found.slice(0, MAX);
      if (blank && nq === '') { hits.unshift(blank); }
      active = hits.length ? 0 : -1;
      var html = hits.map(row).join('');
      if (!hits.length) { html = '<div class="combo-none">맞는 항목이 없습니다</div>'; }
      if (more > 0) { html += '<div class="combo-none">… ' + more + '곳 더 있습니다. 더 입력해 좁혀 주세요</div>'; }
      list.innerHTML = html;
      list.hidden = false;
      inp.setAttribute('aria-expanded', 'true');
    }

    function close() {
      list.hidden = true;
      inp.setAttribute('aria-expanded', 'false');
    }

    function choose(o) {
      var before = sel.value;
      sel.value = o.v;
      inp.value = o.empty ? '' : o.t;
      close();
      if (sel.value !== before) {
        sel.dispatchEvent(new Event('change', {bubbles: true}));
      }
    }

    function highlight(n) {
      var items = list.querySelectorAll('.combo-item');
      if (!items.length) { return; }
      active = (n + items.length) % items.length;
      items.forEach(function (el, i) { el.classList.toggle('on', i === active); });
      items[active].scrollIntoView({block: 'nearest'});
    }

    inp.addEventListener('focus', function () { inp.select(); render(''); });
    inp.addEventListener('input', function () { render(inp.value); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { render(inp.value); } else { highlight(active + 1); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
      else if (e.key === 'Enter') {
        if (!list.hidden && active >= 0 && hits[active]) { e.preventDefault(); choose(hits[active]); }
      } else if (e.key === 'Escape') { close(); inp.value = label(); }
    });
    // mousedown 은 blur 보다 먼저 옵니다 — click 을 쓰면 목록이 먼저 닫혀 못 고릅니다
    list.addEventListener('mousedown', function (e) {
      var it = e.target.closest('.combo-item');
      e.preventDefault();
      if (it) { choose(hits[+it.getAttribute('data-i')]); }
    });
    inp.addEventListener('blur', function () {
      setTimeout(function () {
        if (!list.hidden) { close(); }
        var typed = inp.value.trim();
        if (typed === '') {
          // 비우면 '선택 안 함' 으로
          if (!isEmptyVal(sel.value)) { choose(blank || {v: '', t: '', empty: true}); }
        } else if (typed !== label()) {
          // 목록에서 고르지 않고 글자만 친 경우: 딱 하나만 맞으면 그걸로, 아니면 원래 값으로 되돌림
          var nq = norm(typed);
          var only = opts.filter(function (o) { return !o.empty && o.n.indexOf(nq) >= 0; });
          if (only.length === 1) { choose(only[0]); } else { inp.value = label(); }
        }
      }, 120);
    });
    // 뒤로 가기 등으로 select 값이 바뀌면 입력칸도 따라가게
    sel.addEventListener('change', function () { inp.value = label(); });
  }

  function run() {
    document.querySelectorAll('select[name="company_id"], select[data-search]').forEach(enhance);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
