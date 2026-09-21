<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 세금계산서 — 청구서에서 발행합니다.
 *
 * 외부 연동사(팝빌·바로빌 등)가 아직 정해지지 않아 **내부 발행까지만** 합니다.
 * 국세청 전송은 연동사가 정해지면 붙입니다. 그때까지는 여기서 만든 내용을
 * 연동사 화면에 옮겨 적거나, 승인번호를 받아와 여기 기록해 두는 방식으로 씁니다.
 */

$eid = entity_id();
$err = '';

$STATUS = ['DRAFT' => ['작성중', 'b-warn'], 'ISSUED' => ['발행', 'b-ok'],
           'SENT' => ['전송완료', 'b-ok'], 'CANCELLED' => ['취소', 'b-err'],
           'FAILED' => ['실패', 'b-err']];
// 수정세금계산서 사유 코드 (국세청 기준). 착오정정 계열은 (-)원본 + (+)정정본 두 장, 공급가액 변동은 차액 한 장
$MODIFY_REASONS = ['01' => '기재사항 착오·정정', '02' => '공급가액 변동', '03' => '환입',
                   '04' => '계약의 해제', '05' => '내국신용장 사후개설', '06' => '착오에 의한 이중발급'];
$KIND_OF_DOC = ['TAX' => 'TAXABLE', 'ZERO' => 'ZERO', 'EXEMPT' => 'EXEMPT'];   // 문서 종류 → 세금구분

schema_upgrade_tax_invoices();   // 재발행 컬럼(issued_at · revision · reissue_reason · replaced_by) 보강

