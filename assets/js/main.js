/* GOODPOST 홈페이지 공통 스크립트: 모바일 메뉴, 홈 조회 탭, 부피계산기, 해외운송비 합계, 지역표 검색, 운송사 선택 */
(function () {
  'use strict';

  /* 모바일 메뉴 토글 */
  var toggle = document.querySelector('.nav-toggle');
  var gnb = document.querySelector('.gnb');
  if (toggle && gnb) {
    toggle.addEventListener('click', function () {
      var open = gnb.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* 홈 히어로 조회 탭 */
  var tabs = document.querySelectorAll('.tool-card__tabs button');
  var panels = document.querySelectorAll('.tool-card__panel');
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabs.forEach(function (b) { b.classList.remove('is-active'); b.setAttribute('aria-selected', 'false'); });
      panels.forEach(function (p) { p.classList.remove('is-active'); });
      btn.classList.add('is-active');
      btn.setAttribute('aria-selected', 'true');
      var target = document.getElementById(btn.getAttribute('aria-controls'));
      if (target) target.classList.add('is-active');
    });
  });

  /* 부피계산기: 가로 × 세로 × 높이 ÷ 5,000 */
  var volForm = document.getElementById('volume-form');
  if (volForm) {
    var out = document.getElementById('volume-result');
    var calc = function () {
      var w = parseFloat(volForm.elements['w'].value) || 0;
      var d = parseFloat(volForm.elements['d'].value) || 0;
      var h = parseFloat(volForm.elements['h'].value) || 0;
      out.textContent = ((w * d * h) / 5000).toFixed(2);
    };
    volForm.addEventListener('submit', function (e) { e.preventDefault(); calc(); });
    volForm.addEventListener('input', calc);
    volForm.addEventListener('reset', function () { setTimeout(function () { out.textContent = '0.00'; }, 0); });
  }

  /* 해외운송비 결제 합계 */
  var payForm = document.getElementById('pay-form');
  if (payForm) {
    var total = document.getElementById('pay-total');
    var sum = function () {
      var t = 0;
      payForm.querySelectorAll('input[data-price]').forEach(function (input) {
        t += (parseInt(input.value, 10) || 0) * parseInt(input.getAttribute('data-price'), 10);
      });
      total.textContent = t.toLocaleString('ko-KR') + ' 원';
    };
    payForm.addEventListener('input', sum);
    sum();
  }

  /* 국제특송지역표 검색 */
  var zoneInput = document.getElementById('zone-search');
  if (zoneInput) {
    var rows = document.querySelectorAll('#zone-table tbody tr');
    var filter = function () {
      var q = zoneInput.value.trim().toLowerCase();
      rows.forEach(function (tr) {
        var name = tr.cells[0].textContent.toLowerCase();
        tr.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
      });
    };
    zoneInput.addEventListener('input', filter);
    var zoneForm = zoneInput.closest('form');
    if (zoneForm) zoneForm.addEventListener('submit', function (e) { e.preventDefault(); filter(); });
  }

  /* 화물추적 운송사 선택 */
  var carriers = document.querySelectorAll('.carriers button');
  carriers.forEach(function (btn) {
    btn.addEventListener('click', function () {
      carriers.forEach(function (b) { b.classList.remove('is-active'); b.setAttribute('aria-pressed', 'false'); });
      btn.classList.add('is-active');
      btn.setAttribute('aria-pressed', 'true');
      var hidden = document.getElementById('carrier');
      if (hidden) hidden.value = btn.textContent.trim();
    });
  });
})();
