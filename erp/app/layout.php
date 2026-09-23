<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/** @var array $ADMIN */
function layout_head(string $title, string $active): void
{
    global $CFG, $ADMIN;
    $menu = [
        ['기준정보', [
            ['companies', '거래처 관리', true],
            ['company_contacts', '업체 담당자', true],
            ['carriers', '운송사 관리', true],
            ['destinations', '도착지 관리', true],
            ['rate_table', '특송 기본가격표', true],
            ['company_terms', '업체별 할인율', true],
        ]],
        ['영업관리', [
            ['quotations', '견적서 관리', true],
            ['statements', '거래명세서', true],
            ['rate_calculator', '단가계산기', true],
        ]],
        ['물류관리', [
            ['web_pickups', '온라인 접수', true],
            ['shipments', '매출전표', true],
            ['awb_list', 'AWB 관리', true],
            ['hawb_list', 'HAWB 발행', true],
            ['hawb_batches', '수입 서류', true],
            ['tracking', '화물추적', true],
            ['documents', '문서보관함', true],
        ]],
        ['회계관리', [
            ['sales_stats', '매출통계', true],
            ['purchases', '매입관리', true],
            ['profit', '손익관리', true],
            ['billing', '청구관리', true],
            ['tax_invoices', '전자세금계산서', true],
        ]],
        ['입출금관리', [
            ['cash_dashboard', '입출금 현황', true],
            ['cash_in', '입금 등록', true],
            ['cash_out', '출금 등록', true],
            ['cash_list', '입출금 내역', true],
            ['receipts', '입금확인서', true],
            ['receivables', '미수금 관리', true],
            ['ledger', '거래처별 원장', true],
            ['opening_balances', '기초잔액 관리', true],
            ['bank_import', '은행내역 가져오기', true],
            ['accounts', '계좌 관리', true],
            ['cash_stats', '입출금 통계', true],
        ]],
        ['시스템', [
            ['business_entity', '사업자 관리', true],
            ['permissions', '관리자 / 권한', true],
            ['delete_requests', '삭제 요청 · 승인', true],
            ['boards', '홈페이지 게시판', true],
            ['activity_log', '작업로그', true],
            ['error_log', '오류 기록', true],
            ['settings', '환경설정', true],
            ['storage_settings', '저장소 설정', true],
            ['backup', '백업 / 복구', true],
            ['migration_import', '옛 자료 가져오기', true],
            ['migration', '이관 검수', true],
        ]],
    ];
    // 승인할 수 있는 사람에게만 대기 건수를 보여줍니다
    $pending = (function_exists('delete_request_pending') && can('sys.delete.approve')) ? delete_request_pending() : 0;
    // 홈페이지 온라인 접수 중 아직 확인 안 한 것
    $newPickups = 0;
    if (function_exists('route_can_view') && route_can_view('web_pickups')) {
        try {
            $newPickups = (int)db()->query("SELECT COUNT(*) FROM web_pickups WHERE status = 'NEW'")->fetchColumn();
        } catch (PDOException $e) {
            $newPickups = 0;
        }
    }
    // 홈페이지 Q&A 중 답변 안 한 것
    $qnaWait = 0;
    if (function_exists('route_can_view') && route_can_view('boards')) {
        try {
            $qnaWait = (int)db()->query("SELECT COUNT(*) FROM board_post WHERE board = 'qna' AND answer IS NULL")->fetchColumn();
        } catch (PDOException $e) {
            $qnaWait = 0;
        }
    }
    ?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($CFG['app_name']) ?></title>
<link rel="stylesheet" href="<?= h(asset_v('assets/app.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="scrim" onclick="document.body.classList.remove('nav-open')"></div>
  <aside class="side" id="sidenav">
    <div class="logo"><img src="assets/logo.png" alt="GOODPOST"></div>
    <nav>
      <a class="item<?= $active === 'dashboard' ? ' on' : '' ?>" href="?p=dashboard"
         style="padding-left:16px">대시보드</a>
      <a class="item<?= $active === 'search' ? ' on' : '' ?>" href="?p=search"
         style="padding-left:16px">통합검색</a>
      <?php foreach ($menu as [$grp, $items]):
        // 볼 권한이 없는 화면은 메뉴에서 뺍니다. 한 개도 안 남으면 묶음 제목도 숨깁니다
        $items = array_values(array_filter($items, fn($it) => !function_exists('route_can_view') || route_can_view($it[0])));
        if (!$items) { continue; } ?>
        <div class="grp"><?= h($grp) ?></div>
        <?php foreach ($items as [$key, $label, $live]): ?>
          <?php if ($live): ?>
            <a class="item<?= $active === $key ? ' on' : '' ?>" href="?p=<?= h($key) ?>"><?= h($label) ?><?php
              if ($key === 'delete_requests' && $pending > 0): ?><span class="badge b-warn" style="margin-left:auto;height:18px"><?= $pending ?></span><?php endif;
              if ($key === 'web_pickups'): ?><span class="badge b-warn" id="live-badge-pickups" style="margin-left:auto;height:18px<?= $newPickups > 0 ? '' : ';display:none' ?>"><?= $newPickups ?></span><?php endif;
              if ($key === 'boards'): ?><span class="badge b-warn" id="live-badge-qna" title="답변 대기 Q&amp;A" style="margin-left:auto;height:18px<?= $qnaWait > 0 ? '' : ';display:none' ?>"><?= $qnaWait ?></span><?php endif; ?></a>
          <?php else: ?>
            <span class="item"><?= h($label) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <div class="av"><?= h(mb_substr((string)($ADMIN['name'] ?? '?'), 0, 1)) ?></div>
      <a href="?p=my_account" title="내 정보 · 비밀번호 바꾸기" style="text-decoration:none">
        <b><?= h($ADMIN['name'] ?? '') ?></b>
        <small><?= h($ADMIN['role_code'] ?? '') ?> · 내 정보</small>
      </a>
      <a class="btn sm" style="margin-left:auto" href="?p=logout">나가기</a>
    </div>
  </aside>
  <div class="main">
    <div class="top">
      <button type="button" class="menu-btn" aria-label="메뉴 열기" aria-controls="sidenav"
              onclick="document.body.classList.toggle('nav-open')">&#9776;</button>
      <div class="ent"><?= h(entity_label()) ?></div>
      <form method="get" class="entform">
        <?php foreach ($_GET as $qk => $qv): if ($qk === 'ent' || !is_string($qv)) continue; ?>
          <input type="hidden" name="<?= h($qk) ?>" value="<?= h($qv) ?>">
        <?php endforeach; ?>
        <label for="entsel" style="font-size:11.5px;color:var(--ink2)">사업자</label>
        <select id="entsel" name="ent" onchange="this.form.submit()">
          <option value="all"<?= entity_filter() === null ? ' selected' : '' ?>>전체</option>
          <?php foreach (entity_list() as $e): ?>
            <option value="<?= (int)$e['id'] ?>"<?= entity_filter() === (int)$e['id'] ? ' selected' : '' ?>>
              <?= h($e['name_ko']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="body">
<?php
    if ($m = flash()) {
        echo '<div class="msg ok">' . h($m) . '</div>';
    }
}

function layout_foot(): void
{
    ?>    </div>
  </div>
</div>
<script>
window.GP_CSRF = '<?= h(csrf_token()) ?>';
// 넓은 표는 감싸서 표만 좌우로 밀리게 한다 (휴대폰에서 화면 전체가 옆으로 밀리지 않게)
document.querySelectorAll('.body table').forEach(function (t) {
  var p = t.parentNode;
  if (p.classList && p.classList.contains('tscroll')) return;
  var w = document.createElement('div');
  w.className = 'tscroll';
  p.insertBefore(w, t);
  w.appendChild(t);
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') document.body.classList.remove('nav-open');
});
// 삭제 승인 대기 알림줄 — 화면 위 가운데. 여기서 바로 승인 · 반려합니다
(function () {
  var bar = null;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function post(act, id, note) {
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '?p=delete_requests';
    var add = function (n, v) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i);
    };
    add('_csrf', window.GP_CSRF || '');
    add('act', act); add('id', id);
    if (note) { add('note', note); }
    document.body.appendChild(f);
    f.submit();
  }

  window.gpDelReq = function (d) {
    if (!d || !d.delreq) { return; }
    var n = d.delreq.wait || 0;
    if (n === 0) { if (bar) { bar.remove(); bar = null; } return; }
    var it = (d.delreq.items || [])[0] || {};
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'gp-delreq';
      bar.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:9998;'
        + 'background:#8A1C1C;color:#fff;padding:10px 16px;border-radius:0 0 10px 10px;'
        + 'box-shadow:0 6px 20px rgba(0,0,0,.25);font-size:13px;display:flex;gap:10px;align-items:center;'
        + 'max-width:min(860px,94vw)';
      document.body.appendChild(bar);
    }
    bar.innerHTML =
      '<b style="white-space:nowrap">🗑 삭제 승인 대기 ' + n + '건</b>'
      + '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
      + esc(it.title || '') + ' <span style="opacity:.8">' + esc(it.sub || '') + '</span></span>'
      + '<button type="button" id="dr-ok" style="border:0;border-radius:6px;padding:5px 12px;font-weight:700;'
      + 'background:#fff;color:#8A1C1C;cursor:pointer">승인</button>'
      + '<button type="button" id="dr-no" style="border:1px solid rgba(255,255,255,.6);border-radius:6px;'
      + 'padding:5px 12px;background:transparent;color:#fff;cursor:pointer">반려</button>'
      + '<a href="?p=delete_requests" style="color:#fff;opacity:.85;font-size:12px;white-space:nowrap">전체 보기</a>';
    document.getElementById('dr-ok').addEventListener('click', function () {
      if (confirm('"' + (it.title || '') + '" 을(를) 승인하면 바로 삭제 · 취소됩니다. 진행할까요?')) {
        post('approve', it.id);
      }
    });
    document.getElementById('dr-no').addEventListener('click', function () {
      var why = prompt('반려 사유를 적어 주세요. 요청한 사람이 봅니다.');
      if (why === null) { return; }
      if (why.trim().length < 2) { alert('반려 사유를 두 글자 이상 적어 주세요.'); return; }
      post('reject', it.id, why.trim());
    });
  };
})();

