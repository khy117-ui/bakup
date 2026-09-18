<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 은행 거래내역 가져오기.
 *
 * 인터넷뱅킹에서 받은 CSV 를 올립니다. **올린 것은 확정이 아닙니다.**
 * 거래처 자동매칭은 어디까지나 추천이고, 확실하지 않으면 미분류로 남겨
 * 사람이 보고 처리합니다 — 자동 확정은 틀렸을 때 되돌리는 비용이 훨씬 큽니다.
 *
 * 엑셀(.xlsx)은 라이브러리 없이 못 읽습니다. 은행 사이트에서 **CSV** 로 받거나,
 * 엑셀에서 열어 "CSV(쉼표로 분리)"로 다시 저장해 올리세요.
 */

$err = '';
$eid = entity_id();

require_perm('BANK_IMPORT', '은행내역 가져오기');

/** 은행마다 컬럼 이름이 달라서, 헤더 글자로 찾아냅니다 */
function bank_col(array $head, array $names): int
{
    foreach ($head as $i => $h) {
        $h = preg_replace('/\s+/u', '', (string)$h);
        foreach ($names as $n) {
            if ($h !== '' && mb_strpos($h, $n) !== false) { return $i; }
        }
    }
    return -1;
}

function bank_num(?string $v): float
{
    $v = str_replace([',', ' ', '원'], '', (string)$v);
    return is_numeric($v) ? (float)$v : 0.0;
}

