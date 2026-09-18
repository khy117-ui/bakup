<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 기초잔액 관리 — 옛 시스템에서 넘어온 미수·미지급을 "전환일 한 줄"로 받습니다.
 *
 *   1) 전환일을 정합니다. 그 이전 전표·매입은 미수/미지급 계산에서 닫힙니다.
 *      (매출·매입 기록은 그대로 — 매출통계·손익은 변하지 않습니다)
 *   2) 옛 시스템의 전환일 기준 거래처별 미수(와 업체별 미지급)를 올립니다.
 *   3) 전환일 이후 들어오는 옛 미수 입금은 입금 등록에서 이 기초잔액에 배분합니다.
 *
 * 옛 전표마다 가짜 입금을 붙이지 않습니다. 그러면 입출금 통계에 128억 입금이
 * 찍히고 계좌잔액이 틀어집니다. 돈이 오가지 않았는데 오간 것처럼 기록하지 않습니다.
 */

$err = '';
$eid = entity_id();

require_perm('OPENING_MANAGE', '기초잔액 관리');

/** 헤더 글자로 칸을 찾습니다. 이미 쓴 칸은 건너뜁니다 */
function ob_col(array $head, array $names, array $skip = []): int
{
    foreach ($names as $n) {
        $n = mb_strtoupper($n);
        foreach ($head as $i => $h) {
            if (in_array($i, $skip, true)) { continue; }
            $h = mb_strtoupper(preg_replace('/\s+/u', '', (string)$h));
            if ($h !== '' && mb_strpos($h, $n) !== false) { return $i; }
        }
    }
    return -1;
}

function ob_num(?string $v): ?float
{
    $v = trim(str_replace([',', ' ', '원'], '', (string)$v));
    if ($v === '' || $v === '-') { return null; }
    // 회계 표기 (1,000) = -1000
    if (preg_match('/^\((\d+(\.\d+)?)\)$/', $v, $m)) { return -(float)$m[1]; }
    return is_numeric($v) ? (float)$v : null;
}

function ob_norm(string $s): string
{
    return mb_strtolower(preg_replace('/\s+/u', '', $s));
}

$cutover = ar_cutover();

