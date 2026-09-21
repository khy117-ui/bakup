<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/xlsx.php';

/**
 * 홈택스 전자세금계산서 일괄발급 엑셀 — 국세청 양식 "엑셀 업로드 양식(전자세금계산서-일반(영세율)) - 100건 이하"
 *   · 1~6행은 양식 그대로 (hometax_header.json — 받은 양식에서 그대로 뽑음), 7행부터 한 줄에 세금계산서 한 장
 *   · 59칸: 종류(01 일반 · 02 영세율) · 작성일자(YYYYMMDD) · 공급자 10칸 · 공급받는자 9칸 · 합계 2칸 · 비고
 *           · 품목 4묶음(일자 2자리 · 품목 · 규격 · 수량 · 단가 · 공급가액 · 세액 · 품목비고) · 현금 · 수표 · 어음 · 외상미수금 · 영수(01)/청구(02)
 *   · 한 파일 100건까지 (발급은 홈택스에서 50건씩)
 *   · 계산서(면세)는 양식이 달라 여기서 빼고 알려 줍니다
 * 비고 칸에 ERP 문서번호를 넣어 두어, 발급 뒤 홈택스 목록을 붙여 넣으면 승인번호를 되찾아 붙입니다.
 */

const HT_MAX_ROWS = 100;

/** 한글 2byte · 영문 숫자 1byte 기준으로 자릅니다 (양식 '항목설명' 의 길이 규칙) */
function ht_cut(?string $s, int $maxBytes): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
    $out = '';
    $n = 0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $w = strlen($ch) === 1 ? 1 : 2;
        if ($n + $w > $maxBytes) { break; }
        $out .= $ch;
        $n += $w;
    }
    return $out;
}

function ht_digits(?string $s): string
{
    return (string)preg_replace('/\D/', '', (string)$s);
}

/** 금액 — 원 단위 정수 문자열 */
function ht_amt($v): string
{
    return (string)(int)round((float)$v);
}

/**
 * 세금계산서들을 양식 행으로. 돌려주는 값: [행 목록, 건너뛴 것 [문서번호 => 이유], 담은 id 목록]
 */
