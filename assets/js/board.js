/* GOODPOST 게시판 로더: ERP 게시판 API(또는 예시 JSON)에서 목록 · 본문을 읽어 그립니다.
 * 사용법: <div class="board" data-board="notice"> … 안에 .board__head 를 두면 그 아래에 행을 그립니다.
 *   목록  : ?page=2&q=검색어      본문 : ?id=46
 * 설정은 assets/js/site-config.js 의 GOODPOST_SITE.boards 를 따릅니다. */
(function () {
  'use strict';
  var box = document.querySelector('[data-board]');
  if (!box || !window.GOODPOST_SITE) return;
  var key = box.getAttribute('data-board');
  var cfg = (window.GOODPOST_SITE.boards || {})[key];
  if (!cfg) return;

  var root = box.getAttribute('data-root') || '../';           // 사이트 루트까지의 상대 경로
  var api = /^(https?:)?\/\//.test(cfg.api) ? cfg.api : root + cfg.api;
  var isStatic = /\.json(\?|$)/.test(api);                      // 예시 JSON(페이징 · 검색을 브라우저에서 처리)
  var four = box.classList.contains('board--4');                // Q&A 형(번호 · 제목 · 작성자 · 등록일)
  var head = box.querySelector('.board__head');
  var tools = document.querySelector('.board-tools');
  var pager = document.querySelector('.pager');
  var form = tools && tools.querySelector('form');
  var input = form && form.querySelector('input');
  var writeBtn = document.querySelector('[data-board-write]');
  var params = new URLSearchParams(location.search);
  var page = Math.max(1, parseInt(params.get('page'), 10) || 1);
  var q = (params.get('q') || '').trim();
  var id = params.get('id');
  var size = cfg.pageSize || 15;
  var detailBox = null;

  if (writeBtn && cfg.write) { writeBtn.href = cfg.write; if (!/^https?:/.test(cfg.write)) { writeBtn.removeAttribute('target'); } }
  if (input) input.value = q;
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    location.search = '?' + new URLSearchParams(input.value.trim() ? { q: input.value.trim() } : {}).toString();
  });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(n) { return (n == null || n === '') ? '-' : Number(n).toLocaleString('ko-KR'); }
  function isNew(d) { var t = Date.parse(d); return t && (Date.now() - t) < 7 * 86400000; }
  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }
  function link(p, extra) {
    var sp = new URLSearchParams(extra || {});
    if (p > 1) sp.set('page', p);
    if (q) sp.set('q', q);
    var s = sp.toString();
    return location.pathname.split('/').pop() + (s ? '?' + s : '');
  }
  function clearRows() {
    Array.prototype.slice.call(box.querySelectorAll('.board__row, .board__empty')).forEach(function (el) { el.remove(); });
  }
  function message(html) {
    clearRows();
    var p = document.createElement('p');
    p.className = 'board__empty';
    p.innerHTML = html;
    box.appendChild(p);
  }
  function fail() {
    message('게시글을 불러오지 못했습니다. <a href="' + esc(cfg.link) + '" target="_blank" rel="noopener">ERP 게시판에서 보기</a>');
    if (pager) pager.innerHTML = '';
  }

  /* ---------- 목록 ---------- */
  function renderList(data) {
    var items = data.items || [];
    clearRows();
    if (!items.length) { message(q ? '"' + esc(q) + '" 검색 결과가 없습니다.' : '등록된 글이 없습니다.'); }
    var total = data.total != null ? data.total : items.length;
    var n = 0;                                                    // 일반 글 순번(공지 제외)
    items.forEach(function (it) {
      var a = document.createElement('a');
      a.className = 'board__row' + (it.pinned ? ' is-pinned' : '');
      a.href = link(page, { id: it.id });
      var no = it.pinned ? '공지' : (it.no != null ? it.no : total - (page - 1) * size - (n++));
      var title = (it.secret ? '<span class="lock" aria-label="비밀글">🔒</span> ' : '') + esc(it.title)
        + (it.answered ? '<span class="badge badge--done">답변완료</span>' : '')
        + (isNew(it.date) ? '<span class="badge">NEW</span>' : '');
      a.innerHTML = four
        ? '<span class="no">' + esc(no) + '</span><span class="title">' + title + '</span><span class="writer">' + esc(it.writer || '-') + '</span><span class="date">' + esc(it.date || '') + '</span>'
        : '<span class="no">' + esc(no) + '</span><span class="title">' + title + '</span><span class="date">' + esc(it.date || '') + '</span><span class="hit">' + num(it.views) + '</span>';
      box.appendChild(a);
    });
    renderPager(total);
  }
  function renderPager(total) {
    if (!pager) return;
    var pages = Math.max(1, Math.ceil(total / size));
    var start = Math.floor((page - 1) / 5) * 5 + 1, end = Math.min(pages, start + 4), html = '';
    if (start > 1) html += '<a href="' + link(start - 1) + '" aria-label="이전">‹</a>';
    for (var p = start; p <= end; p++) html += '<a href="' + link(p) + '"' + (p === page ? ' class="is-active" aria-current="page"' : '') + '>' + p + '</a>';
    if (end < pages) html += '<a href="' + link(end + 1) + '" aria-label="다음">›</a>';
    pager.innerHTML = html;
  }
  function loadList() {
    if (isStatic) {
      return fetchJson(api).then(function (data) {
        var all = (data.items || data || []).slice();
        if (q) all = all.filter(function (it) { return (it.title || '').indexOf(q) >= 0 || (it.content || '').indexOf(q) >= 0; });
        var pinned = all.filter(function (it) { return it.pinned; }), rest = all.filter(function (it) { return !it.pinned; });
        var pageItems = rest.slice((page - 1) * size, page * size);
        renderList({ total: rest.length, items: (page === 1 ? pinned : []).concat(pageItems) });
      });
    }
    var u = api + (api.indexOf('?') >= 0 ? '&' : '?') + 'page=' + page + '&size=' + size + (q ? '&q=' + encodeURIComponent(q) : '');
    return fetchJson(u).then(renderList);
  }

  /* ---------- 본문 ---------- */
  function renderDetail(it) {
    if (!it) return fail();
    box.style.display = 'none';
    if (tools) tools.style.display = 'none';
    if (pager) pager.style.display = 'none';
    detailBox = document.createElement('article');
    detailBox.className = 'post';
    var meta = '<span>' + esc(it.date || '') + '</span>' + (it.writer ? '<span>' + esc(it.writer) + '</span>' : '') + (it.views != null ? '<span>조회 ' + num(it.views) + '</span>' : '');
    var body = String(it.content || '');
    if (!/<[a-z][\s\S]*>/i.test(body)) body = '<p>' + esc(body).replace(/\n/g, '<br>') + '</p>';
    body = body.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/\son\w+="[^"]*"/gi, '');
    var files = (it.files || []).map(function (f) { return '<li><a href="' + esc(f.url) + '" target="_blank" rel="noopener">' + esc(f.name || f.url) + '</a></li>'; }).join('');
    detailBox.innerHTML = '<header class="post__head"><h3>' + esc(it.title) + '</h3><div class="post__meta">' + meta + '</div></header>'
      + '<div class="post__body">' + body + '</div>'
      + (it.answer ? '<div class="post__answer"><strong>답변</strong>' + (it.answered_at ? '<span class="post__meta">' + esc(it.answered_at) + '</span>' : '') + '<div>' + String(it.answer).replace(/<script[\s\S]*?<\/script>/gi, '') + '</div></div>' : '')
      + (files ? '<ul class="post__files">' + files + '</ul>' : '')
      + '<div class="form-actions" style="justify-content:flex-start;margin-top:24px"><a class="btn btn--outline btn--sm btn--square" href="' + link(page) + '">목록</a></div>';
    box.parentNode.insertBefore(detailBox, box);
  }
  function loadDetail() {
    if (isStatic) {
      return fetchJson(api).then(function (data) {
        var all = data.items || data || [];
        renderDetail(all.filter(function (it) { return String(it.id) === String(id); })[0]);
      });
    }
    var pw = params.get('pw') || '';
    return fetch(api + (api.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id) + (pw ? '&pw=' + encodeURIComponent(pw) : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        if (res.status === 403 && res.data && res.data.error === 'secret') return renderSecret(res.data, !!pw);
        if (res.status >= 400) throw new Error(res.data && res.data.error || 'HTTP ' + res.status);
        renderDetail(res.data.item || res.data);
      });
  }
  function renderSecret(d, wrong) {
    box.style.display = 'none';
    if (tools) tools.style.display = 'none';
    if (pager) pager.style.display = 'none';
    var it = d.item || {};
    var f = document.createElement('form');
    f.className = 'post';
    f.innerHTML = '<header class="post__head"><h3>🔒 ' + esc(it.title || '비밀글') + '</h3><div class="post__meta"><span>' + esc(it.date || '') + '</span><span>' + esc(it.writer || '') + '</span></div></header>'
      + '<div class="post__body"><p>' + esc(d.message || '비밀글입니다. 비밀번호를 입력해 주세요.') + '</p>'
      + (wrong ? '<p class="warn" style="margin-top:8px">비밀번호가 맞지 않습니다.</p>' : '')
      + '<div class="search-row" style="margin-top:14px;max-width:360px"><input class="search-input" type="password" name="pw" placeholder="비밀번호" required autocomplete="off"><button class="btn btn--navy btn--sm btn--square" type="submit">확인</button></div></div>'
      + '<div class="form-actions" style="justify-content:flex-start;padding:0 24px 24px"><a class="btn btn--outline btn--sm btn--square" href="' + link(page) + '">목록</a></div>';
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var sp = new URLSearchParams(location.search); sp.set('pw', f.elements.pw.value);
      location.search = '?' + sp.toString();
    });
    box.parentNode.insertBefore(f, box);
  }

  message('불러오는 중…');
  (id ? loadDetail() : loadList()).catch(function (e) { if (window.console) console.warn('board:', e); fail(); });
})();
