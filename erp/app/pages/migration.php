<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 이관 검수 — 옛 시스템(ASP + MSSQL) 자료가 제대로 넘어왔는지 보는 화면.
 *
 * 이관 원칙은 **아무것도 버리지 않는다** 입니다. 그래서 값을 지우는 대신
 *   · 원본은 *_staging 에 그대로 두고
 *   · 뜻이 확정되지 않은 컬럼은 *_legacy_extra 에 담고
 *   · 변환에 실패한 값은 migration_errors 에 원본 그대로 남깁니다.
 * 이 화면은 그 세 곳에 무엇이 남아 있는지, 즉 **아직 사람이 판단해야 할 것**을 모아 보여줍니다.
 *
 * 이 화면은 읽기만 합니다. 고치는 일은 SQL 로 합니다 — 한 번에 수천 행이 바뀌는 작업을
 * 버튼 한 번에 걸어 두면 잘못 눌렀을 때 되돌릴 방법이 마땅치 않습니다.
 */

/** 표가 아직 없을 수도 있으니 실패해도 화면은 떠야 합니다 */
function mig_one(string $sql, array $p = [])
{
    try {
        $st = db()->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
}
function mig_all(string $sql, array $p = []): ?array
{
    try {
        $st = db()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return null;
    }
}

// ---------------------------------------------------------------- 진행 상황
$stageComp = mig_one('SELECT COUNT(*) FROM companies_staging');
$liveComp  = mig_one('SELECT COUNT(*) FROM companies');
$stageShip = mig_one('SELECT COUNT(*) FROM shipments_staging');
$liveShip  = mig_one('SELECT COUNT(*) FROM shipments');

// 이관으로 들어온 행만 (직접 입력한 건 legacy_idx 가 NULL)
$fromLegacyComp = mig_one('SELECT COUNT(*) FROM companies WHERE legacy_idx IS NOT NULL');
$fromLegacyShip = mig_one('SELECT COUNT(*) FROM shipments WHERE legacy_idx IS NOT NULL');

// ---------------------------------------------------------------- 손봐야 할 것
// 코드가 비어 있어 임시코드를 붙인 거래처
$placeholder = mig_all(
    "SELECT id, company_code, name_ko, business_number, phone, legacy_idx
       FROM companies
      WHERE company_code LIKE 'LEGACY-%'
      ORDER BY name_ko LIMIT 200");
// 같은 코드가 둘 이상이어서 -D 를 붙인 거래처
$dupCode = mig_all(
    "SELECT id, company_code, name_ko, business_number, legacy_idx
       FROM companies
      WHERE company_code REGEXP '-D[0-9]+\$'
      ORDER BY company_code LIMIT 200");

// DEL 해석 — 아직 결정하지 않은 항목
$delDist = mig_all(
    'SELECT COALESCE(legacy_del, \'(NULL)\') AS v, COUNT(*) AS c
       FROM companies_legacy_extra GROUP BY legacy_del ORDER BY c DESC');
$delHidden = mig_one("SELECT COUNT(*) FROM companies WHERE deleted_at IS NOT NULL");

// 변환 실패 값
$errGroup = mig_all(
    'SELECT source_table, column_name, reason, COUNT(*) AS c
       FROM migration_errors
      GROUP BY source_table, column_name, reason
      ORDER BY c DESC LIMIT 50');
$errTotal = mig_one('SELECT COUNT(*) FROM migration_errors');

$errTbl = query('err');
$errRows = null;
if ($errTbl !== '') {
    $errRows = mig_all(
        'SELECT source_table, source_idx, column_name, raw_value, reason
           FROM migration_errors WHERE column_name = ?
          ORDER BY id LIMIT 200', [$errTbl]);
}

// 전표 보정 내역
$flagGroup = mig_all(
    'SELECT migration_flag AS f, COUNT(*) AS c FROM shipments
      WHERE migration_flag IS NOT NULL GROUP BY migration_flag ORDER BY c DESC');
$flagRows = null;
$flag = query('flag');
if ($flag !== '') {
    $flagRows = mig_all(
        'SELECT s.id, s.awb_no, s.voucher_date, s.migration_flag, s.legacy_idx, c.name_ko
           FROM shipments s LEFT JOIN companies c ON c.id = s.company_id
          WHERE s.migration_flag = ? ORDER BY s.id LIMIT 200', [$flag]);
}

// ---------------------------------------------------------------- 아직 뜻을 모르는 컬럼
$extraDefs = [
    'companies_legacy_extra' => ['거래처', [
        'eventsd' => '의미 불명', 'bill' => '청구 방식 추정',
        'publication' => '계산서 발행 방식 추정', 'saledate' => 'IS_SALES 와 이름 충돌',
        'c_fuel' => '업체별 유류할증률 1', 'c_fuel2' => '유류할증률 2',
        'c_fuel3' => '유류할증률 3', 'legacy_del' => 'DEL 원본 — 해석 대기',
        'legacy_category' => 'CATEGORY 원본', 'legacy_business' => 'BUSINESS 원본',
    ]],
    'shipments_legacy_extra' => ['매출전표', [
        'colmoney' => '착불금액 추정', 'tsnum' => '미확정', 'tsweight' => '미확정',
        'tsweight2' => '미확정', 'division' => '미확정', 'gubun' => '미확정',
        'collect_yn' => '착불 여부 추정', 'business' => 'CUSTOMERS.BUSINESS 와 같은 축',
        'factory_a' => '미확정', 'transit_a' => '미확정',
        'licence_yn' => 'LICENCE 원본', 'taxes_yn' => 'TAXES 원본',
        'bill_yn' => 'BILL 원본', 'deposit_yn' => 'DEPOSIT 원본', 'uptuser' => '최종 수정자',
    ]],
];
$extraStats = [];
foreach ($extraDefs as $tbl => [$lab, $cols]) {
    $sel = [];
    foreach (array_keys($cols) as $c) {
        // 컬럼명은 위 배열에 박아 둔 값만 씁니다 — 바깥에서 들어오지 않습니다
        $sel[] = "SUM(CASE WHEN `$c` IS NOT NULL AND `$c` <> '' THEN 1 ELSE 0 END) AS `$c`";
    }
    $row = mig_all('SELECT ' . implode(', ', $sel) . " FROM `$tbl`");
    $extraStats[$tbl] = [$lab, $cols, $row === null ? null : ($row[0] ?? [])];
}

// ---------------------------------------------------------------- 변경 이력 (되돌릴 수 있는지)
$histComp = mig_one('SELECT COUNT(*) FROM companies_history');
$histShip = mig_one('SELECT COUNT(*) FROM shipments_history');

$ready = $stageComp !== null;

layout_head('이관 검수', 'migration');
?>
<div class="head">
  <h1>이관 검수</h1>
  <div class="crumb">시스템 &gt; 이관 검수</div>
</div>

<?php if (!$ready): ?>
<div class="msg" style="background:var(--warn-bg);color:var(--warn-fg)">
  <b>이관용 표가 아직 없습니다.</b> <code>13a_companies_guard.sql</code> ·
  <code>14a_shipments_guard.sql</code> 을 실행하면 이 화면이 채워집니다.
  지금 보이는 숫자는 새로 입력한 자료만입니다.
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">거래처 · 원본 → 현재</div>
    <div class="val tnum"><?= $stageComp === null ? '-' : money($stageComp) ?>
      <span style="font-size:14px;color:var(--ink3)">→</span>
      <?= $fromLegacyComp === null ? '-' : money($fromLegacyComp) ?></div>
    <div class="sub"><?php if ($stageComp !== null && $fromLegacyComp !== null): ?>
      <?= (int)$stageComp === (int)$fromLegacyComp
           ? '건수 일치'
           : '<b style="color:var(--err-fg)">' . money(abs((int)$stageComp - (int)$fromLegacyComp))
             . '건 차이 — 확인 필요</b>' ?>
      <?php else: ?>대조할 자료 없음<?php endif; ?></div></div>
  <div class="kpi"><div class="lab">매출전표 · 원본 → 현재</div>
    <div class="val tnum"><?= $stageShip === null ? '-' : money($stageShip) ?>
      <span style="font-size:14px;color:var(--ink3)">→</span>
      <?= $fromLegacyShip === null ? '-' : money($fromLegacyShip) ?></div>
    <div class="sub"><?php if ($stageShip !== null && $fromLegacyShip !== null): ?>
      <?= (int)$stageShip === (int)$fromLegacyShip
           ? '건수 일치'
           : '<b style="color:var(--err-fg)">' . money(abs((int)$stageShip - (int)$fromLegacyShip))
             . '건 차이 — 확인 필요</b>' ?>
      <?php else: ?>대조할 자료 없음<?php endif; ?></div></div>
  <div class="kpi"><div class="lab">변환 실패 값</div>
    <div class="val tnum" style="color:<?= (int)$errTotal>0?'var(--warn-fg)':'var(--ink)' ?>">
      <?= $errTotal === null ? '-' : money($errTotal) ?></div>
    <div class="sub">버리지 않고 원본 그대로 보관 중</div></div>
  <div class="kpi"><div class="lab">되돌릴 수 있는 변경</div>
    <div class="val tnum"><?= money((int)$histComp + (int)$histShip) ?></div>
    <div class="sub">거래처 <?= money((int)$histComp) ?> · 전표 <?= money((int)$histShip) ?></div></div>
</div>

<div class="card">
  <div class="ch">건수 대조
    <span style="font-weight:400;color:var(--ink3)">원본과 현재가 맞는지</span>
  </div>
  <table>
    <thead><tr><th style="width:180px">표</th>
      <th class="r" style="width:130px">원본 (staging)</th>
      <th class="r" style="width:130px">이관된 행</th>
      <th class="r" style="width:130px">현재 전체</th>
      <th>차이의 뜻</th></tr></thead>
    <tbody>
      <tr>
        <td style="font-weight:600">거래처</td>
        <td class="r tnum"><?= $stageComp === null ? '-' : money($stageComp) ?></td>
        <td class="r tnum"><?= $fromLegacyComp === null ? '-' : money($fromLegacyComp) ?></td>
        <td class="r tnum"><?= $liveComp === null ? '-' : money($liveComp) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <b>원본 = 이관된 행</b> 이어야 합니다. 현재 전체가 더 많으면 새로 입력한 거래처입니다.
        </td>
      </tr>
      <tr>
        <td style="font-weight:600">매출전표</td>
        <td class="r tnum"><?= $stageShip === null ? '-' : money($stageShip) ?></td>
        <td class="r tnum"><?= $fromLegacyShip === null ? '-' : money($fromLegacyShip) ?></td>
        <td class="r tnum"><?= $liveShip === null ? '-' : money($liveShip) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          전표는 AWB 가 겹치면 넣지 못합니다. 차이가 나면 아래 <b>변환 실패 값</b>에 이유가 있습니다.
        </td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="ch">아직 결정하지 않은 것 · <code>CUSTOMERS.DEL</code>
    <span style="font-weight:400;color:var(--ink3)">가장 중요한 판단 하나</span>
  </div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    옛 시스템의 <code>DEL</code> 이 <b>"지워진 거래처"인지 "거래 종료"인지</b> 확정되지 않았습니다.
    잘못 해석하면 살아 있는 거래처가 사라지므로, <b>전부 사용중으로 넣고</b> 원본값만 따로 보관했습니다.
    현재 숨겨진 거래처는 <b class="tnum"><?= money((int)$delHidden) ?></b> 건입니다.
  </div>
  <?php if ($delDist === null): ?>
    <div class="empty"><code>companies_legacy_extra</code> 가 없습니다.</div>
  <?php elseif (!$delDist): ?>
    <div class="empty">보관된 DEL 값이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:180px">DEL 원본값</th><th class="r" style="width:130px">건수</th>
      <th></th></tr></thead>
    <tbody>
    <?php foreach ($delDist as $d): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($d['v']) ?></td>
        <td class="r tnum"><?= money($d['c']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <?php if ($d['v'] === '(NULL)' || $d['v'] === ''): ?>
            값이 없던 행 — 정상 거래처로 봅니다
          <?php else: ?>
            이 값이 "삭제"를 뜻하는지 확인한 뒤 숨김 처리합니다
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="cb" style="border-top:1px solid var(--line);font-size:11.5px;color:var(--ink2)">
    해석이 정해지면 아래처럼 <b>되돌릴 수 있는 형태</b>로 한 번에 처리합니다
    (<code>deleted_at</code> 만 찍고 행은 남습니다). 값이 <code>Y</code> 인 경우의 예입니다.
  </div>
  <pre class="cb tnum" style="margin:0;border-top:1px solid var(--line);font-size:11.5px;overflow:auto">-- 먼저 몇 건인지 센다
SELECT COUNT(*) FROM companies c
  JOIN companies_legacy_extra e ON e.company_id = c.id
 WHERE e.legacy_del = 'Y' AND c.deleted_at IS NULL;

-- 숨긴다 (되돌릴 때는 deleted_at = NULL 로)
UPDATE companies c
  JOIN companies_legacy_extra e ON e.company_id = c.id
   SET c.deleted_at = '2000-01-01 00:00:00'
 WHERE e.legacy_del = 'Y' AND c.deleted_at IS NULL;</pre>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">임시 코드가 붙은 거래처
    <span style="font-weight:400;color:var(--ink3)">사람이 코드를 정해 줘야 하는 것</span>
  </div>
  <?php if ($placeholder === null): ?>
    <div class="empty">조회할 수 없습니다.</div>
  <?php elseif (!$placeholder): ?>
    <div class="empty">임시 코드가 붙은 거래처가 없습니다.</div>
  <?php else: ?>
  <div class="cb" style="font-size:12px;color:var(--ink2)">
    옛 자료에 <code>CUTCODE</code> 가 비어 있던 행입니다. 버리지 않고
    <code>LEGACY-원본번호</code> 를 붙여 넣었습니다. 쓰는 코드로 바꿔 주세요.
  </div>
  <table>
    <thead><tr><th style="width:170px">임시 코드</th><th>업체명</th>
      <th style="width:140px">사업자번호</th><th style="width:130px">전화</th>
      <th class="c" style="width:90px">원본 IDX</th><th class="c" style="width:55px"></th></tr></thead>
    <tbody>
    <?php foreach ($placeholder as $r): ?>
      <tr>
        <td class="tnum" style="color:var(--err-fg);font-weight:600"><?= h($r['company_code']) ?></td>
        <td style="font-weight:600"><?= h($r['name_ko']) ?></td>
        <td class="tnum"><?= h($r['business_number'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['phone'] ?: '-') ?></td>
        <td class="c tnum"><?= h((string)($r['legacy_idx'] ?? '-')) ?></td>
        <td class="c"><a class="btn sm" href="?p=company_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">코드가 겹쳐 뒤에 번호를 붙인 거래처</div>
  <?php if ($dupCode === null): ?>
    <div class="empty">조회할 수 없습니다.</div>
  <?php elseif (!$dupCode): ?>
    <div class="empty">겹친 코드가 없습니다.</div>
  <?php else: ?>
  <div class="cb" style="font-size:12px;color:var(--ink2)">
    옛 자료에 같은 <code>CUTCODE</code> 가 둘 이상 있었습니다. 어느 쪽도 버리지 않기 위해
    뒤에 <code>-D원본번호</code> 를 붙였습니다. <b>같은 회사가 두 번 들어간 것인지</b>,
    <b>다른 회사인데 코드를 돌려 쓴 것인지</b> 보고 정리하세요.
  </div>
  <table>
    <thead><tr><th style="width:200px">코드</th><th>업체명</th>
      <th style="width:140px">사업자번호</th>
      <th class="c" style="width:90px">원본 IDX</th><th class="c" style="width:55px"></th></tr></thead>
    <tbody>
    <?php foreach ($dupCode as $r): ?>
      <tr>
        <td class="tnum" style="color:var(--warn-fg);font-weight:600"><?= h($r['company_code']) ?></td>
        <td style="font-weight:600"><?= h($r['name_ko']) ?></td>
        <td class="tnum"><?= h($r['business_number'] ?: '-') ?></td>
        <td class="c tnum"><?= h((string)($r['legacy_idx'] ?? '-')) ?></td>
        <td class="c"><a class="btn sm" href="?p=company_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">변환 실패 값
    <span style="font-weight:400;color:var(--ink3)">넣지 못한 값을 원본 그대로 보관</span>
  </div>
  <?php if ($errGroup === null): ?>
    <div class="empty"><code>migration_errors</code> 가 없습니다.</div>
  <?php elseif (!$errGroup): ?>
    <div class="empty">변환에 실패한 값이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:150px">원본 표</th><th style="width:170px">컬럼</th>
      <th>이유</th><th class="r" style="width:110px">건수</th>
      <th class="c" style="width:70px"></th></tr></thead>
    <tbody>
    <?php foreach ($errGroup as $g): ?>
      <tr>
        <td class="tnum"><?= h($g['source_table']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($g['column_name']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h($g['reason']) ?></td>
        <td class="r tnum"><?= money($g['c']) ?></td>
        <td class="c"><a class="btn sm"
              href="?p=migration&amp;err=<?= h(urlencode((string)$g['column_name'])) ?>">원본</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($errRows !== null): ?>
    <div class="ch" style="border-top:1px solid var(--line)">
      <?= h($errTbl) ?> 의 원본값
      <a class="btn sm" style="margin-left:auto" href="?p=migration">닫기</a>
    </div>
    <?php if (!$errRows): ?>
      <div class="empty">해당 값이 없습니다.</div>
    <?php else: ?>
    <table>
      <thead><tr><th style="width:150px">원본 표</th><th class="c" style="width:100px">원본 IDX</th>
        <th style="width:260px">원본값 (그대로)</th><th>이유</th></tr></thead>
      <tbody>
      <?php foreach ($errRows as $r): ?>
        <tr>
          <td class="tnum"><?= h($r['source_table']) ?></td>
          <td class="c tnum"><?= h($r['source_idx'] ?: '-') ?></td>
          <td class="tnum" style="background:#FBF7EE"><?= h($r['raw_value'] ?? '(NULL)') ?></td>
          <td style="font-size:11.5px;color:var(--ink2)"><?= h($r['reason']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">전표 보정 내역
    <span style="font-weight:400;color:var(--ink3)">넣기 위해 손본 것</span>
  </div>
  <?php if ($flagGroup === null): ?>
    <div class="empty"><code>shipments.migration_flag</code> 가 없습니다.</div>
  <?php elseif (!$flagGroup): ?>
    <div class="empty">보정한 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>보정 내용</th><th class="r" style="width:110px">건수</th>
      <th class="c" style="width:70px"></th></tr></thead>
    <tbody>
    <?php foreach ($flagGroup as $g): ?>
      <tr>
        <td style="font-weight:600"><?= h($g['f']) ?></td>
        <td class="r tnum"><?= money($g['c']) ?></td>
        <td class="c"><a class="btn sm"
              href="?p=migration&amp;flag=<?= h(urlencode((string)$g['f'])) ?>">보기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($flagRows !== null): ?>
    <div class="ch" style="border-top:1px solid var(--line)"><?= h($flag) ?>
      <a class="btn sm" style="margin-left:auto" href="?p=migration">닫기</a>
    </div>
    <?php if (!$flagRows): ?>
      <div class="empty">해당 전표가 없습니다.</div>
    <?php else: ?>
    <table>
      <thead><tr><th style="width:160px">AWB</th><th style="width:110px">전표일</th>
        <th>거래처</th><th class="c" style="width:90px">원본 IDX</th>
        <th class="c" style="width:55px"></th></tr></thead>
      <tbody>
      <?php foreach ($flagRows as $r): ?>
        <tr>
          <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
          <td class="tnum"><?= h($r['voucher_date']) ?></td>
          <td><?= h($r['name_ko'] ?: '(연결 안 됨)') ?></td>
          <td class="c tnum"><?= h((string)($r['legacy_idx'] ?? '-')) ?></td>
          <td class="c"><a class="btn sm" href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php foreach ($extraStats as $tbl => [$lab, $cols, $stat]): ?>
<div class="card">
  <div class="ch">뜻이 확정되지 않은 컬럼 · <?= h($lab) ?>
    <span style="font-weight:400;color:var(--ink3)"><?= h($tbl) ?></span>
  </div>
  <?php if ($stat === null): ?>
    <div class="empty">이 표가 아직 없습니다.</div>
  <?php else: ?>
  <div class="cb" style="font-size:12px;color:var(--ink2)">
    <b>값이 있는 컬럼부터</b> 확인하세요. 0 인 컬럼은 옛 시스템에서도 안 쓰던 것이라
    그냥 둬도 됩니다. 뜻이 확정되면 정식 컬럼으로 옮깁니다.
  </div>
  <table>
    <thead><tr><th style="width:190px">컬럼</th><th class="r" style="width:120px">값이 있는 행</th>
      <th>지금까지 파악한 것</th></tr></thead>
    <tbody>
    <?php
    // 값이 많은 것부터 보여줍니다 — 그게 먼저 판단해야 할 것입니다
    $order = [];
    foreach ($cols as $c => $note) { $order[$c] = (int)($stat[$c] ?? 0); }
    arsort($order);
    foreach ($order as $c => $n): ?>
      <tr>
        <td class="tnum" style="font-weight:<?= $n>0?'600':'400' ?>;color:<?= $n>0?'var(--ink)':'var(--ink3)' ?>">
          <?= h($c) ?></td>
        <td class="r tnum" style="<?= $n>0?'font-weight:600':'color:var(--ink3)' ?>"><?= money($n) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h($cols[$c]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <div class="ch">이 화면이 읽기만 하는 이유</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · 여기 나오는 일은 대부분 <b>수천 행이 한 번에 바뀌는</b> 작업입니다. 버튼으로 만들어 두면
      잘못 눌렀을 때 되돌릴 방법이 마땅치 않습니다. 그래서 SQL 로 합니다 —
      먼저 <code>SELECT COUNT(*)</code> 로 몇 건인지 세고, 그 다음 <code>UPDATE</code> 를 돌립니다.<br>
    · 옛 자료 원본은 <code>companies_staging</code> · <code>shipments_staging</code> 에
      <b>손대지 않은 상태로</b> 남아 있습니다. 판단이 틀렸으면 거기서 다시 시작할 수 있습니다.<br>
    · 거래처와 전표는 <b>지울 수 없게</b> 되어 있습니다. 앱 계정에 DELETE 권한이 없고,
      바뀌기 전 행은 <code>*_history</code> 에 통째로 남습니다
      (<a href="?p=activity_log">작업로그</a> 는 누가 했는지, 이쪽은 무엇이 바뀌었는지입니다).<br>
    · 이관이 다 끝나도 <b>staging 을 지우지 마세요.</b> 몇 년 뒤 "이 금액 원래 얼마였지" 를
      확인할 수 있는 유일한 자리입니다.
  </div>
</div>
<?php layout_foot();