// ---------------------------------------------------------------- 전환일 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cutover') {
    csrf_check();
    $new    = trim(post('cutover_date'));
    $reason = trim(post('reason'));
    if ($new !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new)) {
        $err = '날짜 형식이 올바르지 않습니다.';
    } elseif (mb_strlen($reason) < 2) {
        $err = '바꾸는 이유를 적어 주세요. 전 거래처 미수가 한꺼번에 움직이는 설정입니다.';
    } elseif ($new === (string)$cutover) {
        $err = '바뀐 것이 없습니다.';
    } else {
        try {
            db()->prepare("UPDATE app_settings SET setting_val = ?, updated_by = ?
                            WHERE setting_key = 'ar_cutover_date'")
                ->execute([$new !== '' ? $new : null, $_SESSION['admin_id'] ?? null]);
            fin_audit(0, 'UPDATE', 'ar_cutover_date', (string)$cutover, $new, $reason,
                      null, 'app_settings', null);
            log_action('입출금', 'UPDATE', 'app_settings', null, '전환일',
                       (string)$cutover, $new !== '' ? $new : '(해제)', $reason);
            flash($new !== ''
                ? '전환일을 ' . $new . ' 로 정했습니다. 그 이전 전표·매입은 미수/미지급에서 닫혔습니다.'
                : '전환일을 해제했습니다. 모든 전표가 다시 미수 계산에 들어갑니다.');
            redirect('?p=opening_balances');
        } catch (Throwable $e) {
            error_log('전환일 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다. 19_opening_balances.sql 을 실행했는지 확인하세요.';
        }
    }
}

// ---------------------------------------------------------------- CSV 올리기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'upload') {
    csrf_check();
    $type  = post('balance_type') === 'AP' ? 'AP' : 'AR';
    $entId = (int)post('business_entity_id', (string)$eid);
    $f     = $_FILES['csvfile'] ?? null;

    if ($cutover === null) {
        $err = '전환일을 먼저 정하세요. 기초잔액은 전환일 기준 금액입니다.';
    } elseif (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
              || !is_uploaded_file($f['tmp_name'])) {
        $err = '파일을 올리지 못했습니다.';
    } else {
        $raw = (string)file_get_contents($f['tmp_name']);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'CP949');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $rows = [];
        foreach (preg_split("/\r\n|\r|\n/", $raw) as $ln) {
            if (trim($ln) !== '') { $rows[] = str_getcsv($ln); }
        }

        // 헤더 찾기 — 금액 칸이 있는 첫 줄
        $hIdx = -1;
        foreach ($rows as $i => $r) {
            if (ob_col($r, ['미수', '미지급', '잔액', '금액', 'AMOUNT', 'BALANCE']) >= 0) { $hIdx = $i; break; }
        }
        if ($hIdx < 0) {
            $err = '금액 칸(미수 / 미지급 / 잔액 / 금액)을 찾지 못했습니다. 첫 줄에 제목이 있어야 합니다.';
        } else {
            $head  = $rows[$hIdx];
            $cCode = ob_col($head, ['거래처코드', '업체코드', 'CUTCODE', '코드']);
            $cName = ob_col($head, ['거래처명', '업체명', '상호', 'COMPANY', '거래처', '업체'],
                            $cCode >= 0 ? [$cCode] : []);
            $cAmt  = ob_col($head, ['미수', '미지급', '잔액', '금액', 'AMOUNT', 'BALANCE'],
                            array_values(array_filter([$cCode, $cName], fn($x) => $x >= 0)));
            $cEnt  = ob_col($head, ['사업자']);
            $cOrig = ob_col($head, ['발생일', '최초', '오래된']);

            if ($cName < 0 && $cCode < 0) {
                $err = '거래처(업체) 이름이나 코드 칸을 찾지 못했습니다.';
            } else {
                // 거래처 대조표 — AR 에서만 씁니다
                //
                // ★ 옛 CUTCODE 는 한 코드를 여러 회사가 나눠 쓴 경우가 있습니다
                //   (01-A101 = 프랭크웨어 / 판다코리아닷컴 / 오띠인터내셔널 — 15개 코드, 32개 회사).
                //   이관 때 첫 회사만 원래 코드를 갖고 나머지는 '01-A101-D123' 이 됩니다.
                //   그래서 옛 코드만 보고 붙이면 **엉뚱한 회사에 미수가 붙습니다.**
                //   이런 코드는 이름까지 같이 맞아야 붙이고, 안 맞으면 보류합니다.
                $byCode = []; $byName = []; $codeGroup = []; $nameOf = [];
                foreach (db()->query('SELECT id, company_code, name_ko FROM companies
                                       WHERE deleted_at IS NULL')->fetchAll() as $c) {
                    $cc = trim((string)$c['company_code']);
                    $byCode[$cc] = (int)$c['id'];
                    $byName[ob_norm((string)$c['name_ko'])][] = (int)$c['id'];
                    $nameOf[(int)$c['id']] = ob_norm((string)$c['name_ko']);
                    // '01-A101-D123' → 원래 코드 '01-A101' 묶음에 넣습니다
                    $base = preg_replace('/-D\d+$/', '', $cc);
                    $codeGroup[$base][] = (int)$c['id'];
                }
                $entByKey = [];
                foreach (entity_list() as $en) {
                    $entByKey[mb_strtoupper((string)$en['code'])] = (int)$en['id'];
                    $entByKey[ob_norm((string)$en['name_ko'])]    = (int)$en['id'];
                }

                // 같은 거래처가 여러 줄이면 합칩니다
                $bucket = [];  $unmatched = [];  $merged = 0;
                foreach ($rows as $i => $r) {
                    if ($i <= $hIdx) { continue; }
                    $code = $cCode >= 0 ? trim((string)($r[$cCode] ?? '')) : '';
                    $name = $cName >= 0 ? trim((string)($r[$cName] ?? '')) : '';
                    $amt  = ob_num($r[$cAmt] ?? '');
                    if ($code === '' && $name === '') { continue; }
                    if ($amt === null) {
                        $unmatched[] = [$code, $name, (string)($r[$cAmt] ?? ''), '금액을 읽을 수 없음'];
                        continue;
                    }
                    if ($amt == 0.0) { continue; }

                    $rowEnt = $entId;
                    if ($cEnt >= 0) {
                        $ev = trim((string)($r[$cEnt] ?? ''));
                        if ($ev !== '') {
                            $k = $entByKey[mb_strtoupper($ev)] ?? $entByKey[ob_norm($ev)] ?? null;
                            if ($k === null) {
                                $unmatched[] = [$code, $name, (string)$amt, '사업자 "' . $ev . '" 를 모름'];
                                continue;
                            }
                            $rowEnt = $k;
                        }
                    }

                    if ($type === 'AR') {
                        $cid = null; $why = '';
                        $group = $code !== '' ? ($codeGroup[$code] ?? []) : [];
                        if (count($group) > 1) {
                            // 여러 회사가 나눠 쓴 코드 — 이름으로 그 안에서 고릅니다
                            $pick = array_values(array_filter($group,
                                fn($id) => $name !== '' && $nameOf[$id] === ob_norm($name)));
                            if (count($pick) === 1) {
                                $cid = $pick[0];
                            } else {
                                $why = '코드 ' . $code . ' 를 ' . count($group)
                                     . '개 회사가 나눠 씀 — 이름이 정확히 같아야 붙입니다';
                            }
                        } elseif ($code !== '' && isset($byCode[$code])) {
                            $cid = $byCode[$code];
                        } elseif ($name !== '') {
                            $hits = $byName[ob_norm($name)] ?? [];
                            if (count($hits) === 1)    { $cid = $hits[0]; }
                            elseif (count($hits) > 1)  { $why = '같은 이름 거래처가 ' . count($hits) . '곳 — 코드로 지정하세요'; }
                            else                       { $why = '거래처를 찾지 못함'; }
                        } else {
                            $why = '코드 "' . $code . '" 인 거래처 없음';
                        }
                        if ($cid === null) {
                            $unmatched[] = [$code, $name, (string)$amt, $why];
                            continue;
                        }
                        $pk = 'C:' . $cid;
                    } else {
                        $vn = $name !== '' ? $name : $code;
                        $cid = null;
                        $pk = 'V:' . mb_substr(ob_norm($vn), 0, 110);
                    }

                    $bk = $rowEnt . '|' . $pk;
                    if (isset($bucket[$bk])) {
                        $bucket[$bk]['amount'] += $amt;
                        $merged++;
                    } else {
                        $orig = null;
                        if ($cOrig >= 0) {
                            $ts = strtotime(str_replace('.', '-', trim((string)($r[$cOrig] ?? ''))));
                            if ($ts !== false) { $orig = date('Y-m-d', $ts); }
                        }
                        $bucket[$bk] = [
                            'ent' => $rowEnt, 'cid' => $cid, 'pk' => $pk,
                            'vn' => $type === 'AP' ? ($name !== '' ? $name : $code) : null,
                            'amount' => $amt, 'orig' => $orig,
                            'ref' => mb_substr(trim($code . ' ' . $name), 0, 150),
                        ];
                    }
                }

                $pdo = db();
                $ins = 0; $upd = 0; $skipped = []; $neg = 0;
                try {
                    $pdo->beginTransaction();
                    $find = $pdo->prepare(
                        "SELECT o.id, o.amount, v.allocated FROM opening_balances o
                           JOIN v_opening_balance v ON v.id = o.id
                          WHERE o.business_entity_id = ? AND o.balance_type = ?
                            AND o.active_key = ?");
                    foreach ($bucket as $b) {
                        if ($b['amount'] < 0) { $neg++; }
                        $find->execute([$b['ent'], $type, $b['pk']]);
                        $ex = $find->fetch();
                        if ($ex) {
                            if ((float)$ex['allocated'] != 0.0) {
                                $skipped[] = [$b['ref'], money($b['amount']),
                                              '이미 ' . money($ex['allocated']) . '원 배분됨 — 화면에서 직접 고치세요'];
                                continue;
                            }
                            if ((float)$ex['amount'] == $b['amount']) { continue; }
                            $pdo->prepare(
                                'UPDATE opening_balances
                                    SET amount = ?, as_of_date = ?, origin_date = ?, legacy_ref = ?,
                                        source = ?, updated_by = ?
                                  WHERE id = ?')
                                ->execute([$b['amount'], $cutover, $b['orig'], $b['ref'], 'LEGACY',
                                           $_SESSION['admin_id'] ?? null, (int)$ex['id']]);
                            fin_audit(0, 'UPDATE', 'amount', (string)$ex['amount'], (string)$b['amount'],
                                      'CSV 재업로드', null, 'opening_balances', (int)$ex['id']);
                            $upd++;
                        } else {
                            $pdo->prepare(
                                'INSERT INTO opening_balances
                                   (business_entity_id, balance_type, company_id, counterparty_name,
                                    party_key, active_key, as_of_date, origin_date, amount,
                                    source, legacy_ref, created_by)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                                ->execute([$b['ent'], $type, $b['cid'], $b['vn'],
                                           $b['pk'], $b['pk'], $cutover, $b['orig'], $b['amount'],
                                           'LEGACY', $b['ref'], $_SESSION['admin_id'] ?? null]);
                            $newId = (int)$pdo->lastInsertId();
                            fin_audit(0, 'CREATE', 'amount', null, (string)$b['amount'],
                                      'CSV 업로드 ' . mb_substr((string)$f['name'], 0, 80),
                                      null, 'opening_balances', $newId);
                            $ins++;
                        }
                    }
                    log_action('입출금', 'CREATE', 'opening_balances', null,
                               ($type === 'AR' ? '기초미수' : '기초미지급') . ' CSV',
                               null, '추가 ' . $ins . ' · 변경 ' . $upd . ' · 못맞춤 ' . count($unmatched));
                    $pdo->commit();

                    $_SESSION['ob_result'] = [
                        'type' => $type, 'file' => (string)$f['name'],
                        'ins' => $ins, 'upd' => $upd, 'merged' => $merged, 'neg' => $neg,
                        'skipped' => $skipped, 'unmatched' => array_slice($unmatched, 0, 300),
                        'unmatched_cnt' => count($unmatched),
                    ];
                    redirect('?p=opening_balances&type=' . $type);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('기초잔액 업로드 실패: ' . $e->getMessage());
                    $err = '올리지 못했습니다. 아무것도 바뀌지 않았습니다.';
                }
            }
        }
    }
}

// ---------------------------------------------------------------- 한 건 추가
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'add') {
    csrf_check();
    $type  = post('balance_type') === 'AP' ? 'AP' : 'AR';
    $entId = (int)post('business_entity_id', (string)$eid);
    $cid   = (int)post('company_id');
    $vn    = trim(post('counterparty_name'));
    $amt   = ob_num(post('amount'));
    $orig  = post('origin_date');

    if ($cutover === null) {
        $err = '전환일을 먼저 정하세요.';
    } elseif ($amt === null || $amt == 0.0) {
        $err = '금액을 입력하세요.';
    } elseif ($type === 'AR' && $cid <= 0) {
        $err = '미수는 거래처를 골라야 합니다. 그래야 입금을 붙일 수 있습니다.';
    } elseif ($type === 'AP' && $vn === '') {
        $err = '미지급은 업체명을 입력하세요.';
    } elseif ($orig !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $orig)) {
        $err = '발생일 형식이 올바르지 않습니다.';
    } else {
        $pk = $type === 'AR' ? 'C:' . $cid : 'V:' . mb_substr(ob_norm($vn), 0, 110);
        try {
            db()->prepare(
                'INSERT INTO opening_balances
                   (business_entity_id, balance_type, company_id, counterparty_name,
                    party_key, active_key, as_of_date, origin_date, amount, source, memo, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$entId, $type, $type === 'AR' ? $cid : null, $type === 'AP' ? $vn : null,
                           $pk, $pk, $cutover, $orig ?: null, $amt, 'MANUAL',
                           post('memo') ?: null, $_SESSION['admin_id'] ?? null]);
            $newId = (int)db()->lastInsertId();
            fin_audit(0, 'CREATE', 'amount', null, (string)$amt, '직접 입력', null,
                      'opening_balances', $newId);
            flash('기초잔액을 추가했습니다.');
            redirect('?p=opening_balances&type=' . $type);
        } catch (PDOException $e) {
            $err = '이미 같은 거래처(업체)의 기초잔액이 있습니다. 목록에서 금액을 고치세요.';
        }
    }
}

// ---------------------------------------------------------------- 금액 고치기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'edit') {
    csrf_check();
    $id     = (int)post('id');
    $amt    = ob_num(post('amount'));
    $reason = trim(post('reason'));
    $st = db()->prepare('SELECT * FROM v_opening_balance WHERE id = ?');
    $st->execute([$id]);
    $ob = $st->fetch();
    if (!$ob || $ob['status'] !== 'CONFIRMED') {
        $err = '기초잔액을 찾을 수 없습니다.';
    } elseif ($amt === null) {
        $err = '금액을 입력하세요.';
    } elseif ($amt < (float)$ob['allocated']) {
        $err = '이미 ' . money($ob['allocated']) . '원이 배분되어 있어 그보다 적게 줄일 수 없습니다.';
    } elseif (mb_strlen($reason) < 2) {
        $err = '고치는 이유를 적어 주세요.';
    } else {
        db()->prepare('UPDATE opening_balances SET amount = ?, updated_by = ? WHERE id = ?')
            ->execute([$amt, $_SESSION['admin_id'] ?? null, $id]);
        fin_audit(0, 'UPDATE', 'amount', (string)$ob['amount'], (string)$amt, $reason, null,
                  'opening_balances', $id);
        flash('금액을 고쳤습니다.');
        redirect('?p=opening_balances&type=' . $ob['balance_type']);
    }
}

