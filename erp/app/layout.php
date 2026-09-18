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
            ['rate_table', '특송 기본가격표', true],
            ['company_terms', '업체별 할인율', true],
        ]],
        ['영업관리', [
            ['quotations', '견적서 관리', true],
            ['statements', '거래명세서', true],
            ['rate_calculator', '단가계산기', true],
        ]],
        ['물류관리', [
            ['shipments', '매출전표', true],
            ['awb_list', 'AWB 관리', true],
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
            ['boards', '게시판 관리', true],
            ['activity_log', '작업로그', true],
            ['settings', '환경설정', true],
            ['storage_settings', '저장소 설정', true],
            ['backup', '백업 / 복구', true],
            ['migration_import', '옛 자료 가져오기', true],
            ['migration', '이관 검수', true],
        ]],
    ];
    // 승인할 수 있는 사람에게만 대기 건수를 보여줍니다
    $pending = (function_exists('delete_request_pending') && can('sys.delete.approve')) ? delete_request_pending() : 0;
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
              if ($key === 'delete_requests' && $pending > 0): ?><span class="badge b-warn" style="margin-left:auto;height:18px"><?= $pending ?></span><?php endif; ?></a>
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
</script>
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