// 실시간 알림 — 30초마다 새 온라인 접수 · Q&A 를 확인해 메뉴 숫자를 고치고, 새로 들어오면 오른쪽 아래에 알림
(function () {
  var KEY = 'gp_live_seen';
  var seen = {};
  try { seen = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { seen = {}; }
  var box = null;
  function toast(msg, url) {
    if (!box) {
      box = document.createElement('div');
      box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;max-width:340px';
      document.body.appendChild(box);
    }
    var a = document.createElement('a');
    a.href = url;
    a.textContent = msg;
    a.style.cssText = 'display:block;background:#0B4F6C;color:#fff;padding:12px 14px;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.18);font-size:13px;font-weight:600;text-decoration:none;line-height:1.5';
    box.appendChild(a);
    setTimeout(function () { a.remove(); }, 15000);
    try {   // 짧은 알림음 (브라우저가 막으면 조용히 넘어감)
      var C = window.AudioContext || window.webkitAudioContext, ctx = new C(), o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = 880; g.gain.value = 0.05; o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.18);
    } catch (e) {}
    try { if (window.Notification && Notification.permission === 'granted') { var n = new Notification('GOODPOST ERP', { body: msg }); n.onclick = function () { window.focus(); location.href = url; }; } } catch (e) {}
  }
  function badge(id, n) {
    var b = document.getElementById(id);
    if (b) { b.textContent = n; b.style.display = n > 0 ? '' : 'none'; }
  }
  function tick() {
    fetch('?p=live_feed', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var first = !seen.init;
        if (d.pickups) {
          badge('live-badge-pickups', d.pickups['new']);
          if (!first && d.pickups.max_id > (seen.p || 0)) {
            d.pickups.items.filter(function (i) { return i.id > (seen.p || 0); }).forEach(function (i) {
              toast('🚚 새 온라인 접수 ' + i.no + ' · ' + i.title, i.url);
            });
          }
          seen.p = d.pickups.max_id;
        }
        if (d.qna) {
          badge('live-badge-qna', d.qna.wait);
          if (!first && d.qna.max_id > (seen.q || 0)) {
            d.qna.items.filter(function (i) { return i.id > (seen.q || 0); }).forEach(function (i) {
              toast('💬 새 Q&A 문의 · ' + i.title, i.url);
            });
          }
          seen.q = d.qna.max_id;
        }
        if (window.gpDelReq) { gpDelReq(d); }
        if (!first && d.delreq && d.delreq.max_id > (seen.d || 0) && (seen.d || 0) > 0) {
          toast('🗑 새 삭제 승인 요청 · ' + ((d.delreq.items || [])[0] || {}).title, '?p=delete_requests');
        }
        if (d.delreq) { seen.d = d.delreq.max_id; }
        seen.init = 1;
        try { localStorage.setItem(KEY, JSON.stringify(seen)); } catch (e) {}
        document.dispatchEvent(new CustomEvent('gp:live', { detail: d }));   // 대시보드가 목록을 다시 그림
      })
      .catch(function () {});
  }
  window.gpLiveTick = tick;
  setTimeout(tick, 800);
  setInterval(function () { if (!document.hidden) tick(); }, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
// 자동 화물추적 — 이 브라우저에서 5분에 한 번 서버에 신호 (서버도 전체 5분에 한 번만 일함)
(function () {
  var k = 'gp_track_tick', now = Date.now(), last = 0;
  try { last = +localStorage.getItem(k) || 0; } catch (e) {}
  if (now - last < 300000) return;
  try { localStorage.setItem(k, String(now)); } catch (e) {}
  setTimeout(function () {
    fetch('?p=track_tick', { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
  }, 1500);
})();
</script>
<script src="<?= h(asset_v('assets/combo.js')) ?>"></script>
</body>
</html>
<?php
}

function entity_name(): string
{
    static $n = null;
    if ($n !== null) {
        return $n;
    }
    $st = db()->prepare('SELECT name_ko FROM business_entities WHERE id = ?');
    $st->execute([entity_id()]);
    return $n = (string)$st->fetchColumn();
}
