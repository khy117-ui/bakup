<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/hawb.php';

/** HAWB 인쇄 — A4 한 장에 한 건. ?ids=1,2,3 으로 여러 건을 이어서 뽑습니다 */

hawb_ensure_table();

$eid = entity_id();
$batch = (int)query('batch', '0');
if ($batch > 0) {
    // 항공편 하나의 송장을 모두 (적하목록 순서대로)
    $st = db()->prepare('SELECT h.id FROM hawbs h
                           JOIN hawb_batches b ON b.id = h.batch_id AND b.business_entity_id = ?
                          WHERE h.batch_id = ? AND h.deleted_at IS NULL ORDER BY h.house_no, h.id');
    $st->execute([$eid, $batch]);
    $_GET['ids'] = implode(',', $st->fetchAll(PDO::FETCH_COLUMN));
}
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)query('ids', ''))))));
$ids = array_slice($ids, 0, 100);
if (!$ids) { exit('인쇄할 HAWB 를 고르세요.'); }

$ph = implode(',', array_fill(0, count($ids), '?'));
$st = db()->prepare("SELECT * FROM hawbs WHERE id IN ($ph) AND business_entity_id = ? AND deleted_at IS NULL
                      ORDER BY FIELD(id, $ph)");
$st->execute(array_merge($ids, [$eid], $ids));
$list = $st->fetchAll();
if (!$list) { exit('HAWB 를 찾을 수 없습니다.'); }

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch() ?: [];

$logo = hawb_logo_src($be);

// 몇 번 뽑았는지 남겨 둡니다 (재발행 확인용)
try {
    db()->prepare("UPDATE hawbs SET print_count = print_count + 1, printed_at = NOW() WHERE id IN ($ph)")
        ->execute($ids);
} catch (PDOException $e) {
    error_log('HAWB 인쇄 기록 실패: ' . $e->getMessage());
}
log_action('물류', 'PRINT', 'hawbs', (int)$list[0]['id'], (string)$list[0]['house_no'], null,
           'HAWB 인쇄 ' . count($list) . '건');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>HAWB <?= h((string)$list[0]['house_no']) ?><?= count($list) > 1 ? ' 외 ' . (count($list) - 1) . '건' : '' ?></title>
<style>
<?= hawb_print_css() ?>
  .bar { position: sticky; top: 0; display: flex; gap: 8px; align-items: center;
         background: #0B4F6C; color: #fff; padding: 8px 14px; font-size: 13px; }
  .bar button { font: inherit; padding: 5px 14px; border: 0; border-radius: 6px; cursor: pointer;
                background: #fff; color: #0B4F6C; font-weight: 700; }
</style>
</head>
<body>
<div class="bar noprint">
  <b>HAWB <?= count($list) ?>건</b>
  <span style="opacity:.85">A4 세로 · 한 장에 한 건 · PDF 로 저장하려면 인쇄에서 'PDF로 저장'</span>
  <button onclick="window.print()" style="margin-left:auto">인쇄</button>
  <button onclick="window.close()">닫기</button>
</div>
<?php foreach ($list as $h): ?>
  <div class="sheet"><?= hawb_render_form($h, $logo, $be) ?></div>
<?php endforeach; ?>
</body>
</html>
