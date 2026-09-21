<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/hawb_docs.php';

/**
 * 수입 서류 내려받기 — 받은 엑셀 양식 그대로.
 *   ?type=en&batch=1   영문 통관목록
 *   ?type=cn&batch=1   중문 적하목록
 *   ?type=inv&id=5     COMMERCIAL INVOICE (송장 한 건)
 */

hawb_ensure_table();

$eid  = entity_id();
$type = query('type', 'en');
$safe = static fn (string $s): string => preg_replace('/[^A-Za-z0-9._-]/', '', $s) ?: 'file';

if ($type === 'inv') {
    $h = hawb_one_load((int)query('id', '0'), $eid);
    if (!$h) { exit('송장을 찾을 수 없습니다.'); }
    $b = [];
    if ($h['batch_id']) {
        $st = db()->prepare('SELECT * FROM hawb_batches WHERE id = ? AND business_entity_id = ?');
        $st->execute([(int)$h['batch_id'], $eid]);
        $b = $st->fetch() ?: [];
    }
    $st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
    $st->execute([$eid]);
    $be = $st->fetch() ?: [];

    log_action('물류', 'PRINT', 'hawbs', (int)$h['id'], (string)$h['house_no'], null, 'INVOICE 내려받기');
    hawb_send_xlsx(hawb_xlsx_invoice($h, $b, $be), 'INVOICE_' . $safe((string)$h['house_no']) . '.xlsx');
    exit;
}

$b = hawb_batch_load((int)query('batch', '0'), $eid);
if (!$b) { exit('항공편을 찾을 수 없습니다.'); }
if (!$b['hawbs']) { exit('이 항공편에는 송장이 없습니다. 송장을 먼저 넣으세요.'); }

$tag = $safe((string)($b['flight_no'] ?: 'FLT')) . '_' . $safe((string)$b['flight_date']);

if ($type === 'cn') {
    log_action('물류', 'PRINT', 'hawb_batches', (int)$b['id'], (string)$b['flight_no'], null,
               '중문 적하목록 내려받기 ' . count($b['hawbs']) . '건');
    hawb_send_xlsx(hawb_xlsx_cn($b), '중문_적하목록_' . $tag . '.xlsx');
    exit;
}

log_action('물류', 'PRINT', 'hawb_batches', (int)$b['id'], (string)$b['flight_no'], null,
           '영문 통관목록 내려받기 ' . count($b['hawbs']) . '건');
hawb_send_xlsx(hawb_xlsx_en($b), '영문_통관목록_' . $tag . '.xlsx');
