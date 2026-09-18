/* 요금표 로더: assets/data/rates.xlsx 를 읽어 [data-sheet] 컨테이너에 표를 그립니다.
   - 엑셀 파일만 같은 이름으로 덮어써 올리면 페이지 새로고침 시 바로 반영됩니다.
   - 파싱은 SheetJS(xlsx.full.min.js, CDN)로 처리합니다. */
(function () {
  'use strict';
  var containers = document.querySelectorAll('[data-sheet]');
  if (!containers.length) return;
  var root = document.body.getAttribute('data-root') || '';
  var FILE = root + 'assets/data/rates.xlsx';

  function fmt(v) {
    if (v === undefined || v === null || v === '') return '<span class="placeholder">-</span>';
    if (typeof v === 'number') return Number.isInteger(v) ? v.toLocaleString('ko-KR') : String(v);
    return String(v);
  }
  function render(el, rows) {
    if (!rows || !rows.length) { el.innerHTML = '<p class="note">표시할 데이터가 없습니다.</p>'; return; }
    var head = rows[0];
    var body = rows.slice(1).filter(function (r) { return r && r.some(function (c) { return c !== undefined && c !== null && c !== ''; }); });
    var center = el.getAttribute('data-align') === 'center';
    var id = el.getAttribute('data-table-id');
    var html = '<div class="table-scroll"><table class="table' + (center ? ' table--center' : '') + '"' + (id ? ' id="' + id + '"' : '') + '><thead><tr>';
    head.forEach(function (h) { html += '<th scope="col">' + fmt(h) + '</th>'; });
    html += '</tr></thead><tbody>';
    body.forEach(function (r) {
      html += '<tr>';
      for (var i = 0; i < head.length; i++) html += '<td>' + fmt(r[i]) + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
    el.dispatchEvent(new CustomEvent('rates:rendered', { bubbles: true }));
  }
  function renderWorkbook(wb, scope) {
    (scope || containers).forEach(function (el) {
      var name = el.getAttribute('data-sheet');
      var ws = wb.Sheets[name];
      if (!ws) { el.innerHTML = '<p class="note">엑셀 파일에 "' + name + '" 시트가 없습니다.</p>'; return; }
      render(el, XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' }));
    });
    var stamp = document.getElementById('rates-updated');
    if (stamp && wb.Props && wb.Props.ModifiedDate) stamp.textContent = '요금표 기준일: ' + new Date(wb.Props.ModifiedDate).toLocaleDateString('ko-KR');
  }
  function fail(msg) {
    containers.forEach(function (el) { el.innerHTML = '<p class="note">' + msg + '</p>'; });
  }
  function load() {
    if (typeof XLSX === 'undefined') { fail('요금표 라이브러리를 불러오지 못했습니다. 네트워크 연결을 확인해 주세요.'); return; }
    fetch(FILE, { cache: 'no-store' })
      .then(function (res) { if (!res.ok) throw new Error(res.status); return res.arrayBuffer(); })
      .then(function (buf) { renderWorkbook(XLSX.read(buf, { type: 'array' })); })
      .catch(function () { fail('요금표 파일(assets/data/rates.xlsx)을 불러오지 못했습니다. 파일이 올바른 위치에 있는지 확인해 주세요.'); });
  }

  /* 관리자 미리보기: 로컬 엑셀 파일을 선택하면 업로드 전에 표를 확인 */
  var picker = document.getElementById('rates-file');
  if (picker) {
    picker.addEventListener('change', function () {
      var f = picker.files[0]; if (!f) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        var wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
        var status = document.getElementById('rates-status');
        if (status) status.textContent = f.name + ' 미리보기 (시트: ' + wb.SheetNames.join(', ') + ')';
        renderWorkbook(wb);
      };
      reader.readAsArrayBuffer(f);
    });
  }
  load();
})();