// ---------------------------------------------------------------- 취소
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cancel') {
    csrf_check();
    $id     = (int)post('id');
    $reason = trim(post('reason'));
    $st = db()->prepare('SELECT * FROM v_opening_balance WHERE id = ?');
    $st->execute([$id]);
    $ob = $st->fetch();
    if (!$ob || $ob['status'] !== 'CONFIRMED') {
        $err = '기초잔액을 찾을 수 없습니다.';
    } elseif ((float)$ob['allocated'] != 0.0) {
        $err = '이미 입금(지급)이 배분되어 있어 취소할 수 없습니다. 먼저 그 입출금을 취소하세요.';
    } elseif (mb_strlen($reason) < 2) {
        $err = '취소 사유를 적어 주세요.';
    } else {
        db()->prepare("UPDATE opening_balances
                          SET status = 'CANCELLED', active_key = NULL, cancelled_at = NOW(),
                              cancelled_by = ?, cancel_reason = ?
                        WHERE id = ?")
            ->execute([$_SESSION['admin_id'] ?? null, $reason, $id]);
        fin_audit(0, 'CANCEL', 'status', 'CONFIRMED', 'CANCELLED', $reason,
                  json_encode($ob, JSON_UNESCAPED_UNICODE), 'opening_balances', $id);
        flash('취소했습니다. 기록은 남아 있습니다.');
        redirect('?p=opening_balances&type=' . $ob['balance_type']);
    }
}

