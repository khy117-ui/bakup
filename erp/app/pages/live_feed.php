<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 실시간 알림 신호 — 홈페이지 온라인 접수(새 접수) · Q&A(답변 대기).
 * ERP 화면(layout 의 스크립트)이 30초마다 부릅니다. 볼 권한이 있는 것만 내려줍니다.
 *   { pickups: {new: 2, max_id: 15, items: [...]}, qna: {wait: 1, max_id: 42, items: [...]} }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();   // 다른 화면이 이 요청을 기다리지 않게

$out = [];

// 삭제 승인 대기 — 승인 권한이 있는 사람에게만 (화면 위 알림줄에 씁니다)
if (can('sys.delete.approve')) {
    try {
        $st = db()->query("SELECT id, route, target_label, reason, requested_name, requested_at
                             FROM delete_requests WHERE status = 'PENDING'
                            ORDER BY requested_at DESC, id DESC LIMIT 5");
        $rows = $st->fetchAll();
        $cnt = (int)db()->query("SELECT COUNT(*) FROM delete_requests WHERE status = 'PENDING'")->fetchColumn();
        $out['delreq'] = [
            'wait'   => $cnt,
            'max_id' => $rows ? (int)$rows[0]['id'] : 0,
            'items'  => array_map(static fn ($r) => [
                'id'    => (int)$r['id'],
                'title' => (string)$r['target_label'],
                'sub'   => ($r['requested_name'] ?: '') . ($r['reason'] ? ' · ' . $r['reason'] : ''),
                'route' => (string)$r['route'],
                'at'    => (string)$r['requested_at'],
                'url'   => '?p=delete_requests',
            ], $rows),
        ];
    } catch (PDOException $e) {
        // 표가 아직 없으면 조용히 넘어갑니다
    }
}
$pdo = db();
if (route_can_view('web_pickups')) {
    try {
        $new = (int)$pdo->query("SELECT COUNT(*) FROM web_pickups WHERE status = 'NEW'")->fetchColumn();
        $max = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM web_pickups')->fetchColumn();
        $items = [];
        foreach ($pdo->query("SELECT id, req_no, company_name, manager, pickup_date, pickup_time, status, created_at
                                FROM web_pickups ORDER BY (status = 'NEW') DESC, id DESC LIMIT 8")->fetchAll() as $r) {
            $items[] = ['id' => (int)$r['id'], 'no' => $r['req_no'], 'title' => $r['company_name'],
                        'sub' => $r['manager'] . ' · 픽업 ' . trim(($r['pickup_date'] ?? '') . ' ' . ($r['pickup_time'] ?? '')),
                        'status' => $r['status'], 'at' => substr((string)$r['created_at'], 0, 16),
                        'url' => '?p=web_pickups&id=' . (int)$r['id']];
        }
        $out['pickups'] = ['new' => $new, 'max_id' => $max, 'items' => $items];
    } catch (PDOException $e) {
        $out['pickups'] = ['new' => 0, 'max_id' => 0, 'items' => []];
    }
}
if (route_can_view('boards')) {
    try {
        $wait = (int)$pdo->query("SELECT COUNT(*) FROM board_post WHERE board = 'qna' AND answer IS NULL")->fetchColumn();
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM board_post WHERE board = 'qna'")->fetchColumn();
        $items = [];
        foreach ($pdo->query("SELECT id, title, writer, created_at, answer IS NULL AS waiting FROM board_post
                               WHERE board = 'qna' ORDER BY (answer IS NULL) DESC, id DESC LIMIT 8")->fetchAll() as $r) {
            $items[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'sub' => $r['writer'],
                        'status' => (int)$r['waiting'] ? 'WAIT' : 'DONE', 'at' => substr((string)$r['created_at'], 0, 16),
                        'url' => '?p=boards&b=qna&id=' . (int)$r['id']];
        }
        $out['qna'] = ['wait' => $wait, 'max_id' => $max, 'items' => $items];
    } catch (PDOException $e) {
        $out['qna'] = ['wait' => 0, 'max_id' => 0, 'items' => []];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