// ---------------------------------------------------------------- 업로드
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'upload') {
    csrf_check();
    $entId  = (int)post('business_entity_id', (string)$eid);
    $acctId = (int)post('bank_account_id');
    $f = $_FILES['csvfile'] ?? null;

    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = '파일을 올리지 못했습니다. 크기가 너무 크지 않은지 확인하세요.';
    } elseif (!is_uploaded_file($f['tmp_name'])) {
        $err = '올바른 업로드가 아닙니다.';
    } else {
        $raw = file_get_contents($f['tmp_name']);
        if ($raw === false || $raw === '') {
            $err = '빈 파일입니다.';
        } else {
            // 은행 CSV 는 대개 CP949 입니다. UTF-8 이 아니면 변환합니다
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'CP949');
            }
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $hash = hash('sha256', $raw);

            $pdo = db();
            try {
                $pdo->beginTransaction();

                $dup = $pdo->prepare('SELECT id FROM bank_imports
                                       WHERE business_entity_id = ? AND file_hash = ?');
                $dup->execute([$entId, $hash]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException('같은 파일을 이미 올렸습니다. 중복 등록을 막았습니다.');
                }

                $lines = preg_split("/\r\n|\r|\n/", $raw);
                $rowsRaw = [];
                foreach ($lines as $ln) {
                    if (trim($ln) === '') { continue; }
                    $rowsRaw[] = str_getcsv($ln);
                }
                if (count($rowsRaw) < 2) {
                    throw new RuntimeException('내용이 없습니다. 헤더와 거래 한 줄 이상이 필요합니다.');
                }

                // 헤더는 보통 첫 줄이지만, 은행에 따라 위에 안내문이 붙습니다.
                // "거래일" 같은 글자가 있는 줄을 헤더로 봅니다
                $hIdx = 0;
                foreach ($rowsRaw as $i => $r) {
                    if (bank_col($r, ['거래일', '거래일시', '일자', '날짜']) >= 0) { $hIdx = $i; break; }
                }
                $head = $rowsRaw[$hIdx];

                $cDate  = bank_col($head, ['거래일시', '거래일자', '거래일', '일자', '날짜']);
                $cIn    = bank_col($head, ['입금', '맡기신', '입금액']);
                $cOut   = bank_col($head, ['출금', '찾으신', '출금액']);
                $cBal   = bank_col($head, ['잔액', '거래후잔액']);
                $cDesc  = bank_col($head, ['거래내용', '적요', '내용', '거래구분']);
                $cParty = bank_col($head, ['보낸분', '받는분', '의뢰인', '상대방', '거래처']);
                $cMemo  = bank_col($head, ['메모', '비고']);

                if ($cDate < 0 || ($cIn < 0 && $cOut < 0)) {
                    throw new RuntimeException(
                        '거래일과 입금/출금 칸을 찾지 못했습니다. 은행에서 받은 원본 CSV 인지 확인하세요.');
                }

                $pdo->prepare(
                    'INSERT INTO bank_imports
                       (business_entity_id, bank_account_id, file_name, file_hash,
                        row_count, imported_by)
                     VALUES (?,?,?,?,0,?)')
                    ->execute([$entId, $acctId ?: null,
                               mb_substr((string)$f['name'], 0, 255), $hash,
                               $_SESSION['admin_id'] ?? null]);
                $impId = (int)$pdo->lastInsertId();

                // 거래처 이름 — 자동매칭에 씁니다
                $comps = $pdo->query('SELECT id, name_ko FROM companies
                                       WHERE deleted_at IS NULL')->fetchAll();

                $ins = $pdo->prepare(
                    'INSERT INTO bank_import_rows
                       (import_id, business_entity_id, bank_account_id, line_no, txn_at,
                        in_amount, out_amount, balance, description, counterparty, memo,
                        raw_line, match_status, suggested_company_id, match_score)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

                $n = 0; $matched = 0;
                foreach ($rowsRaw as $i => $r) {
                    if ($i <= $hIdx) { continue; }
                    $dateRaw = trim((string)($r[$cDate] ?? ''));
                    if ($dateRaw === '') { continue; }
                    $ts = strtotime(str_replace('.', '-', $dateRaw));
                    if ($ts === false) { continue; }

                    $in  = $cIn  >= 0 ? bank_num($r[$cIn]  ?? '') : 0.0;
                    $out = $cOut >= 0 ? bank_num($r[$cOut] ?? '') : 0.0;
                    if ($in <= 0 && $out <= 0) { continue; }

                    $party = $cParty >= 0 ? trim((string)($r[$cParty] ?? '')) : '';
                    $desc  = $cDesc  >= 0 ? trim((string)($r[$cDesc]  ?? '')) : '';

                    // 자동매칭 — 이름이 그대로 들어 있으면 높은 점수, 아니면 미분류
                    $sugg = null; $score = null; $status = 'UNMATCHED';
                    $hay = preg_replace('/\s+/u', '', $party . $desc);
                    if ($hay !== '') {
                        foreach ($comps as $c) {
                            $name = preg_replace('/\s+/u', '', (string)$c['name_ko']);
                            if ($name === '' || mb_strlen($name) < 2) { continue; }
                            if ($name === $hay)                  { $sugg = (int)$c['id']; $score = 100; break; }
                            if (mb_strpos($hay, $name) !== false) { $sugg = (int)$c['id']; $score = 80; }
                        }
                    }
                    if ($sugg !== null) { $status = 'SUGGESTED'; $matched++; }

                    $ins->execute([
                        $impId, $entId, $acctId ?: null, ++$n, date('Y-m-d H:i:s', $ts),
                        $in, $out, $cBal >= 0 ? bank_num($r[$cBal] ?? '') : null,
                        mb_substr($desc, 0, 255) ?: null,
                        mb_substr($party, 0, 100) ?: null,
                        $cMemo >= 0 ? mb_substr(trim((string)($r[$cMemo] ?? '')), 0, 255) : null,
                        mb_substr(implode(',', $r), 0, 2000),
                        $status, $sugg, $score,
                    ]);
                }

                if ($n === 0) {
                    throw new RuntimeException('읽어들인 거래가 0건입니다. 파일 형식을 확인하세요.');
                }

                $pdo->prepare('UPDATE bank_imports SET row_count = ?, matched_count = ? WHERE id = ?')
                    ->execute([$n, $matched, $impId]);
                log_action('입출금', 'CREATE', 'bank_imports', $impId, (string)$f['name'],
                           null, $n . '건 · 추천 ' . $matched . '건');
                $pdo->commit();
                flash($n . '건을 읽었습니다. 거래처가 추천된 것은 ' . $matched . '건입니다. '
                    . '확인 후 처리하세요 — 자동으로 확정되지 않습니다.');
                redirect('?p=bank_import&import_id=' . $impId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = $e instanceof RuntimeException ? $e->getMessage() : '가져오지 못했습니다.';
                if (!($e instanceof RuntimeException)) { error_log('은행내역 실패: ' . $e->getMessage()); }
            }
        }
    }
}

// ---------------------------------------------------------------- 무시 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'ignore') {
    csrf_check();
    $rid = (int)post('row_id');
    db()->prepare("UPDATE bank_import_rows
                      SET match_status = 'IGNORED', confirmed_at = NOW(), confirmed_by = ?
                    WHERE id = ? AND match_status <> 'CONFIRMED'")
        ->execute([$_SESSION['admin_id'] ?? null, $rid]);
    flash('이 거래를 무시 처리했습니다. 목록에서 내려갑니다.');
    redirect('?p=bank_import&import_id=' . (int)post('import_id'));
}

// ---------------------------------------------------------------- 조회
$impId = (int)query('import_id', '0');

$params = [];
$w = entity_where('i.business_entity_id', $params);
$st = db()->prepare("SELECT i.*, e.name_ko AS entity_name, b.bank_name, b.account_no,
                            a.name AS who
                       FROM bank_imports i
                       JOIN business_entities e ON e.id = i.business_entity_id
                       LEFT JOIN business_bank_accounts b ON b.id = i.bank_account_id
                       LEFT JOIN admins a ON a.id = i.imported_by
                      WHERE $w ORDER BY i.id DESC LIMIT 20");
$st->execute($params);
$imports = $st->fetchAll();

$rows = [];
if ($impId > 0) {
    $st = db()->prepare(
        "SELECT r.*, c.name_ko AS suggested_name,
                (SELECT COALESCE(SUM(v.balance),0) FROM v_company_receivable v
                  WHERE v.company_id = r.suggested_company_id) AS suggested_balance
           FROM bank_import_rows r
           LEFT JOIN companies c ON c.id = r.suggested_company_id
          WHERE r.import_id = ?
          ORDER BY (r.match_status = 'CONFIRMED'), (r.match_status = 'IGNORED'),
                   r.line_no");
    $st->execute([$impId]);
    $rows = $st->fetchAll();
}

$accounts = db()->query('SELECT id, bank_name, account_no, business_entity_id
                          FROM business_bank_accounts WHERE is_active = 1
                          ORDER BY sort_order, id')->fetchAll();

$ST = ['UNMATCHED' => ['미분류', 'b-err'], 'SUGGESTED' => ['추천있음', 'b-warn'],
       'CONFIRMED' => ['처리완료', 'b-ok'], 'IGNORED' => ['무시', 'b-info']];

layout_head('은행내역 가져오기', 'bank_import');
?>
<div class="head">
  <h1>은행 거래내역 가져오기</h1>
  <div class="crumb">입출금관리 &gt; 은행내역 가져오기 · <?= h(entity_label()) ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">파일 올리기</div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <div class="f" style="align-items:flex-end">
        <div class="fw w1"><label for="be">사업자 *</label>
          <select id="be" name="business_entity_id">
            <?php foreach (entity_list() as $en): ?>
              <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $eid ? ' selected' : '' ?>>
                <?= h($en['name_ko']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w2"><label for="ba">계좌</label>
          <select id="ba" name="bank_account_id">
            <option value="">— 지정 안 함 —</option>
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>">
                <?= h($a['bank_name']) ?> <?= h($a['account_no']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w2"><label for="bf">CSV 파일 *</label>
          <input type="file" id="bf" name="csvfile" accept=".csv,text/csv" required></div>
        <button class="btn pri">가져오기</button>
      </div>
    </form>
  </div>
  <div class="pager"><span>
    <b>CSV 로 받으세요.</b> 엑셀(.xlsx)은 이 시스템이 직접 못 읽습니다 — 은행에서 CSV 로 받거나,
    엑셀에서 열어 <b>다른 이름으로 저장 → CSV(쉼표로 분리)</b> 로 바꿔 올리시면 됩니다.
    <b>거래일시 · 입금 · 출금 · 잔액 · 거래내용 · 보낸분/받는분 · 메모</b> 칸을 헤더 글자로 찾아냅니다.
    같은 파일을 두 번 올리면 막습니다.
  </span></div>
</div>

<?php if ($imports): ?>
<div class="card">
  <div class="ch">가져온 파일</div>
  <table>
    <thead><tr><th style="width:150px">일시</th><th>파일</th>
      <th style="width:140px">사업자</th><th style="width:150px">계좌</th>
      <th class="c" style="width:80px">건수</th><th class="c" style="width:90px">추천</th>
      <th style="width:90px">올린이</th><th class="c" style="width:55px"></th></tr></thead>
    <tbody>
    <?php foreach ($imports as $im): ?>
      <tr<?= (int)$im['id'] === $impId ? ' style="background:#F2F7FB"' : '' ?>>
        <td class="tnum" style="font-size:11.5px"><?= h($im['imported_at']) ?></td>
        <td style="font-weight:600"><?= h($im['file_name']) ?></td>
        <td style="font-size:11.5px"><?= h($im['entity_name']) ?></td>
        <td class="tnum" style="font-size:11.5px">
          <?= h($im['bank_name'] ? $im['bank_name'] . ' ' . $im['account_no'] : '-') ?></td>
        <td class="c tnum"><?= money($im['row_count']) ?></td>
        <td class="c tnum"><?= money($im['matched_count']) ?></td>
        <td style="font-size:11.5px"><?= h($im['who'] ?: '-') ?></td>
        <td class="c"><a class="btn sm" href="?p=bank_import&amp;import_id=<?= (int)$im['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($impId > 0): ?>
<div class="card">
  <div class="ch">거래 목록
    <span style="font-weight:400;color:var(--ink3)">미처리 먼저</span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">읽어들인 거래가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:145px">거래일시</th>
      <th class="r" style="width:130px">입금</th><th class="r" style="width:130px">출금</th>
      <th style="width:140px">보낸분/받는분</th><th>거래내용</th>
      <th style="width:230px">추천 거래처</th>
      <th class="c" style="width:90px">상태</th><th class="c" style="width:170px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab, $cls] = $ST[$r['match_status']] ?? [$r['match_status'], 'b-info'];
      $done = in_array($r['match_status'], ['CONFIRMED', 'IGNORED'], true); ?>
      <tr<?= $done ? ' style="color:var(--ink3)"' : '' ?>>
        <td class="tnum" style="font-size:11.5px"><?= h($r['txn_at']) ?></td>
        <td class="r tnum" style="color:#1B7F5A;font-weight:<?= (float)$r['in_amount'] > 0 ? '700' : '400' ?>">
          <?= (float)$r['in_amount'] > 0 ? money($r['in_amount']) : '' ?></td>
        <td class="r tnum" style="color:#B3261E;font-weight:<?= (float)$r['out_amount'] > 0 ? '700' : '400' ?>">
          <?= (float)$r['out_amount'] > 0 ? money($r['out_amount']) : '' ?></td>
        <td style="font-weight:600"><?= h($r['counterparty'] ?: '-') ?></td>
        <td style="font-size:11.5px"><?= h($r['description'] ?: '-') ?></td>
        <td style="font-size:11.5px">
          <?php if ($r['suggested_name']): ?>
            → <b><?= h($r['suggested_name']) ?></b>
            <span class="badge <?= (int)$r['match_score'] >= 100 ? 'b-ok' : 'b-warn' ?>">
              <?= (int)$r['match_score'] ?>점</span>
            <div style="color:var(--ink2)">미수금 <?= money($r['suggested_balance']) ?></div>
          <?php else: ?>
            <span style="color:var(--ink3)">추천 없음 — 직접 고르세요</span>
          <?php endif; ?>
        </td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c">
          <?php if (!$done): ?>
            <?php if ((float)$r['in_amount'] > 0): ?>
              <a class="btn sm pri" href="?p=cash_in<?= $r['suggested_company_id']
                   ? '&amp;company_id=' . (int)$r['suggested_company_id'] : '' ?>">입금처리</a>
            <?php else: ?>
              <a class="btn sm" href="?p=cash_out">출금처리</a>
            <?php endif; ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="ignore">
              <input type="hidden" name="row_id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="import_id" value="<?= $impId ?>">
              <button class="btn sm">무시</button>
            </form>
          <?php else: ?>
            <span style="font-size:11px"><?= h($r['confirmed_at'] ?: '') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>자동으로 확정하지 않습니다.</b> 추천은 이름이 겹친다는 뜻일 뿐입니다 —
    같은 이름의 다른 회사일 수도, 대표자 개인 이름으로 들어온 돈일 수도 있습니다.
    <b>입금처리</b>를 누르면 입금 등록 화면이 열리고, 거기서 어느 전표에 충당할지 직접 고릅니다.
  </span></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">이 기능을 쓰는 순서</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    <b>1.</b> 인터넷뱅킹에서 기간을 정해 거래내역을 <b>CSV</b> 로 받습니다.<br>
    <b>2.</b> 여기 올립니다. 계좌를 지정해 두면 나중에 대사가 쉽습니다.<br>
    <b>3.</b> 추천이 붙은 것부터 확인합니다. <b>100점</b>은 이름이 정확히 같고,
      <b>80점</b>은 이름이 포함된 경우입니다. 둘 다 확정이 아닙니다.<br>
    <b>4.</b> <b>입금처리</b>를 눌러 어느 매출전표에 충당할지 고릅니다.
      한 입금을 여러 전표에 나눌 수 있습니다.<br>
    <b>5.</b> 회사 통장 사이 이동이면 <b>출금처리 → 계좌이체</b> 로 넣으세요.
      비용으로 잡히지 않습니다.<br>
    · 이자·수수료처럼 처리할 게 없는 줄은 <b>무시</b>로 내려두면 목록이 깨끗해집니다.
  </div>
</div>
<?php layout_foot();