function hometax_rows(PDO $pdo, int $eid, array $ids): array
{
    $st = $pdo->prepare('SELECT * FROM business_entities WHERE id = ?');
    $st->execute([$eid]);
    $sup = $st->fetch();
    if (!$sup) { throw new RuntimeException('사업자 정보를 찾을 수 없습니다.'); }
    $supNo = ht_digits($sup['business_number']);
    if (strlen($supNo) !== 10 || trim((string)$sup['representative']) === '') {
        throw new RuntimeException('우리 회사 사업자등록번호 · 대표자가 비어 있거나 틀립니다. [사업자 관리] 에서 먼저 채우세요.');
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return [[], [], []]; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT t.*, i.status AS inv_status, i.balance AS inv_balance
                           FROM tax_invoices t LEFT JOIN invoices i ON i.id = t.invoice_id
                          WHERE t.id IN ($ph) AND t.business_entity_id = ? AND t.deleted_at IS NULL
                          ORDER BY t.issue_date, t.id");
    $st->execute(array_merge($ids, [$eid]));
    $docs = $st->fetchAll();
    $itemSt = $pdo->prepare('SELECT * FROM tax_invoice_items WHERE tax_invoice_id = ? ORDER BY line_no');

    $rows = [];
    $skip = [];
    $used = [];
    foreach ($docs as $t) {
        $no = (string)$t['doc_no'];
        if (!in_array($t['status'], ['DRAFT', 'ISSUED'], true) || $t['nts_approval_no']) {
            $skip[$no] = '이미 전송했거나 취소된 문서';
            continue;
        }
        if (!in_array($t['doc_type'], ['TAX', 'ZERO'], true)) {
            $skip[$no] = tax_doc_label((string)$t['doc_type']) . '는 이 양식으로 발급할 수 없습니다 (전자계산서 양식 따로)';
            continue;
        }
        $buyerNo = ht_digits($t['buyer_biz_no']);
        if (!in_array(strlen($buyerNo), [10, 13], true)) {
            $skip[$no] = '공급받는자 사업자등록번호가 10자리가 아님 (' . $t['buyer_biz_no'] . ')';
            continue;
        }
        if (trim((string)$t['buyer_name']) === '' || trim((string)$t['buyer_rep']) === '') {
            $skip[$no] = '공급받는자 상호 · 대표자(성명)가 비어 있음 — 거래처 정보를 채운 뒤 세금계산서를 다시 만드세요';
            continue;
        }
        if (count($rows) >= HT_MAX_ROWS) {
            $skip[$no] = '한 파일 ' . HT_MAX_ROWS . '건 초과 — 다음 파일로';
            continue;
        }

        $date = (string)$t['issue_date'];
        $ym   = substr($date, 0, 7);
        $zero = $t['doc_type'] === 'ZERO';
        $itemSt->execute([(int)$t['id']]);
        $items = $itemSt->fetchAll();

        // 품목은 4줄까지 — 넘으면 한 줄로 합칩니다 ("핸드링차지 외 12건"). 일자는 작성 월 안의 날만 (아니면 작성일)
        $lines = [];
        if (count($items) <= 4) {
            foreach ($items as $it) {
                $d = (string)($it['supply_date'] ?? '');
                $lines[] = [substr($d, 0, 7) === $ym ? substr($d, 8, 2) : substr($date, 8, 2),
                            (string)$it['item_name'], (string)($it['spec'] ?? ''),
                            (float)$it['supply_amount'], $zero ? 0.0 : (float)$it['tax_amount'], ''];
            }
        } else {
            $first = trim((string)preg_replace('/\s+\S+$/u', '', (string)$items[0]['item_name'])) ?: '국제운송';
            $lines[] = [substr($date, 8, 2), $first . ' 외 ' . (count($items) - 1) . '건', $zero ? '영세율' : '과세',
                        array_sum(array_column($items, 'supply_amount')),
                        $zero ? 0.0 : array_sum(array_column($items, 'tax_amount')),
                        'AWB ' . count($items) . '건 · 명세는 청구서 참조'];
        }
        $supplyTotal = array_sum(array_column($lines, 3));
        $taxTotal    = array_sum(array_column($lines, 4));
        $paid = ($t['inv_status'] ?? '') === 'PAID' || (isset($t['inv_balance']) && (float)$t['inv_balance'] <= 0.0 && $t['inv_status'] !== null);

        $r = array_fill(0, 59, '');
        $r[0]  = $zero ? '02' : '01';
        $r[1]  = str_replace('-', '', $date);
        $r[2]  = $supNo;
        $r[4]  = ht_cut($sup['name_ko'], 70);
        $r[5]  = ht_cut($sup['representative'], 30);
        $r[6]  = ht_cut($sup['address_ko'], 150);
        $r[7]  = ht_cut($sup['business_type'], 40);
        $r[8]  = ht_cut($sup['business_item'], 60);
        $r[9]  = ht_cut($sup['email'], 40);
        $r[10] = $buyerNo;
        $r[12] = ht_cut($t['buyer_name'], 70);
        $r[13] = ht_cut($t['buyer_rep'], 30);
        $r[14] = ht_cut($t['buyer_address'], 150);
        $r[15] = ht_cut($t['buyer_biz_type'], 40);
        $r[16] = ht_cut($t['buyer_biz_item'], 60);
        $r[17] = ht_cut($t['buyer_email'], 40);
        $r[19] = ht_amt($supplyTotal);
        $r[20] = ht_amt($taxTotal);
        $r[21] = ht_cut('ERP ' . $no . ($t['remark'] ? ' ' . $t['remark'] : ''), 150);   // 승인번호 되찾기용
        foreach (array_slice($lines, 0, 4) as $k => [$day, $name, $spec, $amt, $tax, $memo]) {
            $b = 22 + $k * 8;
            $r[$b]     = str_pad((string)(int)$day, 2, '0', STR_PAD_LEFT);
            $r[$b + 1] = ht_cut($name, 100);
            $r[$b + 2] = ht_cut($spec, 60);
            $r[$b + 5] = ht_amt($amt);
            $r[$b + 6] = ht_amt($tax);
            $r[$b + 7] = ht_cut($memo, 100);
        }
        $r[58] = $paid ? '01' : '02';   // 영수(대가를 받음) / 청구(아직 못 받음)
        $rows[] = $r;
        $used[] = (int)$t['id'];
    }
    return [$rows, $skip, $used];
}

/** 양식 그대로의 xlsx (1~6행 머리말 + 7행부터 자료) */
function hometax_xlsx(array $rows): string
{
    $head = json_decode((string)file_get_contents(APP_DIR . '/hometax_header.json'), true);
    if (!is_array($head) || count($head) !== 6 || count($head[5]) !== 59) {
        throw new RuntimeException('홈택스 양식 머리말 파일(app/hometax_header.json)이 없거나 잘못되었습니다.');
    }
    $widths = array_fill(0, 59, 12);
    $widths[12] = $widths[14] = $widths[23] = 24;
    return xlsx_build('엑셀업로드양식', array_merge($head, $rows), $widths, [0, 1, 2, 3, 4, 5]);
}

/**
 * 홈택스 발급 목록(엑셀에서 복사해 붙여 넣은 표)으로 승인번호를 찾아 붙입니다.
 *   머리행에서 승인번호 · 작성일자 · 공급받는자 등록번호 · 공급가액 · 세액 · 비고 칸을 이름으로 찾습니다.
 *   ① 비고에 'ERP 문서번호' 가 있으면 그 문서 ② 없으면 작성일자 + 공급받는자 번호 + 공급가액 + 세액이 한 건만 맞을 때
 * 돌려주는 값: ['matched' => [[문서번호, 승인번호]], 'unmatched' => [줄 설명], 'error' => '']
 */
function hometax_match_approvals(PDO $pdo, int $eid, string $pasted): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($pasted)) ?: [];
    $head = null;
    $col = [];
    $res = ['matched' => [], 'unmatched' => [], 'error' => ''];
    $norm = fn(string $s) => (string)preg_replace('/\s+/u', '', $s);
    foreach ($lines as $i => $line) {
        $cells = explode("\t", $line);
        if ($head === null) {
            $n = array_map($norm, $cells);
            foreach ($n as $k => $h) {
                if (!isset($col['appr']) && strpos($h, '승인번호') !== false) { $col['appr'] = $k; }
                elseif (!isset($col['date']) && strpos($h, '작성일') !== false) { $col['date'] = $k; }
                elseif (!isset($col['buyer']) && strpos($h, '공급받는자') !== false && strpos($h, '등록번호') !== false) { $col['buyer'] = $k; }
                elseif (!isset($col['supply']) && $h === '공급가액') { $col['supply'] = $k; }
                elseif (!isset($col['tax']) && $h === '세액') { $col['tax'] = $k; }
                elseif (!isset($col['memo']) && $h === '비고') { $col['memo'] = $k; }
            }
            if (isset($col['appr'])) { $head = $i; }
            continue;
        }
        $v = fn(string $k) => isset($col[$k]) ? trim((string)($cells[$col[$k]] ?? '')) : '';
        $appr = (string)preg_replace('/[^0-9A-Za-z-]/', '', $v('appr'));
        if ($appr === '') { continue; }

        $t = null;
        if (preg_match('/ERP\s*([A-Z0-9-]+)/', $v('memo'), $m)) {
            $st = $pdo->prepare("SELECT id, doc_no, nts_approval_no FROM tax_invoices
                                  WHERE business_entity_id = ? AND doc_no = ? AND deleted_at IS NULL");
            $st->execute([$eid, $m[1]]);
            $t = $st->fetch() ?: null;
        }
        if (!$t) {
            $d = ht_digits($v('date'));
            $date = strlen($d) >= 8 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : '';
            $st = $pdo->prepare("SELECT id, doc_no, nts_approval_no FROM tax_invoices
                                  WHERE business_entity_id = ? AND deleted_at IS NULL AND status IN ('DRAFT','ISSUED')
                                    AND issue_date = ? AND REPLACE(buyer_biz_no, '-', '') = ?
                                    AND ROUND(supply_total) = ? AND ROUND(tax_total) = ?");
            $st->execute([$eid, $date, ht_digits($v('buyer')), (int)ht_digits($v('supply')), (int)ht_digits($v('tax'))]);
            $found = $st->fetchAll();
            $t = count($found) === 1 ? $found[0] : null;
        }
        if (!$t) {
            $res['unmatched'][] = $appr . ' (' . $v('date') . ' · ' . $v('buyer') . ' · ' . $v('supply') . ')';
            continue;
        }
        if ($t['nts_approval_no'] && $t['nts_approval_no'] !== $appr) {
            $res['unmatched'][] = $appr . ' — ' . $t['doc_no'] . ' 에 이미 다른 승인번호';
            continue;
        }
        $pdo->prepare("UPDATE tax_invoices SET nts_approval_no = ?, status = 'SENT', sent_at = COALESCE(sent_at, NOW())
                        WHERE id = ?")->execute([$appr, (int)$t['id']]);
        log_action('세금계산서', 'ISSUE', 'tax_invoices', (int)$t['id'], (string)$t['doc_no'], null,
                   '홈택스 승인번호 ' . $appr . ' (목록 붙여넣기)');
        $res['matched'][] = [$t['doc_no'], $appr];
    }
    if ($head === null) {
        $res['error'] = '머리행(승인번호 · 작성일자 …)을 찾지 못했습니다. 홈택스 목록 엑셀에서 머리행까지 같이 복사해 붙여 넣으세요.';
    }
    return $res;
}