/** 청구서 + 거래처 (세금계산서를 만들 때 필요한 값) */
function tax_load_invoice(int $invId, int $eid): ?array
{
    $st = db()->prepare(
        'SELECT i.*, c.name_ko, c.business_number, c.representative, c.address_ko,
                c.business_type, c.business_item, c.tax_email, c.email
           FROM invoices i JOIN companies c ON c.id = i.company_id
          WHERE i.id = ? AND i.business_entity_id = ? AND i.deleted_at IS NULL');
    $st->execute([$invId, $eid]);
    return $st->fetch() ?: null;
}

/**
 * 청구서의 현재 전표 · 조정항목으로 세금계산서 품목 줄을 세금구분별로 만듭니다.
 * 돌려주는 값: ['ZERO' => [[공급일, 품목명, 공급가액, 세액], ...], 'TAXABLE' => [...], 'EXEMPT' => [...]]
 */
function tax_lines_from_invoice(PDO $pdo, array $inv): array
{
    $labels = charge_labels();
    $ships = $pdo->prepare(
        "SELECT s.id, s.awb_no, s.voucher_date, ch.tax_type,
                GROUP_CONCAT(DISTINCT ch.charge_type ORDER BY ch.line_no SEPARATOR ',') AS types,
                SUM(ch.supply_amount) AS supply, SUM(ch.tax_amount) AS tax
           FROM invoice_shipments xs
           JOIN shipments s ON s.id = xs.shipment_id
           JOIN shipment_charges ch ON ch.shipment_id = s.id
          WHERE xs.invoice_id = ?
          GROUP BY s.id, s.awb_no, s.voucher_date, ch.tax_type, xs.line_no
          ORDER BY xs.line_no");
    $ships->execute([(int)$inv['id']]);
    $lines = ['ZERO' => [], 'TAXABLE' => [], 'EXEMPT' => []];
    foreach ($ships->fetchAll() as $s) {
        if (!isset($lines[$s['tax_type']]) || ((float)$s['supply'] == 0.0 && (float)$s['tax'] == 0.0)) {
            continue;
        }
        $names = array_map(fn($c) => $labels[$c] ?? $c, array_unique(explode(',', (string)$s['types'])));
        $lines[$s['tax_type']][] = [$s['voucher_date'], implode('·', $names) . ' ' . $s['awb_no'],
                                    (float)$s['supply'], (float)$s['tax']];
    }
    // 청구서 조정 항목(할인 · 추가 등)도 그 세금구분 쪽에 한 줄씩
    $adj = $pdo->prepare('SELECT item_name, supply_amount, tax_type, tax_amount FROM invoice_items
                           WHERE invoice_id = ? ORDER BY line_no');
    $adj->execute([(int)$inv['id']]);
    foreach ($adj->fetchAll() as $a) {
        if (isset($lines[$a['tax_type']]) && ((float)$a['supply_amount'] != 0.0 || (float)$a['tax_amount'] != 0.0)) {
            $lines[$a['tax_type']][] = [$inv['invoice_date'], (string)$a['item_name'],
                                        (float)$a['supply_amount'], (float)$a['tax_amount']];
        }
    }
    return $lines;
}

/**
 * 세금계산서 한 장을 품목과 함께 넣습니다. 돌려주는 값: [id, 문서번호]
 *   $tt    : 세금구분 ZERO / TAXABLE / EXEMPT  (문서 종류는 여기서 정해집니다)
 *   $lines : [[공급일, 품목명, 공급가액, 세액], ...]
 *   $opt   : revision · original_id · modify_reason · remark · reissue_reason
 */
function tax_invoice_insert(PDO $pdo, int $eid, array $inv, string $date, string $tt, array $lines, array $opt = []): array
{
    $kinds = ['ZERO' => ['ZERO', '영세율'], 'TAXABLE' => ['TAX', '과세'], 'EXEMPT' => ['EXEMPT', '면세']];
    [$docType, $spec] = $kinds[$tt];
    $sup = array_sum(array_column($lines, 2));
    $vat = $tt === 'TAXABLE' ? array_sum(array_column($lines, 3)) : 0.0;
    $no = next_doc_no('TAX', 'GPA-T-', '-');
    $pdo->prepare(
        "INSERT INTO tax_invoices
           (business_entity_id, doc_no, company_id, invoice_id, issue_date,
            doc_type, modify_reason, original_id, buyer_biz_no, buyer_name, buyer_rep, buyer_address,
            buyer_biz_type, buyer_biz_item, buyer_email,
            supply_total, tax_total, grand_total, status, revision, reissue_reason, remark, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT',?,?,?,?)")
        ->execute([
            $eid, $no, (int)$inv['company_id'], (int)$inv['id'], $date, $docType,
            $opt['modify_reason'] ?? null, $opt['original_id'] ?? null,
            $inv['business_number'], $inv['name_ko'], $inv['representative'],
            $inv['address_ko'], $inv['business_type'], $inv['business_item'],
            $inv['tax_email'] ?: $inv['email'],
            $sup, $vat, $sup + $vat, (int)($opt['revision'] ?? 1),
            $opt['reissue_reason'] ?? null, $opt['remark'] ?? null, $_SESSION['admin_id'] ?? null,
        ]);
    $tid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare(
        'INSERT INTO tax_invoice_items
           (tax_invoice_id, line_no, supply_date, item_name, spec, qty, unit_price, supply_amount, tax_amount)
         VALUES (?,?,?,?,?,1,?,?,?)');
    $n = 0;
    foreach ($lines as [$d, $name, $amt, $tax]) {
        $ins->execute([$tid, ++$n, $d, mb_substr($name, 0, 200), $spec, $amt, $amt, $tt === 'TAXABLE' ? $tax : 0.0]);
    }
    return [$tid, $no];
}

/** 로그에 남길 세금계산서 요약 한 줄 */
function tax_snapshot(array $t): string
{
    return sprintf('%s %s · 공급가액 %s · 세액 %s · 합계 %s · 작성일 %s · 상태 %s · %d차%s',
        tax_doc_label((string)$t['doc_type']), $t['doc_no'], number_format((float)$t['supply_total']),
        number_format((float)$t['tax_total']), number_format((float)$t['grand_total']), $t['issue_date'],
        $t['status'], (int)($t['revision'] ?? 1),
        !empty($t['nts_approval_no']) ? ' · 승인번호 ' . $t['nts_approval_no'] : '');
}

// ---------------------------------------------------------------- 청구서에서 생성
// 한 청구서에 영세율 · 과세 · 면세가 섞여 있으면 종류별로 따로 만듭니다 (한 장에 섞을 수 없음)
//   영세율분 → 영세율 세금계산서(ZERO) · 과세분 → 세금계산서(TAX) · 면세분 → 계산서(EXEMPT)
// 회사 기준: 특송 · 항공 · 해상 운송 = 영세율, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관 = 과세 (CHARGE_TYPES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    $invId = (int)post('invoice_id');
    $date  = post('issue_date', date('Y-m-d'));

    $inv = tax_load_invoice($invId, $eid);

    if (!$inv) {
        $err = '청구서를 찾을 수 없습니다.';
    } elseif ($inv['status'] === 'DRAFT') {
        $err = '아직 발행하지 않은 청구서입니다. 청구서를 먼저 발행하세요.';
    } elseif ($inv['status'] === 'CANCELLED') {
        $err = '취소된 청구서로는 세금계산서를 만들 수 없습니다.';
    } elseif (trim((string)$inv['business_number']) === '') {
        $err = '거래처에 사업자등록번호가 없습니다. 거래처 정보를 먼저 채우세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '작성일자를 입력하세요.';
    } else {
        $dup = db()->prepare(
            "SELECT id FROM tax_invoices
              WHERE invoice_id = ? AND deleted_at IS NULL AND status <> 'CANCELLED'");
        $dup->execute([$invId]);
        if ($dup->fetchColumn()) {
            $err = '이 청구서로 이미 세금계산서를 만들었습니다.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();

                $lines = tax_lines_from_invoice($pdo, $inv);
                $made = [];
                foreach (['ZERO', 'TAXABLE', 'EXEMPT'] as $tt) {
                    if (!$lines[$tt]) { continue; }
                    $sup = array_sum(array_column($lines[$tt], 2));
                    $vat = $tt === 'TAXABLE' ? array_sum(array_column($lines[$tt], 3)) : 0.0;
                    if ($sup == 0.0 && $vat == 0.0) { continue; }
                    [$tid, $no] = tax_invoice_insert($pdo, $eid, $inv, $date, $tt, $lines[$tt]);
                    $docType = ['ZERO' => 'ZERO', 'TAXABLE' => 'TAX', 'EXEMPT' => 'EXEMPT'][$tt];
                    log_action('세금계산서', 'CREATE', 'tax_invoices', $tid, $no, null,
                               tax_doc_label($docType) . ' · 청구서 ' . $inv['invoice_no'] . ' · 품목 ' . count($lines[$tt]) . '건');
                    $made[] = [$tid, $no, tax_doc_label($docType)];
                }
                if (!$made) {
                    throw new RuntimeException('담을 품목이 없습니다. 청구서 금액을 확인하세요.');
                }
                $pdo->commit();
                flash(count($made) === 1
                    ? $made[0][2] . ' ' . $made[0][1] . ' 를 만들었습니다. 내용을 확인하고 발행하세요.'
                    : '영세율 · 과세를 나눠 ' . count($made) . '장을 만들었습니다 ('
                      . implode(', ', array_map(fn($m) => $m[2] . ' ' . $m[1], $made)) . '). 내용을 확인하고 발행하세요.');
                redirect(count($made) === 1 ? '?p=tax_invoices&id=' . $made[0][0] : '?p=tax_invoices');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('세금계산서 생성 실패: ' . $e->getMessage());
                $err = $e instanceof RuntimeException ? $e->getMessage() : '만들지 못했습니다.';
            }
        }
    }
}

// ---------------------------------------------------------------- 재발행 · 수정세금계산서
// 청구서를 고쳐 재발행한 뒤(또는 내용 착오 때) 세금계산서를 다시 만듭니다.
//   · 국세청 전송 전(승인번호 없음): 청구서의 현재 금액으로 새 문서를 만들고 이전 문서는 취소(대체) 처리
//   · 국세청 전송 후(승인번호 있음): 원본은 그대로 두고 수정세금계산서를 만듭니다
//       - 착오정정 계열(01 · 03 · 04 · 05 · 06): (-) 원본 취소분 + (+) 정정본  두 장
//       - 02 공급가액 변동: 차액만 한 장
//   어느 경우든 사유가 필수이고, 이전 · 이후 금액이 작업로그에 남습니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'reissue') {
    csrf_check();
    $tid  = (int)post('id');
    $why  = trim(post('reason'));
    $code = post('modify_reason', '01');
    $date = post('issue_date', date('Y-m-d'));
    $st = db()->prepare('SELECT * FROM tax_invoices WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$tid, $eid]);
    $t = $st->fetch();
    $inv = $t && $t['invoice_id'] ? tax_load_invoice((int)$t['invoice_id'], $eid) : null;
    $tt = $t ? ($KIND_OF_DOC[$t['doc_type']] ?? null) : null;

    if (!$t) {
        $err = '세금계산서를 찾을 수 없습니다.';
    } elseif ($t['status'] === 'CANCELLED') {
        $err = '취소된 문서는 재발행할 수 없습니다. 대체 문서가 있으면 그 문서에서 하세요.';
    } elseif (!empty($t['replaced_by'])) {
        $err = '이미 다른 문서로 대체(재발행)된 문서입니다.';
    } elseif (!$inv) {
        $err = '연결된 청구서가 없어 재발행할 수 없습니다.';
    } elseif ($inv['status'] === 'CANCELLED') {
        $err = '청구서가 취소되어 있습니다.';
    } elseif ($tt === null) {
        $err = '이 문서 종류는 재발행을 지원하지 않습니다.';
    } elseif (mb_strlen($why) < 2) {
        $err = '재발행 사유를 적어 주세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '작성일자를 입력하세요.';
    } elseif (!isset($MODIFY_REASONS[$code])) {
        $err = '수정 사유 코드가 올바르지 않습니다.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $lines = tax_lines_from_invoice($pdo, $inv)[$tt];
            $newSup = array_sum(array_column($lines, 2));
            $newTax = $tt === 'TAXABLE' ? array_sum(array_column($lines, 3)) : 0.0;
            if (!$lines || ($newSup == 0.0 && $newTax == 0.0)) {
                throw new RuntimeException('청구서에 ' . tax_doc_label((string)$t['doc_type']) . ' 대상 금액이 없습니다. 청구서를 확인하세요.');
            }
            $sent   = $t['status'] === 'SENT' || trim((string)$t['nts_approval_no']) !== '';
            $before = tax_snapshot($t);
            $rev    = (int)($t['revision'] ?? 1) + 1;
            $made   = [];

            if (!$sent) {
                // 국세청 전송 전 — 새로 만들고 이전 문서는 취소(대체)
                [$nid, $nno] = tax_invoice_insert($pdo, $eid, $inv, $date, $tt, $lines,
                    ['revision' => $rev, 'original_id' => null, 'reissue_reason' => $why,
                     'remark' => '재발행 (' . $t['doc_no'] . ' 대체)']);
                $pdo->prepare("UPDATE tax_invoices SET status = 'CANCELLED', replaced_by = ?, reissue_reason = ? WHERE id = ?")
                    ->execute([$nid, $why, $tid]);
                $made[] = [$nid, $nno];
                log_action('세금계산서', 'CANCEL', 'tax_invoices', $tid, (string)$t['doc_no'], $before,
                           '재발행으로 대체 → ' . $nno, $why);
                $nt = $pdo->prepare('SELECT * FROM tax_invoices WHERE id = ?'); $nt->execute([$nid]);
                log_action('세금계산서', 'CREATE', 'tax_invoices', $nid, $nno, $before,
                           '재발행 ' . $rev . '차 · ' . tax_snapshot($nt->fetch()), $why);
                $msg = '세금계산서를 재발행했습니다 (' . $rev . '차, ' . $nno . '). 이전 문서 ' . $t['doc_no'] . ' 는 취소(대체) 처리했습니다.'
                     . ' 내용을 확인하고 발행하세요.';
            } else {
                // 국세청 전송 후 — 수정세금계산서
                $memo = '수정세금계산서 (' . $MODIFY_REASONS[$code] . ') · 원본 ' . $t['doc_no'] . ($t['nts_approval_no'] ? ' 승인 ' . $t['nts_approval_no'] : '');
                if ($code === '02') {
                    $dSup = $newSup - (float)$t['supply_total'];
                    $dTax = $newTax - (float)$t['tax_total'];
                    if (abs($dSup) < 0.5 && abs($dTax) < 0.5) {
                        throw new RuntimeException('원본과 금액 차이가 없습니다. 공급가액 변동이 아니면 다른 사유를 고르세요.');
                    }
                    [$nid, $nno] = tax_invoice_insert($pdo, $eid, $inv, $date, $tt,
                        [[$date, '공급가액 변동분 (원본 ' . $t['doc_no'] . ')', $dSup, $dTax]],
                        ['revision' => $rev, 'original_id' => $tid, 'modify_reason' => $code, 'reissue_reason' => $why, 'remark' => $memo]);
                    $made[] = [$nid, $nno];
                } else {
                    // (-) 원본 취소분: 원본 품목을 그대로 음수로
                    $it = $pdo->prepare('SELECT supply_date, item_name, supply_amount, tax_amount FROM tax_invoice_items
                                          WHERE tax_invoice_id = ? ORDER BY line_no');
                    $it->execute([$tid]);
                    $neg = [];
                    foreach ($it->fetchAll() as $r) {
                        $neg[] = [$r['supply_date'] ?: $date, (string)$r['item_name'], -(float)$r['supply_amount'], -(float)$r['tax_amount']];
                    }
                    [$nid1, $nno1] = tax_invoice_insert($pdo, $eid, $inv, $date, $tt, $neg,
                        ['revision' => $rev, 'original_id' => $tid, 'modify_reason' => $code, 'reissue_reason' => $why,
                         'remark' => $memo . ' · 취소분(-)']);
                    [$nid, $nno] = tax_invoice_insert($pdo, $eid, $inv, $date, $tt, $lines,
                        ['revision' => $rev, 'original_id' => $tid, 'modify_reason' => $code, 'reissue_reason' => $why,
                         'remark' => $memo . ' · 정정본(+)']);
                    $made[] = [$nid1, $nno1];
                    $made[] = [$nid, $nno];
                }
                $pdo->prepare('UPDATE tax_invoices SET replaced_by = ?, reissue_reason = ? WHERE id = ?')
                    ->execute([$nid, $why, $tid]);
                log_action('세금계산서', 'UPDATE', 'tax_invoices', $tid, (string)$t['doc_no'], $before,
                           '수정세금계산서 발행 (' . $MODIFY_REASONS[$code] . ') → ' . implode(', ', array_column($made, 1)), $why);
                foreach ($made as [$mid, $mno]) {
                    $nt = $pdo->prepare('SELECT * FROM tax_invoices WHERE id = ?'); $nt->execute([$mid]);
                    log_action('세금계산서', 'CREATE', 'tax_invoices', $mid, $mno, $before,
                               '수정세금계산서 · ' . tax_snapshot($nt->fetch()), $why);
                }
                $msg = '수정세금계산서 ' . count($made) . '장을 만들었습니다 (' . implode(', ', array_column($made, 1)) . ').'
                     . ' 홈택스 [수정발급] 메뉴에서 원본 승인번호로 발급한 뒤 승인번호를 기록하세요.';
            }
            $pdo->commit();
            flash($msg);
            redirect('?p=tax_invoices&id=' . $made[count($made) - 1][0]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('세금계산서 재발행 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : '재발행하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 홈택스 일괄발급 엑셀 내려받기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'hometax_xlsx') {
    csrf_check();
    require_once APP_DIR . '/hometax.php';
    try {
        [$rows, $skip, $used] = hometax_rows(db(), $eid, (array)($_POST['tid'] ?? []));
        if (!$rows) {
            $err = '내려받을 세금계산서가 없습니다.'
                 . ($skip ? ' 뺀 것: ' . implode(' / ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($skip), $skip)) : '');
        } else {
            $bin = hometax_xlsx($rows);
            log_action('세금계산서', 'EXPORT', 'tax_invoices', null, '홈택스 엑셀', null,
                       count($rows) . '건 · ' . implode(',', $used) . ($skip ? ' · 뺀 것 ' . count($skip) . '건' : ''));
            if ($skip) {
                // 뺀 것은 다음 화면에서 알려 줍니다 (파일 응답에는 메시지를 못 붙임)
                flash('홈택스 엑셀에서 뺀 것 ' . count($skip) . '건: '
                      . implode(' / ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($skip), $skip)));
            }
            $fn = 'hometax_' . entity_code($eid) . '_' . date('Ymd_His') . '_' . count($rows) . '건.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"hometax.xlsx\"; filename*=UTF-8''" . rawurlencode($fn));
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: no-store');
            echo $bin;
            exit;
        }
    } catch (Throwable $e) {
        error_log('홈택스 엑셀 실패: ' . $e->getMessage());
        $err = $e instanceof RuntimeException ? $e->getMessage() : '엑셀을 만들지 못했습니다.';
    }
}

// ---------------------------------------------------------------- 홈택스 발급 목록으로 승인번호 붙이기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'hometax_match') {
    csrf_check();
    require_once APP_DIR . '/hometax.php';
    try {
        $res = hometax_match_approvals(db(), $eid, (string)($_POST['pasted'] ?? ''));
        if ($res['error'] !== '') {
            $err = $res['error'];
        } else {
            flash('승인번호를 ' . count($res['matched']) . '건 붙였습니다.'
                  . ($res['unmatched'] ? ' 못 찾은 것 ' . count($res['unmatched']) . '건: '
                                         . implode(' / ', array_slice($res['unmatched'], 0, 10)) : ''));
            redirect('?p=tax_invoices');
        }
    } catch (Throwable $e) {
        error_log('홈택스 승인번호 붙이기 실패: ' . $e->getMessage());
        $err = '승인번호를 붙이지 못했습니다.';
    }
}

// ---------------------------------------------------------------- 상태 변경
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['issue', 'cancel'], true)) {
    csrf_check();
    $tid = (int)post('id');
    $st = db()->prepare('SELECT * FROM tax_invoices WHERE id = ? AND business_entity_id = ?');
    $st->execute([$tid, $eid]);
    $t = $st->fetch();
    if (!$t) {
        $err = '세금계산서를 찾을 수 없습니다.';
    } elseif (post('act') === 'issue') {
        $nts = post('nts_approval_no');
        db()->prepare(
            "UPDATE tax_invoices SET status = ?, nts_approval_no = ?, sent_at = NOW(), issued_at = COALESCE(issued_at, NOW())
              WHERE id = ?")
            ->execute([$nts !== '' ? 'SENT' : 'ISSUED', $nts ?: null, $tid]);
        log_action('세금계산서', 'ISSUE', 'tax_invoices', $tid, (string)$t['doc_no'],
                   tax_snapshot($t), ($nts !== '' ? 'SENT ' . $nts : 'ISSUED') . ' · ' . (int)($t['revision'] ?? 1) . '차');
        flash($nts !== '' ? '승인번호를 기록하고 전송완료로 표시했습니다.' : '발행 처리했습니다.');
        redirect('?p=tax_invoices&id=' . $tid);
    } else {
        $why = post('reason');
        if (mb_strlen($why) < 2) {
            $err = '취소 사유를 적어 주세요.';
        } else {
            db()->prepare("UPDATE tax_invoices SET status = 'CANCELLED' WHERE id = ?")
                ->execute([$tid]);
            log_action('세금계산서', 'CANCEL', 'tax_invoices', $tid, (string)$t['doc_no'],
                       (string)$t['status'], 'CANCELLED', $why);
            flash('취소 처리했습니다. 국세청에 이미 전송했다면 수정세금계산서를 따로 발행해야 합니다.');
            redirect('?p=tax_invoices');
        }
    }
}

// ---------------------------------------------------------------- 조회
$id = (int)query('id', '0');
$cur = null; $items = [];
if ($id > 0) {
    $st = db()->prepare(
        'SELECT t.*, i.invoice_no, i.status AS inv_status, i.issue_count AS inv_issue_count, i.issued_at AS inv_issued_at,
                i.zero_supply AS inv_zero, i.taxable_supply AS inv_taxable, i.exempt_supply AS inv_exempt, i.tax_total AS inv_tax,
                o.doc_no AS original_no, r.doc_no AS replaced_no
           FROM tax_invoices t
           LEFT JOIN invoices i ON i.id = t.invoice_id
           LEFT JOIN tax_invoices o ON o.id = t.original_id
           LEFT JOIN tax_invoices r ON r.id = t.replaced_by
          WHERE t.id = ? AND t.business_entity_id = ? AND t.deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if ($cur) {
        $st = db()->prepare('SELECT * FROM tax_invoice_items WHERE tax_invoice_id = ?
                              ORDER BY line_no');
        $st->execute([$id]);
        $items = $st->fetchAll();
    }
}
$mismatch = null;      // 청구서의 현재 금액과 이 문서 금액이 다르면 [문서 공급가액, 문서 세액, 청구서 공급가액, 청구서 세액]
$children = [];        // 이 문서를 원본으로 만든 수정세금계산서들
$history  = [];
$canReissue = false;
if ($cur) {
    $kind = $KIND_OF_DOC[$cur['doc_type']] ?? null;
    $active = $cur['status'] !== 'CANCELLED' && empty($cur['replaced_by']);
    $canReissue = $active && $cur['invoice_id'] && $kind !== null && ($cur['inv_status'] ?? '') !== 'CANCELLED';
    if ($active && $kind !== null && $cur['invoice_id'] && empty($cur['original_id'])) {
        $invSup = ['ZERO' => (float)$cur['inv_zero'], 'TAXABLE' => (float)$cur['inv_taxable'], 'EXEMPT' => (float)$cur['inv_exempt']][$kind];
        $invTax = $kind === 'TAXABLE' ? (float)$cur['inv_tax'] : 0.0;
        if (abs($invSup - (float)$cur['supply_total']) > 0.5 || abs($invTax - (float)$cur['tax_total']) > 0.5) {
            $mismatch = [(float)$cur['supply_total'], (float)$cur['tax_total'], $invSup, $invTax];
        }
    }
    $st = db()->prepare('SELECT id, doc_no, status, supply_total, tax_total, modify_reason, remark FROM tax_invoices
                          WHERE original_id = ? AND deleted_at IS NULL ORDER BY id');
    $st->execute([$id]);
    $children = $st->fetchAll();
    // 변경 이력 — 이 문서와 원본 · 대체 · 수정 문서의 로그를 함께
    $ids = array_values(array_unique(array_filter(array_merge([$id, (int)$cur['original_id'], (int)$cur['replaced_by']],
                                                               array_map(fn($c) => (int)$c['id'], $children)))));
    $st = db()->prepare("SELECT * FROM activity_logs WHERE ref_table = 'tax_invoices' AND ref_id IN ("
                        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 100');
    $st->execute($ids);
    $history = $st->fetchAll();
}
$ACT = ['CREATE' => ['만듦', 'b-ok'], 'UPDATE' => ['수정', 'b-info'], 'DELETE' => ['삭제', 'b-err'],
        'CANCEL' => ['취소', 'b-err'], 'ISSUE' => ['발행', 'b-info'], 'PRINT' => ['출력', 'b-warn'],
        'EXPORT' => ['내보내기', 'b-warn'], 'DOWNLOAD' => ['다운로드', 'b-warn']];

$kw  = query('kw');
$sel = query('status');
$where = ['t.business_entity_id = ?', 't.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(t.doc_no LIKE ? OR t.buyer_name LIKE ? OR t.nts_approval_no LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like);
}
if (isset($STATUS[$sel])) { $where[] = 't.status = ?'; $params[] = $sel; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT t.*, i.invoice_no FROM tax_invoices t
                       LEFT JOIN invoices i ON i.id = t.invoice_id
                      WHERE $w ORDER BY t.issue_date DESC, t.id DESC LIMIT 100");
$st->execute($params);
$rows = $st->fetchAll();

// 세금계산서를 아직 안 만든 청구서
$st = db()->prepare(
    "SELECT i.id, i.invoice_no, i.invoice_date, i.taxable_supply, i.zero_supply,
            i.tax_total, i.grand_total, c.name_ko, c.business_number
       FROM invoices i
       JOIN companies c ON c.id = i.company_id
      WHERE i.business_entity_id = ? AND i.deleted_at IS NULL
        AND i.status NOT IN ('DRAFT','CANCELLED')
        AND NOT EXISTS (SELECT 1 FROM tax_invoices x
                         WHERE x.invoice_id = i.id AND x.deleted_at IS NULL
                           AND x.status <> 'CANCELLED')
      ORDER BY i.invoice_date DESC LIMIT 50");
$st->execute([$eid]);
$pending = $st->fetchAll();

layout_head('전자세금계산서', 'tax_invoices');
?>
<div class="head">
  <h1>전자세금계산서</h1>
  <div class="crumb">회계관리 &gt; 전자세금계산서</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="msg" style="background:var(--info-bg);color:var(--info-fg);line-height:1.8">
  <b>홈택스 엑셀 일괄발급으로 국세청에 보냅니다.</b>
  ① 아래 발행 내역에서 <b>작성중</b> 인 것을 골라 <b>[홈택스 엑셀 내려받기]</b> (한 파일 100건까지)
  → ② 홈택스 <b>전자(세금)계산서 일괄발급</b> 에 그 파일을 올려 발급 (인증서 서명 — 50건씩)
  → ③ 홈택스 발급 목록을 엑셀로 받아 <b>머리행까지 복사</b> 해 아래 <b>[승인번호 붙이기]</b> 칸에 붙여 넣으면 승인번호가 자동으로 붙고 '전송완료' 가 됩니다.
  <span style="color:var(--ink3)">엑셀 비고 칸에 ERP 문서번호가 들어가 짝을 찾습니다. 비고를 지우지 마세요.</span>
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($cur['doc_no']) ?></span>
    <?php [$lab,$cls] = $STATUS[$cur['status']] ?? [$cur['status'],'b-info']; ?>
    <span class="badge <?= $cls ?>"><?= h($lab) ?></span>
    <span class="badge <?= $cur['doc_type'] === 'TAX' ? 'b-info' : 'b-warn' ?>"><?= h(tax_doc_label((string)$cur['doc_type'])) ?></span>
    <?php if ((int)($cur['revision'] ?? 1) > 1): ?><span class="badge b-info">재발행 <?= (int)$cur['revision'] ?>차</span><?php endif; ?>
    <?php if (!empty($cur['original_id'])): ?>
      <span class="badge b-warn">수정세금계산서<?= !empty($cur['modify_reason']) ? ' · ' . h($MODIFY_REASONS[$cur['modify_reason']] ?? $cur['modify_reason']) : '' ?></span>
      <a href="?p=tax_invoices&amp;id=<?= (int)$cur['original_id'] ?>" style="font-size:12px">원본 <?= h((string)$cur['original_no']) ?></a>
    <?php endif; ?>
    <?php if (!empty($cur['replaced_by'])): ?>
      <span class="badge b-err">대체됨</span>
      <a href="?p=tax_invoices&amp;id=<?= (int)$cur['replaced_by'] ?>" style="font-size:12px">→ <?= h((string)$cur['replaced_no']) ?></a>
    <?php endif; ?>
    <a class="btn sm" style="margin-left:auto" href="?p=tax_invoices">목록</a>
  </div>
  <?php if ($mismatch): ?>
  <div class="msg err" style="margin:10px 12px 0">
    청구서 <?= h((string)$cur['invoice_no']) ?> 의 현재 금액과 다릅니다 —
    이 문서 공급가액 <b class="tnum"><?= money($mismatch[0]) ?></b> · 세액 <b class="tnum"><?= money($mismatch[1]) ?></b>
    / 청구서 공급가액 <b class="tnum"><?= money($mismatch[2]) ?></b> · 세액 <b class="tnum"><?= money($mismatch[3]) ?></b>.
    <?= (int)($cur['inv_issue_count'] ?? 0) > 1 ? '청구서가 ' . (int)$cur['inv_issue_count'] . '회차로 재발행되었습니다. ' : '' ?>
    아래 <b>[재발행]</b> 으로 세금계산서를 다시 만드세요.
  </div>
  <?php endif; ?>
  <?php if ($children): ?>
  <div class="msg" style="margin:10px 12px 0;background:var(--info-bg);color:var(--info-fg)">
    이 문서로 만든 수정세금계산서:
    <?php foreach ($children as $c): ?>
      <a href="?p=tax_invoices&amp;id=<?= (int)$c['id'] ?>" class="tnum"><?= h($c['doc_no']) ?></a>
      (<?= money($c['supply_total']) ?> / <?= money($c['tax_total']) ?> · <?= h($STATUS[$c['status']][0] ?? $c['status']) ?>)
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">공급받는자</div>
      <div style="font-weight:600"><?= h($cur['buyer_name']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">사업자번호</div>
      <div class="tnum"><?= h($cur['buyer_biz_no']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">대표자</div>
      <div><?= h($cur['buyer_rep'] ?: '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">작성일자</div>
      <div class="tnum"><?= h($cur['issue_date']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">청구서</div>
      <div><?= $cur['invoice_no']
           ? '<a href="?p=invoice_view&amp;id=' . (int)$cur['invoice_id'] . '">'
             . h($cur['invoice_no']) . '</a>' : '-' ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">국세청 승인번호</div>
      <div class="tnum"><?= h($cur['nts_approval_no'] ?: '미기록') ?></div></div>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:105px">공급일</th>
      <th>품목</th><th style="width:90px">규격</th>
      <th class="r" style="width:130px">공급가액</th><th class="r" style="width:110px">세액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td class="c tnum"><?= (int)$it['line_no'] ?></td>
        <td class="tnum"><?= h($it['supply_date']) ?></td>
        <td><?= h($it['item_name']) ?></td>
        <td><?= h($it['spec'] ?: '-') ?></td>
        <td class="r tnum"><?= money($it['supply_amount']) ?></td>
        <td class="r tnum"><?= money($it['tax_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F7FAFB">
        <td colspan="4" class="r" style="font-weight:700">합계</td>
        <td class="r tnum" style="font-weight:700"><?= money($cur['supply_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($cur['tax_total']) ?></td>
      </tr>
    </tbody>
  </table>
  <div class="cb" style="border-top:1px solid var(--line);display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if (in_array($cur['status'], ['DRAFT','ISSUED'], true)): ?>
      <form method="post" class="f" style="align-items:flex-end;gap:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="issue">
        <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <div class="fw w2"><label>국세청 승인번호 (있으면)</label>
          <input type="text" name="nts_approval_no" class="tnum"
                 value="<?= h($cur['nts_approval_no']) ?>"
                 placeholder="연동사에서 발행 후 받은 번호"></div>
        <button class="btn pri">발행 처리</button>
      </form>
    <?php endif; ?>
    <?php if ($canReissue): $sentDoc = $cur['status'] === 'SENT' || trim((string)$cur['nts_approval_no']) !== ''; ?>
      <form method="post" class="f" style="align-items:flex-end;gap:8px;flex-basis:100%;padding:10px 0;border-top:1px dashed var(--line)"
            onsubmit="return confirm('<?= $sentDoc
                ? '국세청에 전송된 문서입니다. 원본은 그대로 두고 수정세금계산서를 만듭니다. 계속할까요?'
                : '청구서의 현재 금액으로 세금계산서를 다시 만들고, 이 문서는 취소(대체) 처리합니다. 계속할까요?' ?>');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="reissue">
        <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <div class="fw w1"><label>작성일자</label><input type="date" name="issue_date" value="<?= h(date('Y-m-d')) ?>"></div>
        <?php if ($sentDoc): ?>
        <div class="fw w2"><label>수정 사유 (국세청)</label>
          <select name="modify_reason">
            <?php foreach ($MODIFY_REASONS as $k => $v): ?><option value="<?= $k ?>"><?= $k ?> <?= h($v) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="fw gr" style="min-width:240px"><label><?= $sentDoc ? '수정 발행' : '재발행' ?> 사유 *</label>
          <input type="text" name="reason" required placeholder="예) 청구서 운임 정정으로 금액 변경"></div>
        <button class="btn <?= $mismatch ? 'pri' : '' ?>"><?= $sentDoc ? '수정세금계산서 발행' : '재발행' ?></button>
        <div style="flex-basis:100%;font-size:11.5px;color:var(--ink3)">
          <?= $sentDoc
              ? '착오정정 계열 사유는 (-)원본 취소분과 (+)정정본 두 장, 공급가액 변동은 차액 한 장이 만들어집니다. 홈택스 [수정발급] 메뉴에서 원본 승인번호로 발급하세요.'
              : '아직 국세청에 보내지 않은 문서라 새 문서로 바꿉니다. 이전 문서는 취소(대체) 상태로 남고 이력에서 볼 수 있습니다.' ?>
        </div>
      </form>
    <?php endif; ?>
    <?php if ($cur['status'] !== 'CANCELLED'): ?>
      <form method="post" class="f" style="align-items:flex-end;gap:8px;margin-left:auto"
            onsubmit="return confirm('세금계산서를 취소 처리합니다.');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="cancel">
        <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <div class="fw w3"><label>취소 사유 *</label>
          <input type="text" name="reason" required placeholder="예) 공급가액 착오"></div>
        <button class="btn" style="border-color:#C9A257;color:#6B4700">취소</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="ch">변경 이력
    <span style="font-weight:400;color:var(--ink3)">발행 · 재발행 · 수정 · 취소가 이전값 → 이후값과 사유로 남습니다 (원본 · 대체 · 수정 문서 포함)</span>
    <a class="btn sm" style="margin-left:auto" href="?p=activity_log&amp;module=<?= h(rawurlencode('세금계산서')) ?>&amp;kw=<?= h(rawurlencode($cur['doc_no'])) ?>">전체 작업로그</a>
  </div>
  <?php if (!$history): ?>
    <div class="empty">기록이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:130px">일시</th><th style="width:90px">담당</th><th style="width:90px">구분</th>
      <th style="width:150px">문서</th><th>내용 (이전 → 이후)</th><th style="width:180px">사유</th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $hrow): [$al, $ac] = $ACT[$hrow['action']] ?? [$hrow['action'], 'b-info']; ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h(substr((string)$hrow['created_at'], 0, 16)) ?></td>
        <td><?= h($hrow['admin_name']) ?></td>
        <td><span class="badge <?= $ac ?>"><?= h($al) ?></span></td>
        <td class="tnum" style="font-size:11.5px"><?= h((string)$hrow['ref_label']) ?></td>
        <td style="font-size:11.5px;line-height:1.5;word-break:break-all">
          <?php if ($hrow['before_value'] !== null && $hrow['before_value'] !== ''): ?>
            <div style="color:var(--ink3)">이전: <?= h(mb_substr((string)$hrow['before_value'], 0, 300)) ?></div>
          <?php endif; ?>
          <?php if ($hrow['after_value'] !== null && $hrow['after_value'] !== ''): ?>
            <div>이후: <?= h(mb_substr((string)$hrow['after_value'], 0, 300)) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px"><?= h((string)$hrow['reason']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">세금계산서를 만들 청구서
    <span style="font-weight:400;color:var(--ink3)">발행된 청구서 중 아직 안 만든 것</span>
  </div>
  <?php if (!$pending): ?>
    <div class="empty">대상 청구서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:175px">청구번호</th><th style="width:105px">청구일</th>
      <th>거래처</th><th style="width:130px">사업자번호</th>
      <th class="r" style="width:120px">과세</th><th class="r" style="width:120px">영세</th>
      <th class="r" style="width:100px">VAT</th><th style="width:230px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pending as $pd): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($pd['invoice_no']) ?></td>
        <td class="tnum"><?= h($pd['invoice_date']) ?></td>
        <td><?= h($pd['name_ko']) ?></td>
        <td class="tnum"><?= $pd['business_number'] !== null && $pd['business_number'] !== ''
             ? h($pd['business_number'])
             : '<span style="color:var(--err-fg)">없음</span>' ?></td>
        <td class="r tnum"><?= money($pd['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($pd['zero_supply']) ?></td>
        <td class="r tnum"><?= money($pd['tax_total']) ?></td>
        <td>
          <form method="post" class="f" style="gap:6px;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="create">
            <input type="hidden" name="invoice_id" value="<?= (int)$pd['id'] ?>">
            <input type="date" name="issue_date" value="<?= h(date('Y-m-d')) ?>" style="width:140px">
            <button class="btn sm pri">만들기</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>영세율과 과세가 섞인 청구서는 [만들기] 한 번에 두 장으로 나눠 만듭니다</b> —
    운송(특송 · 항공 · 해상)은 영세율 세금계산서, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관은 과세 세금계산서.
  </span></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">발행 내역
    <form class="f" method="get" style="margin-left:auto;align-items:flex-end;gap:8px">
      <input type="hidden" name="p" value="tax_invoices">
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="번호 · 거래처 · 승인번호"
             style="width:210px">
      <select name="status" style="width:120px">
        <option value="">전체</option>
        <?php foreach ($STATUS as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $sel===$k?' selected':'' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm">검색</button>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">발행한 세금계산서가 없습니다.</div>
  <?php else: ?>
  <form method="post" id="ht-form">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="hometax_xlsx">
  <div class="cb" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--line)">
    <span style="font-size:12.5px;color:var(--ink2)">작성중 · 발행(승인번호 없음) 문서를 골라 홈택스 일괄발급 엑셀로 —
      <b id="ht-n">0</b>건</span>
    <button class="btn sm pri" style="margin-left:auto" id="ht-btn" disabled>홈택스 엑셀 내려받기</button>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:36px"><input type="checkbox" id="ht-all" title="이 목록의 보낼 수 있는 것 모두"></th>
      <th style="width:165px">문서번호</th><th style="width:100px">작성일자</th>
      <th>공급받는자</th><th style="width:125px">사업자번호</th>
      <th class="c" style="width:90px">종류</th>
      <th class="r" style="width:125px">공급가액</th><th class="r" style="width:105px">세액</th>
      <th style="width:150px">승인번호</th><th class="c" style="width:85px">상태</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab,$cls] = $STATUS[$r['status']] ?? [$r['status'],'b-info']; ?>
      <tr>
        <td class="c"><?php if (in_array($r['status'], ['DRAFT', 'ISSUED'], true) && !$r['nts_approval_no']
                                && in_array($r['doc_type'], ['TAX', 'ZERO'], true)): ?>
          <input type="checkbox" class="ht-pick" name="tid[]" value="<?= (int)$r['id'] ?>"><?php endif; ?></td>
        <td class="tnum" style="font-weight:600"><?= h($r['doc_no']) ?></td>
        <td class="tnum"><?= h($r['issue_date']) ?></td>
        <td><?= h($r['buyer_name']) ?></td>
        <td class="tnum"><?= h($r['buyer_biz_no']) ?></td>
        <td class="c"><?= h(tax_doc_label((string)$r['doc_type'])) ?></td>
        <td class="r tnum"><?= money($r['supply_total']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['nts_approval_no'] ?: '-') ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span>
          <?php if ((int)($r['revision'] ?? 1) > 1): ?><div style="font-size:10.5px;color:var(--ink3)">재발행 <?= (int)$r['revision'] ?>차</div><?php endif; ?>
          <?php if (!empty($r['original_id'])): ?><div style="font-size:10.5px;color:#6B4700">수정분</div><?php endif; ?>
          <?php if (!empty($r['replaced_by'])): ?><div style="font-size:10.5px;color:var(--err-fg)">대체됨</div><?php endif; ?></td>
        <td class="c"><a class="btn sm" href="?p=tax_invoices&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </form>
  <script>
  (function () {
    var picks = document.querySelectorAll('.ht-pick'), n = document.getElementById('ht-n'), btn = document.getElementById('ht-btn');
    function upd() {
      var c = 0; picks.forEach(function (p) { if (p.checked) c++; });
      n.textContent = c; btn.disabled = c === 0;
      btn.textContent = c > 100 ? '100건까지만 됩니다' : '홈택스 엑셀 내려받기';
      if (c > 100) btn.disabled = true;
    }
    picks.forEach(function (p) { p.addEventListener('change', upd); });
    document.getElementById('ht-all').addEventListener('change', function () {
      var on = this.checked; picks.forEach(function (p) { p.checked = on; }); upd();
    });
    // 파일을 받은 뒤 화면을 새로 읽어 '뺀 것' 안내를 보여 줍니다
    document.getElementById('ht-form').addEventListener('submit', function () {
      setTimeout(function () { location.reload(); }, 2500);
    });
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">홈택스 승인번호 붙이기
    <span style="font-weight:400;color:var(--ink3)">발급 후 홈택스 목록조회 → 엑셀 내려받기 → 머리행부터 표 전체 복사 → 아래에 붙여넣기</span></div>
  <form method="post" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="hometax_match">
    <textarea name="pasted" rows="5" required style="font-family:ui-monospace,Consolas,monospace;font-size:12px"
              placeholder="작성일자&#9;승인번호&#9;…&#9;공급받는자사업자등록번호&#9;…&#9;공급가액&#9;세액&#9;…&#9;비고 (홈택스 엑셀에서 복사한 그대로)"></textarea>
    <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
      <span style="font-size:11.5px;color:var(--ink3)">비고의 ERP 문서번호로 먼저 찾고, 없으면 작성일자 · 공급받는자 번호 · 공급가액 · 세액이 모두 같은 한 건을 찾습니다.</span>
      <button class="btn pri" style="margin-left:auto">승인번호 붙이기</button>
    </div>
  </form>
</div>
<?php layout_foot();
