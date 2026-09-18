<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 백업 / 복구.
 *
 * **복구는 이 화면에서 하지 않습니다.** 웹에서 되돌리기를 누를 수 있게 만들면
 * 잘못 눌러서 자료가 날아가는 경로가 하나 생깁니다. 되돌리기는 서버에서 직접 합니다.
 *
 * 여기서 하는 일은 두 가지입니다.
 *   1) 지금 무엇이 얼마나 있는지 보여줍니다 — 백업이 제대로 됐는지 대조할 기준값.
 *   2) 중요한 표를 CSV 로 바로 내려받습니다 — mysqldump 를 못 돌리는 상황의 최소 보험.
 */

$eid = entity_id();

// 내려받을 수 있는 표. 여기 없는 이름은 절대 내보내지 않습니다
$EXPORTABLE = [
    'companies' => ['거래처', 'SELECT * FROM companies ORDER BY id'],
    'company_contacts' => ['업체 담당자',
        'SELECT * FROM company_contacts ORDER BY company_id, id'],
    'shipments' => ['매출전표',
        'SELECT * FROM shipments WHERE business_entity_id = :eid ORDER BY id'],
    'shipment_charges' => ['매출전표 요금',
        'SELECT c.* FROM shipment_charges c JOIN shipments s ON s.id = c.shipment_id
          WHERE s.business_entity_id = :eid ORDER BY c.shipment_id, c.id'],
    'purchases' => ['매입',
        'SELECT * FROM purchases WHERE business_entity_id = :eid ORDER BY id'],
    'invoices' => ['청구서',
        'SELECT * FROM invoices WHERE business_entity_id = :eid ORDER BY id'],
    'financial_transactions' => ['입출금',
        'SELECT * FROM financial_transactions WHERE business_entity_id = :eid ORDER BY id'],
    'payment_allocations' => ['입출금 배분',
        'SELECT a.* FROM payment_allocations a
           JOIN financial_transactions f ON f.id = a.transaction_id
          WHERE f.business_entity_id = :eid ORDER BY a.id'],
    'tax_invoices' => ['세금계산서',
        'SELECT * FROM tax_invoices WHERE business_entity_id = :eid ORDER BY id'],
];

// ---------------------------------------------------------------- CSV 내려받기
$dl = query('dl');
if ($dl !== '') {
    if (!isset($EXPORTABLE[$dl])) {
        http_response_code(400);
        exit('내보낼 수 없는 표입니다.');
    }
    [$label, $sql] = $EXPORTABLE[$dl];
    $st = db()->prepare($sql);
    $st->execute(str_contains($sql, ':eid') ? ['eid' => $eid] : []);

    log_action('시스템', 'EXPORT', $dl, null, $label, null, 'CSV 내려받기');

    $file = 'goodpost_' . $dl . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");            // 엑셀이 UTF-8 로 읽게 하는 표식
    $head = false;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!$head) { fputcsv($out, array_keys($row)); $head = true; }
        // VARBINARY 같은 값은 CSV 에 넣지 않습니다
        foreach ($row as $k => $v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $row[$k] = '(binary)';
            }
        }
        fputcsv($out, $row);
    }
    if (!$head) { fputcsv($out, ['(내보낼 행이 없습니다)']); }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------- 현재 상태
