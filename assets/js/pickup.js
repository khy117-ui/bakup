/* 온라인접수(픽업 예약) — ERP(erp/pickup.php)로 보내 저장하고, 담당자 · 고객에게 메일 · 카톡이 갑니다. */
(function () {
  'use strict';
  var form = document.getElementById('pickup-form');
  if (!form) return;
  var msg = document.getElementById('pk-msg');
  var ts = document.getElementById('pk-ts');
  if (ts) ts.value = String(Date.now());   // 페이지를 연 시각 — 너무 빨리 보내는 봇을 거릅니다

  // 픽업일자: 오늘 이전은 못 고르게
  var date = document.getElementById('date');
  if (date) {
    var t = new Date(); t.setMinutes(t.getMinutes() - t.getTimezoneOffset());
    date.min = t.toISOString().slice(0, 10);
  }

  function say(text, ok) {
    msg.textContent = text;
    msg.style.color = ok ? 'var(--navy)' : '#B42318';
    msg.style.fontWeight = '700';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var need = [['company', '회사명'], ['manager', '업무담당자'], ['tel', '연락처'], ['addr', '주소']];
    for (var i = 0; i < need.length; i++) {
      var el = form.elements[need[i][0]];
      if (!el || !el.value.trim()) { say(need[i][1] + '을(를) 입력해 주세요.', false); el && el.focus(); return; }
    }
    var em = form.elements['email'];
    if (em && em.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em.value.trim())) {
      say('이메일 주소를 확인해 주세요.', false); em.focus(); return;
    }
    if (!form.elements['agree'].checked) { say('개인정보 수집 · 이용에 동의해 주세요.', false); return; }

    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    say('신청을 보내는 중입니다…', true);
    fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (d) {
        if (d && d.ok) {
          form.reset();
          if (ts) ts.value = String(Date.now());
          say('픽업 예약 신청이 접수되었습니다. 접수번호 ' + d.req_no + ' — 담당자가 확인 후 연락드립니다.', true);
        } else {
          say((d && d.error) || '접수하지 못했습니다. 전화(02-6929-0666)로 문의해 주세요.', false);
        }
      })
      .catch(function () { say('접수하지 못했습니다. 잠시 후 다시 시도하거나 전화(02-6929-0666)로 문의해 주세요.', false); })
      .then(function () { btn.disabled = false; });
  });
})();