// ---------------------------------------------------------------- 조회
$type = query('type') === 'AP' ? 'AP' : 'AR';
$kw   = query('kw');
$only = query('only');                     // open = 잔액 남은 것만

$result = $_SESSION['ob_result'] ?? null;
unset($_SESSION['ob_result']);

// 전환일을 바꾸면 무엇이 닫히는지 미리 보여줍니다
$preview = null;
$pv = query('preview');
$pvDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pv) ? $pv : $cutover;
if ($pvDate !== null) {
    try {
        $st = db()->prepare(
            "SELECT
               (SELECT COUNT(*) FROM shipments WHERE deleted_at IS NULL AND status <> 'CANCELLED'
                  AND voucher_date < ?) AS ship_cnt,
               (SELECT COALESCE(SUM(t.grand_total),0) FROM shipments s
                  JOIN v_shipment_totals t ON t.shipment_id = s.id
                 WHERE s.deleted_at IS NULL AND s.status <> 'CANCELLED' AND s.voucher_date < ?) AS ship_amt,
               (SELECT COUNT(*) FROM shipments WHERE deleted_at IS NULL AND voucher_date >= ?) AS after_cnt,
               (SELECT COUNT(*) FROM purchases WHERE deleted_at IS NULL AND purchase_date < ?) AS pur_cnt,
               (SELECT COALESCE(SUM(total_amount),0) FROM purchases
                 WHERE deleted_at IS NULL AND purchase_date < ?) AS pur_amt,
               (SELECT COUNT(*) FROM payment_allocations pa
                  JOIN shipments s ON s.id = pa.shipment_id
                  JOIN financial_transactions f ON f.id = pa.transaction_id AND f.status = 'CONFIRMED'
                 WHERE s.voucher_date < ?) AS alloc_before");
        $st->execute([$pvDate, $pvDate, $pvDate, $pvDate, $pvDate, $pvDate]);
        $preview = $st->fetch();
    } catch (PDOException $e) {
        $preview = null;
    }
}

// 합계
$sum = ['AR' => ['amt' => 0, 'rem' => 0, 'cnt' => 0], 'AP' => ['amt' => 0, 'rem' => 0, 'cnt' => 0]];
try {
    $params = [];
    $w = entity_where('v.business_entity_id', $params);
    $st = db()->prepare("SELECT v.balance_type, SUM(v.amount) amt, SUM(v.remaining) rem, COUNT(*) cnt
                           FROM v_opening_balance v
                          WHERE $w AND v.status = 'CONFIRMED' GROUP BY v.balance_type");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $sum[$r['balance_type']] = ['amt' => (float)$r['amt'], 'rem' => (float)$r['rem'], 'cnt' => (int)$r['cnt']];
    }
} catch (PDOException $e) {
    $err = $err ?: '기초잔액 테이블이 없습니다. 19_opening_balances.sql 을 실행하세요.';
}

// 목록
$rows = [];
try {
    $params = [];
    $w = entity_where('v.business_entity_id', $params);
    $params[] = $type;
    $extra = '';
    if ($kw !== '') {
        $extra .= ' AND (c.name_ko LIKE ? OR c.company_code LIKE ? OR v.counterparty_name LIKE ? OR v.legacy_ref LIKE ?)';
        $like = '%' . $kw . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($only === 'open') { $extra .= ' AND v.remaining <> 0'; }
    $st = db()->prepare(
        "SELECT v.*, c.name_ko, c.company_code, e.code AS entity_code
           FROM v_opening_balance v
           JOIN business_entities e ON e.id = v.business_entity_id
           LEFT JOIN companies c ON c.id = v.company_id
          WHERE $w AND v.balance_type = ? AND v.status = 'CONFIRMED' $extra
          ORDER BY v.remaining DESC, v.id LIMIT 500");
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    $rows = [];
}

$editId = (int)query('edit', '0');
$companies = db()->query('SELECT id, company_code, name_ko FROM companies
                           WHERE deleted_at IS NULL ORDER BY name_ko')->fetchAll();

layout_head('기초잔액 관리', 'opening_balances');
?>
<div class="head">
  <h1>기초잔액 관리</h1>
  <div class="crumb">입출금관리 &gt; 기초잔액 관리 · <?= h(entity_label()) ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
<?php cutover_warning(); ?>

<?php if ($result): ?>
<div class="card">
  <div class="ch"><?= $result['type'] === 'AR' ? '기초 미수' : '기초 미지급' ?> 올린 결과
    <span style="font-weight:400;color:var(--ink3)"><?= h($result['file']) ?></span></div>
  <div class="cb">
    추가 <b class="tnum"><?= money($result['ins']) ?></b>건 ·
    변경 <b class="tnum"><?= money($result['upd']) ?></b>건 ·
    같은 거래처 합침 <b class="tnum"><?= money($result['merged']) ?></b>줄 ·
    <span style="color:<?= $result['unmatched_cnt'] > 0 ? 'var(--err-fg)' : 'inherit' ?>">
      못 맞춘 줄 <b class="tnum"><?= money($result['unmatched_cnt']) ?></b>건</span>
    <?php if ($result['neg'] > 0): ?>
      · <span style="color:var(--warn-fg)">음수(선수금) <b><?= money($result['neg']) ?></b>건</span>
    <?php endif; ?>
  </div>
  <?php if ($result['unmatched']): ?>
  <table>
    <thead><tr><th style="width:140px">코드</th><th>이름</th>
      <th class="r" style="width:150px">금액</th><th style="width:320px">이유</th></tr></thead>
    <tbody>
    <?php foreach ($result['unmatched'] as [$c, $n, $a, $why]): ?>
      <tr>
        <td class="tnum"><?= h($c ?: '-') ?></td>
        <td><?= h($n ?: '-') ?></td>
        <td class="r tnum"><?= h(is_numeric($a) ? money((float)$a) : $a) ?></td>
        <td style="color:var(--err-fg);font-size:11.5px"><?= h($why) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>못 맞춘 줄은 들어가지 않았습니다.</b> 버린 게 아니라 보류입니다 — 파일에서 코드를 고쳐
    다시 올리거나, 아래 <b>한 건 추가</b>로 거래처를 골라 넣으세요. 다시 올려도 이미 들어간 건은
    중복되지 않습니다.
  </span></div>
  <?php endif; ?>
  <?php if ($result['skipped']): ?>
  <div class="cb" style="border-top:1px solid var(--line);font-size:12px">
    <b>건너뛴 것</b> (이미 입금이 배분되어 CSV 로 덮어쓰지 않았습니다):
    <?php foreach ($result['skipped'] as [$ref, $a, $why]): ?>
      <div><?= h($ref) ?> · <?= h($a) ?> — <?= h($why) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">1. 전환일 (기초잔액 기준일)</div>
  <div class="cb">
    <div style="font-size:13px;margin-bottom:10px">
      지금 전환일 :
      <b class="tnum" style="font-size:16px;color:<?= $cutover ? 'var(--ink)' : 'var(--err-fg)' ?>">
        <?= h($cutover ?? '설정 안 됨') ?></b>
    </div>
    <form class="f" method="get" style="align-items:flex-end;margin-bottom:10px">
      <input type="hidden" name="p" value="opening_balances">
      <div class="fw w1"><label for="pv">이 날짜로 하면 어떻게 되나</label>
        <input type="date" id="pv" name="preview" value="<?= h($pvDate ?? '') ?>"></div>
      <button class="btn">미리 보기</button>
    </form>
    <?php if ($preview): ?>
    <table style="max-width:760px">
      <tbody>
        <tr><td style="width:280px">닫히는 매출전표 (<?= h($pvDate) ?> 이전)</td>
            <td class="r tnum"><b><?= money($preview['ship_cnt']) ?></b>건</td>
            <td class="r tnum"><?= money($preview['ship_amt']) ?>원</td></tr>
        <tr><td>닫히는 매입</td>
            <td class="r tnum"><b><?= money($preview['pur_cnt']) ?></b>건</td>
            <td class="r tnum"><?= money($preview['pur_amt']) ?>원</td></tr>
        <tr><td>계속 미수로 보는 전표 (이후)</td>
            <td class="r tnum"><b><?= money($preview['after_cnt']) ?></b>건</td><td></td></tr>
        <?php if ((int)$preview['alloc_before'] > 0): ?>
        <tr><td colspan="3" style="color:var(--err-fg)">
          <b>주의</b> — 이 날짜 이전 전표에 이미 입금 배분이 <?= money($preview['alloc_before']) ?>건
          있습니다. 전표가 닫히면 그 배분은 미수 계산에 쓰이지 않습니다.
          해당 입금을 취소하고 기초잔액에 다시 배분하세요.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <form method="post" class="f" style="align-items:flex-end;margin-top:12px"
          onsubmit="return confirm('전환일을 바꾸면 모든 거래처의 미수·미지급이 한꺼번에 바뀝니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cutover">
      <div class="fw w1"><label for="cd">전환일</label>
        <input type="date" id="cd" name="cutover_date" value="<?= h($cutover ?? '') ?>"></div>
      <div class="fw w3"><label for="cr">바꾸는 이유 *</label>
        <input type="text" id="cr" name="reason" required
               placeholder="예) 옛 시스템 9/30 마감 — 10/1 부터 새 시스템"></div>
      <button class="btn pri">전환일 저장</button>
    </form>
  </div>
  <div class="pager"><span>
    <b>전환일 = 옛 시스템 마지막 입력일의 다음 날</b>로 잡으세요. 옛 시스템에서 전표를 9/30 까지
    넣었다면 전환일은 10/1, 기초잔액은 <b>9/30 마감 기준</b> 미수입니다.
    그 이전 매출·매입 <b>기록은 지워지지 않습니다</b> — 매출통계·손익은 그대로이고,
    미수/미지급 계산에서만 빠집니다. 비워 두면 아무것도 닫히지 않습니다 (데모 자료를 볼 때).
  </span></div>
</div>

<div class="kpis">
  <div class="kpi"><div class="lab">기초 미수 (넣은 금액)</div>
    <div class="val tnum"><?= money($sum['AR']['amt']) ?></div>
    <div class="sub"><?= money($sum['AR']['cnt']) ?>곳</div></div>
  <div class="kpi"><div class="lab">기초 미수 (남은 금액)</div>
    <div class="val tnum" style="color:var(--err-fg)"><?= money($sum['AR']['rem']) ?></div>
    <div class="sub">회수 <?= money($sum['AR']['amt'] - $sum['AR']['rem']) ?></div></div>
  <div class="kpi"><div class="lab">기초 미지급 (넣은 금액)</div>
    <div class="val tnum"><?= money($sum['AP']['amt']) ?></div>
    <div class="sub"><?= money($sum['AP']['cnt']) ?>곳</div></div>
  <div class="kpi"><div class="lab">기초 미지급 (남은 금액)</div>
    <div class="val tnum"><?= money($sum['AP']['rem']) ?></div>
    <div class="sub">지급 <?= money($sum['AP']['amt'] - $sum['AP']['rem']) ?></div></div>
</div>

<div class="card">
  <div class="ch">2. 옛 시스템 잔액 올리기 (CSV)</div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <div class="fw w1"><label for="ut">종류 *</label>
        <select id="ut" name="balance_type">
          <option value="AR"<?= $type === 'AR' ? ' selected' : '' ?>>미수 (받을 돈)</option>
          <option value="AP"<?= $type === 'AP' ? ' selected' : '' ?>>미지급 (줄 돈)</option>
        </select></div>
      <div class="fw w1"><label for="ue">사업자</label>
        <select id="ue" name="business_entity_id">
          <?php foreach (entity_list() as $en): ?>
            <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $eid ? ' selected' : '' ?>>
              <?= h($en['name_ko']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label for="uf">CSV 파일 *</label>
        <input type="file" id="uf" name="csvfile" accept=".csv,text/csv" required></div>
      <button class="btn pri"<?= $cutover === null ? ' disabled title="전환일을 먼저 정하세요"' : '' ?>>올리기</button>
    </form>
  </div>
  <div class="pager"><span>
    <b>필요한 칸</b> — <b>거래처코드</b>(옛 CUTCODE) 또는 <b>거래처명</b>, 그리고 <b>미수금액</b>.
    선택: <b>사업자</b>(GPA/GPM, 없으면 위에서 고른 사업자), <b>최초발생일</b>(연령분석용).
    코드가 있으면 코드로, 없으면 이름으로 맞춥니다. <b>못 맞춘 줄은 넣지 않고 목록으로 보여드립니다.</b>
    같은 파일을 다시 올려도 중복되지 않습니다. CP949 도 그대로 읽습니다.
  </span></div>
</div>

<div class="card">
  <div class="ch">3. 한 건 추가</div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add">
      <div class="fw w1"><label for="at">종류</label>
        <select id="at" name="balance_type"
                onchange="document.getElementById('arw').style.display=this.value==='AR'?'':'none';
                          document.getElementById('apw').style.display=this.value==='AP'?'':'none';">
          <option value="AR"<?= $type === 'AR' ? ' selected' : '' ?>>미수</option>
          <option value="AP"<?= $type === 'AP' ? ' selected' : '' ?>>미지급</option>
        </select></div>
      <div class="fw w1"><label for="ae">사업자</label>
        <select id="ae" name="business_entity_id">
          <?php foreach (entity_list() as $en): ?>
            <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $eid ? ' selected' : '' ?>>
              <?= h($en['name_ko']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2" id="arw"<?= $type === 'AP' ? ' style="display:none"' : '' ?>><label for="ac">거래처</label>
        <select id="ac" name="company_id">
          <option value="">— 선택 —</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2" id="apw"<?= $type === 'AR' ? ' style="display:none"' : '' ?>><label for="av">업체명</label>
        <input type="text" id="av" name="counterparty_name" placeholder="DHL / 한성통운 …"></div>
      <div class="fw w1"><label for="aa">금액 *</label>
        <input type="text" id="aa" name="amount" class="tnum" style="text-align:right" required></div>
      <div class="fw w1"><label for="ao">최초발생일</label>
        <input type="date" id="ao" name="origin_date"></div>
      <div class="fw w1"><label for="am">메모</label>
        <input type="text" id="am" name="memo"></div>
      <button class="btn pri"<?= $cutover === null ? ' disabled' : '' ?>>추가</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $type === 'AR' ? ' pri' : '' ?>" href="?p=opening_balances&amp;type=AR">기초 미수</a>
    <a class="btn sm<?= $type === 'AP' ? ' pri' : '' ?>" href="?p=opening_balances&amp;type=AP">기초 미지급</a>
    <form class="f" method="get" style="margin-left:auto;gap:6px;align-items:center">
      <input type="hidden" name="p" value="opening_balances">
      <input type="hidden" name="type" value="<?= h($type) ?>">
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="거래처 · 코드 · 업체" style="width:180px">
      <select name="only" style="width:130px">
        <option value="">전체</option>
        <option value="open"<?= $only === 'open' ? ' selected' : '' ?>>잔액 남은 것만</option>
      </select>
      <button class="btn sm">찾기</button>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">기초잔액이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:60px">사업자</th><th>거래처 / 업체</th>
      <th style="width:105px">기준일</th><th style="width:105px">최초발생</th>
      <th class="r" style="width:135px">기초금액</th><th class="r" style="width:125px">회수·지급</th>
      <th class="r" style="width:135px">남은 금액</th><th class="c" style="width:85px">상태</th>
      <th class="c" style="width:70px">출처</th><th class="c" style="width:120px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab, $cls] = pay_status_badge($r['pay_status']); ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h($r['entity_code']) ?></td>
        <td style="font-weight:600">
          <?= h($r['balance_type'] === 'AR' ? ($r['name_ko'] ?? '(삭제된 거래처)') : $r['counterparty_name']) ?>
          <?php if ($r['company_code']): ?>
            <span style="font-weight:400;font-size:11px;color:var(--ink3)"><?= h($r['company_code']) ?></span>
          <?php endif; ?>
          <?php if ($r['legacy_ref']): ?>
            <div style="font-weight:400;font-size:11px;color:var(--ink3)">원본 · <?= h($r['legacy_ref']) ?></div>
          <?php endif; ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['as_of_date']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['origin_date'] ?: '-') ?></td>
        <td class="r tnum"><?= money($r['amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($r['allocated']) ?></td>
        <td class="r tnum" style="font-weight:700;<?= (float)$r['remaining'] > 0 ? 'color:var(--err-fg)' : '' ?>">
          <?= money($r['remaining']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c" style="font-size:11px"><?= $r['source'] === 'LEGACY' ? '옛 시스템' : '직접' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=opening_balances&amp;type=<?= h($type) ?>&amp;edit=<?= (int)$r['id'] ?>">고치기</a>
          <?php if ($r['balance_type'] === 'AR' && (float)$r['remaining'] > 0): ?>
            <a class="btn sm pri" href="?p=cash_in&amp;company_id=<?= (int)$r['company_id'] ?>">입금</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($editId === (int)$r['id']): ?>
      <tr style="background:#F7FAFB"><td colspan="10">
        <form method="post" class="f" style="align-items:flex-end;gap:8px;display:inline-flex">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="edit">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="fw w1"><label>금액</label>
            <input type="text" name="amount" class="tnum" style="text-align:right"
                   value="<?= h((string)(float)$r['amount']) ?>"></div>
          <div class="fw w2"><label>이유 *</label>
            <input type="text" name="reason" required placeholder="예) 옛 시스템 재확인 결과 정정"></div>
          <button class="btn pri">금액 저장</button>
        </form>
        <?php if ((float)$r['allocated'] == 0.0): ?>
        <form method="post" class="f" style="align-items:flex-end;gap:8px;display:inline-flex;margin-left:24px"
              onsubmit="return confirm('이 기초잔액을 취소합니다.');">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="cancel">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="fw w2"><label>취소 사유 *</label>
            <input type="text" name="reason" required></div>
          <button class="btn" style="border-color:#C9A257;color:#6B4700">취소</button>
        </form>
        <?php else: ?>
          <span style="font-size:11.5px;color:var(--ink3);margin-left:24px">
            이미 <?= money($r['allocated']) ?>원이 배분되어 취소할 수 없습니다.</span>
        <?php endif; ?>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">옛 시스템에서 받아 올 것</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · 옛 시스템 <b>미수금 현황 화면</b>에서 <b>전환일 전날 마감 기준</b>으로 거래처별 미수 잔액을
      엑셀/CSV 로 받으세요. 회계 담당자가 믿고 쓰던 숫자를 그대로 가져오는 게 가장 안전합니다.<br>
    · 그런 화면이 없으면 <b>거래처코드 · 거래처명 · 미수금액</b> 세 칸짜리 표만 있으면 됩니다.<br>
    · 운송사에 줄 돈(미지급)도 같은 방식으로 <b>업체명 · 미지급액</b> 을 올리면 됩니다.
      안 올리면 전환일 이전 매입은 전부 지급 끝난 것으로 봅니다.<br>
    · <b>IS_SALES 를 뽑은 날과 미수 잔액을 뽑은 날이 같아야 합니다.</b> 날짜가 다르면 그 사이에 생긴
      매출이 양쪽에서 빠지거나 두 번 잡힙니다.
  </div>
</div>
<?php layout_foot();