$counts = [];
foreach (['companies' => '거래처', 'company_contacts' => '업체 담당자',
          'shipments' => '매출전표', 'shipment_charges' => '매출전표 요금',
          'purchases' => '매입', 'quotations' => '견적서', 'statements' => '거래명세서',
          'invoices' => '청구서', 'invoice_shipments' => '청구-전표 연결',
          'tax_invoices' => '세금계산서', 'financial_transactions' => '입출금',
          'payment_allocations' => '입출금 배분',
          'documents' => '문서보관함', 'posts' => '게시글',
          'activity_logs' => '작업로그'] as $t => $lab) {
    try {
        $counts[$t] = [$lab, (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()];
    } catch (PDOException $e) {
        $counts[$t] = [$lab, null];
    }
}

$sizes = [];
try {
    $sizes = db()->query(
        'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS BYTES
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
          ORDER BY BYTES DESC LIMIT 10')->fetchAll();
} catch (PDOException $e) {
    $sizes = [];
}

$dbTotal = 0;
foreach ($sizes as $s) { $dbTotal += (int)$s['BYTES']; }

$docCnt = 0; $docBytes = 0; $docPending = 0;
try {
    $r = db()->query('SELECT COUNT(*) c, COALESCE(SUM(size_bytes),0) b FROM documents
                       WHERE deleted_at IS NULL')->fetch();
    $docCnt = (int)$r['c']; $docBytes = (int)$r['b'];
    $docPending = (int)db()->query("SELECT COUNT(*) FROM documents
                                     WHERE deleted_at IS NULL
                                       AND backup_status <> 'SYNCED'")->fetchColumn();
} catch (PDOException $e) {
    // 아직 테이블이 없을 수 있습니다
}

$dbName = '';
try { $dbName = (string)db()->query('SELECT DATABASE()')->fetchColumn(); } catch (PDOException $e) {}

$nas = [];
try {
    $nas = db()->query("SELECT name, host, base_path, last_test_at, last_test_ok
                          FROM storage_settings
                         WHERE is_active = 1 AND role = 'BACKUP' ORDER BY id")->fetchAll();
} catch (PDOException $e) {}

layout_head('백업 / 복구', 'backup');

$mb = static fn($b) => $b > 0 ? number_format($b / 1048576, 1) . ' MB' : '-';
?>
<div class="head">
  <h1>백업 / 복구</h1>
  <div class="crumb">시스템 &gt; 백업 / 복구</div>
</div>

<div class="msg" style="background:var(--info-bg);color:var(--info-fg)">
  <b>되돌리기는 이 화면에 없습니다.</b> 웹에서 누를 수 있게 만들면 잘못 눌러 자료가 날아가는 길이
  하나 생깁니다. 되돌리기는 서버에 직접 붙어서 합니다 — 아래 순서를 그대로 쓰세요.
</div>

<div class="kpis">
  <div class="kpi"><div class="lab">DB 크기</div>
    <div class="val tnum"><?= h($mb($dbTotal)) ?></div>
    <div class="sub"><?= h($dbName ?: '-') ?></div></div>
  <div class="kpi"><div class="lab">보관 문서</div>
    <div class="val tnum"><?= money($docCnt) ?></div>
    <div class="sub"><?= h($mb($docBytes)) ?></div></div>
  <div class="kpi"><div class="lab">NAS 로 안 넘어간 문서</div>
    <div class="val tnum" style="color:<?= $docPending>0?'var(--err-fg)':'var(--ink)' ?>">
      <?= money($docPending) ?></div>
    <div class="sub">이 숫자가 0 이어야 안심입니다</div></div>
  <div class="kpi"><div class="lab">거래처</div>
    <div class="val tnum"><?= $counts['companies'][1] === null ? '-' : money($counts['companies'][1]) ?></div>
    <div class="sub">가장 먼저 지켜야 하는 표</div></div>
</div>

<div class="card">
  <div class="ch">지금 들어 있는 건수
    <span style="font-weight:400;color:var(--ink3)">백업을 되살린 뒤 이 숫자와 맞는지 대조하세요</span>
  </div>
  <table>
    <thead><tr><th style="width:200px">표</th><th style="width:170px">테이블</th>
      <th class="r" style="width:120px">건수</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($counts as $t => [$lab, $c]): ?>
      <tr>
        <td style="font-weight:600"><?= h($lab) ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink2)"><?= h($t) ?></td>
        <td class="r tnum"><?= $c === null
             ? '<span style="color:var(--err-fg)">없음</span>' : money($c) ?></td>
        <td style="font-size:11.5px;color:var(--ink3)">
          <?php if ($c === null): ?>테이블이 아직 만들어지지 않았습니다<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="ch">중요한 표 CSV 로 바로 내려받기
    <span style="font-weight:400;color:var(--ink3)">mysqldump 를 못 돌리는 상황의 최소 보험</span>
  </div>
  <table>
    <thead><tr><th style="width:220px">표</th><th>포함 범위</th>
      <th class="c" style="width:120px"></th></tr></thead>
    <tbody>
    <?php foreach ($EXPORTABLE as $k => [$lab, $sql]): ?>
      <tr>
        <td style="font-weight:600"><?= h($lab) ?>
          <div class="tnum" style="font-weight:400;font-size:11px;color:var(--ink3)"><?= h($k) ?></div></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <?= str_contains($sql, ':eid') ? '현재 사업자 자료만' : '전체 (사업자 구분 없는 표)' ?>
          · 숨긴 행까지 모두 포함
        </td>
        <td class="c"><a class="btn sm" href="?p=backup&amp;dl=<?= h($k) ?>">CSV 받기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>CSV 는 백업이 아니라 보험입니다.</b> 연결(FK)과 순서가 빠져 있어 그대로 되살릴 수는 없습니다.
    진짜 백업은 아래 <code>mysqldump</code> 입니다. 내려받은 기록은 작업로그에 남습니다.
  </span></div>
</div>

<div class="card">
  <div class="ch">제대로 백업하는 순서</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    <b>1. DB</b> — 서버에서 하루 한 번 돌립니다. 비밀번호는 명령줄에 적지 말고
    <code>~/.my.cnf</code> 에 넣으세요 (명령줄에 적으면 <code>ps</code> 로 다 보입니다).
  </div>
  <pre class="cb tnum" style="margin:0;border-top:1px solid var(--line);font-size:11.5px;overflow:auto">mysqldump --defaults-extra-file=~/.my.cnf \
  --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 \
  <?= h($dbName ?: 'DB이름') ?> | gzip &gt; goodpost_$(date +%Y%m%d).sql.gz</pre>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9;border-top:1px solid var(--line)">
    <b>2. 문서</b> — 스캔 원본입니다. DB 보다 이쪽이 더 중요합니다. DB 는 다시 입력할 수 있지만
    스캔본은 다시 만들 수 없습니다.
  </div>
  <pre class="cb tnum" style="margin:0;border-top:1px solid var(--line);font-size:11.5px;overflow:auto">rsync -av --delete <?= h(storage_root()) ?>/ nas:/volume1/goodpost/documents/</pre>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9;border-top:1px solid var(--line)">
    <b>3. 확인</b> — 백업 파일이 <b>열리는지</b> 봐야 백업입니다. 크기만 보면 안 됩니다.
    <code>gzip -t</code> 로 압축이 온전한지, 한 달에 한 번은 <b>다른 DB 에 되살려서</b>
    위의 건수와 맞는지 대조하세요.
  </div>
  <pre class="cb tnum" style="margin:0;border-top:1px solid var(--line);font-size:11.5px;overflow:auto">gzip -t goodpost_20260917.sql.gz && echo OK</pre>
</div>

<div class="card">
  <div class="ch">되살리는 순서
    <span style="font-weight:400;color:var(--ink3)">서버에서 직접</span>
  </div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    <b>1.</b> 지금 DB 를 먼저 덤프해 둡니다. 되살리기가 잘못됐을 때 돌아올 자리가 필요합니다.<br>
    <b>2.</b> <b>운영 DB 에 바로 넣지 마세요.</b> 빈 DB 를 새로 만들어 거기 넣고,
      위의 건수와 맞는지 확인한 다음 접속 설정만 바꿔 옮겨 붙습니다.<br>
    <b>3.</b> 문서 폴더는 <code>rsync</code> 를 <b>반대 방향</b>으로 돌립니다.
      이때 <code>--delete</code> 를 빼세요 — 방향을 잘못 주면 남아 있는 파일까지 지웁니다.<br>
    <b>4.</b> 되살린 뒤 <a href="?p=settings">환경설정 → 시스템 정보</a> 에서 collation 이
      한 종류인지 보세요. 덤프를 다른 문자셋으로 넣으면 조인에서 오류가 납니다.
  </div>
  <pre class="cb tnum" style="margin:0;border-top:1px solid var(--line);font-size:11.5px;overflow:auto">gunzip &lt; goodpost_20260917.sql.gz | mysql --defaults-extra-file=~/.my.cnf 새DB이름</pre>
</div>

<div class="card">
  <div class="ch">덩치 큰 표 10개</div>
  <?php if (!$sizes): ?>
    <div class="empty">조회 권한이 없어 볼 수 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>테이블</th><th class="r" style="width:130px">행 (추정)</th>
      <th class="r" style="width:130px">크기</th></tr></thead>
    <tbody>
    <?php foreach ($sizes as $s): ?>
      <tr>
        <td class="tnum"><?= h($s['TABLE_NAME']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money((int)$s['TABLE_ROWS']) ?></td>
        <td class="r tnum"><?= h($mb((int)$s['BYTES'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    행 수는 <b>추정치</b>입니다 (InnoDB 통계). 정확한 건수는 위 표를 보세요.
  </span></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">등록된 백업 저장소</div>
  <?php if (!$nas): ?>
    <div class="empty">백업 저장소가 등록되지 않았습니다.
      <a href="?p=storage_settings">저장소 설정</a> 에서 NAS 를 등록하세요.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:150px">이름</th><th>주소 · 경로</th>
      <th style="width:190px">마지막 확인</th></tr></thead>
    <tbody>
    <?php foreach ($nas as $n): ?>
      <tr>
        <td style="font-weight:600"><?= h($n['name']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($n['host']) ?> · <?= h($n['base_path']) ?></td>
        <td class="tnum" style="font-size:11.5px">
          <?php if ($n['last_test_at']): ?>
            <?= h($n['last_test_at']) ?>
            <?= (int)$n['last_test_ok'] ? '<span class="badge b-ok">OK</span>'
                                       : '<span class="badge b-err">실패</span>' ?>
          <?php else: ?><span style="color:var(--ink3)">확인한 적 없음</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
