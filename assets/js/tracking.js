/* 화물추적 — ERP 공개 조회(erp/track.php)에 물어보고 결과를 그립니다.
   · 운송사 버튼(자동/DHL/FedEx/EMS/UPS) + 번호 → 조회
   · 주소에 ?bl=번호 가 있으면 (메인 화면 배송조회) 바로 조회 */
(function () {
  'use strict';
  var form = document.getElementById('track-form');
  var input = document.getElementById('bl');
  var out = document.getElementById('track-result');
  if (!form || !input || !out) return;

  var root = document.body.getAttribute('data-root') || '';
  var API = root + 'erp/track.php';
  var STEPS = ['픽업의뢰', '입고', '항공/해상운송', '통관', '내륙운송', '제품인도'];
  var WEEK = ['일', '월', '화', '수', '목', '금', '토'];
  var region = null;
  try { region = new Intl.DisplayNames(['ko'], { type: 'region' }); } catch (e) { region = null; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function country(code) {
    if (!code) return '';
    try { return region ? region.of(code) : code; } catch (e) { return code; }
  }
  function carrierValue() {
    var b = form.querySelector('.carriers button.is-active');
    return b ? (b.getAttribute('data-carrier') || 'auto') : 'auto';
  }

  function stepsHtml(stage, delivered) {
    var h = '<ol class="track">';
    STEPS.forEach(function (name, i) {
      var n = i + 1;
      var cls = n < stage || (delivered && n === stage) ? 'done' : (n === stage ? 'current' : '');
      var state = cls === 'done' ? '완료' : (cls === 'current' ? '진행중' : '대기');
      h += '<li class="' + cls + '"><span class="dot">' + (cls === 'done' ? '✓' : n) + '</span>' +
           '<span class="name">' + name + '</span><span class="state">' + state + '</span></li>';
    });
    return h + '</ol>';
  }

  function eventsHtml(events) {
    if (!events.length) return '';
    var h = '<div class="tk-events"><div class="tk-events__head">추적 세부사항 <span>' + events.length + '건 · 최신순</span></div>';
    var prev = '';
    events.forEach(function (e, i) {
      var day = e.at.slice(0, 10);
      if (day !== prev) {
        prev = day;
        var d = new Date(day + 'T00:00:00');
        h += '<div class="tk-day">' + esc(day) + (isNaN(d) ? '' : ' (' + WEEK[d.getDay()] + ')') + '</div>';
      }
      h += '<div class="tk-row' + (i === 0 ? ' is-latest' : '') + '">' +
           '<span class="tk-time en">' + esc(e.at.slice(11, 16)) + '</span>' +
           '<span class="tk-status">' + esc(e.status) +
             (e.description ? '<small>' + esc(e.description) + '</small>' : '') + '</span>' +
           '<span class="tk-loc">' + esc(e.location || '-') + '</span></div>';
    });
    return h + '</div>';
  }

  function render(data) {
    var html = '';
    var r = data.route;
    data.results.forEach(function (it) {
      var latest = it.events[0];
      html += '<div class="card tk-card">';
      html += '<div class="tk-head"><span class="tk-carrier">' + esc(it.carrier) + '</span>' +
              '<strong class="en tk-no">' + esc(it.no) + '</strong>' +
              (it.delivered ? '<span class="tk-badge tk-badge--ok">배송완료</span>'
                : (latest ? '<span class="tk-badge">배송중</span>' : '')) + '</div>';
      if (r) {
        html += '<p class="note tk-route">출발지 ' + esc(country(r.from)) + ' → 도착지 ' +
                esc(country(r.to) + (r.to_city ? ' · ' + r.to_city : '')) +
                (r.ship_date ? ' · 발송일 ' + esc(r.ship_date) : '') +
                (r.pcs ? ' · ' + esc(r.pcs) + '개' : '') + '</p>';
      }
      if (latest) {
        html += '<p class="tk-latest"><b>' + esc(latest.status) + '</b>' +
                (latest.location ? ' · ' + esc(latest.location) : '') +
                ' <span class="en">' + esc(latest.at.slice(0, 16)) + '</span></p>';
      }
      html += stepsHtml(it.stage || 1, it.delivered);
      if (it.message) html += '<p class="tk-msg">' + esc(it.message) + '</p>';
      html += eventsHtml(it.events);
      if (it.site_url) {
        html += '<p class="tk-site"><a href="' + esc(it.site_url) + '" target="_blank" rel="noopener">' +
                esc(it.carrier) + ' 사이트에서 자세히 보기 →</a></p>';
      }
      html += '</div>';
    });
    out.innerHTML = html;
  }

  function lookup(no) {
    no = String(no || '').replace(/[\s-]+/g, '').toUpperCase();
    if (!/^[A-Z0-9]{6,40}$/.test(no)) {
      out.innerHTML = '<div class="card tk-card"><p class="tk-msg">운송장 번호를 확인해 주세요. (영문 · 숫자 6~40자)</p></div>';
      return;
    }
    out.innerHTML = '<div class="card tk-card"><p class="note">조회 중입니다…</p></div>';
    var url = API + '?no=' + encodeURIComponent(no) + '&carrier=' + encodeURIComponent(carrierValue());
    fetch(url, { cache: 'no-store', referrerPolicy: 'no-referrer' })
      .then(function (res) { return res.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data || !data.ok) {
          out.innerHTML = '<div class="card tk-card"><p class="tk-msg">' +
            esc((data && data.error) || '조회하지 못했습니다. 잠시 후 다시 시도해 주세요.') + '</p></div>';
          return;
        }
        render(data);
      })
      .catch(function () {
        out.innerHTML = '<div class="card tk-card"><p class="tk-msg">조회하지 못했습니다. 잠시 후 다시 시도해 주세요.</p></div>';
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    lookup(input.value);
    try { history.replaceState(null, '', '?bl=' + encodeURIComponent(input.value.trim())); } catch (err) { /* 무시 */ }
  });

  var bl = new URLSearchParams(location.search).get('bl');
  if (bl) {
    input.value = bl;
    lookup(bl);
  }
})();
