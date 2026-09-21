/* 요금표 로더: ERP 단가표(erp/rates.php) + assets/data/rates.xlsx 를 읽어 [data-sheet] 컨테이너에 표를 그립니다.
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
  /* ERP 단가표와 연결된 칸은 ERP(erp/rates.php) 숫자로, 나머지 칸은 엑셀(rates.xlsx)로 그립니다.
     ERP 에서 가격표를 고치면 여기 새로고침만으로 바뀝니다. */
  function load() {
    var erp = fetch(root + 'erp/rates.php', { cache: 'no-store' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .catch(function () { return null; });
    var xls = (typeof XLSX === 'undefined') ? Promise.resolve(null)
      : fetch(FILE, { cache: 'no-store' })
          .then(function (res) { if (!res.ok) throw new Error(res.status); return res.arrayBuffer(); })
          .then(function (buf) { return XLSX.read(buf, { type: 'array' }); })
          .catch(function () { return null; });
    Promise.all([erp, xls]).then(function (r) {
      var sheets = (r[0] && r[0].ok && r[0].sheets) || {};
      var wb = r[1];
      var rest = [];
      containers.forEach(function (el) {
        var name = el.getAttribute('data-sheet');
        if (sheets[name]) { render(el, sheets[name]); } else { rest.push(el); }
      });
      if (rest.length) {
        if (wb) { renderWorkbook(wb, rest); }
        else { rest.forEach(function (el) { el.innerHTML = '<p class="note">요금표를 불러오지 못했습니다. 잠시 후 새로고침해 주세요.</p>'; }); }
      }
      var stamp = document.getElementById('rates-updated');
      var tables = (r[0] && r[0].tables) || {};
      var dates = Object.keys(tables).map(function (k) { return tables[k].from; }).sort();
      if (stamp && dates.length) stamp.textContent = '요금표 기준일: ' + dates[dates.length - 1];
    });
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
