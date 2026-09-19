<?php
/**
 * 홈페이지 온라인 접수(픽업 예약) — 공개 입구 (로그인 없음, POST 만, JSON 응답)
 *
 *   POST /erp/pickup.php  (member/pickup.html 의 양식이 fetch 로 보냄)
 *
 * 옛 시스템에서는 픽업 접수가 공격을 받아 막아 두었던 기능입니다. 그래서 여기서는
 *   · 다른 사이트에서 보낸 요청 거부 (Origin 확인)
 *   · 사람 눈에 안 보이는 칸(website)에 뭔가 들어오면 봇으로 보고 조용히 버림
 *   · 페이지를 연 뒤 3초도 안 돼 보낸 것 거부 (봇)
 *   · 같은 IP 10분 3건 · 하루 10건, 전체 하루 200건까지
 *   · 글자 수 제한, 파일 첨부 없음, 저장은 준비된 문장(prepared)으로만
 * 저장 후 담당자 · 고객에게 메일 · 카톡을 보냅니다 (실패해도 접수는 됨 — 결과는 ERP 에 남음).
 */
declare(strict_types=1);

define('GP_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/pickups.php';
require_once APP_DIR . '/track_any.php';   // public_track_hits (조회 · 접수 횟수 기록 표)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function pk_out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pk_out(['ok' => false, 'error' => '잘못된 요청입니다.'], 405);
}
// 다른 사이트에서 보낸 요청 막기 — 브라우저가 보내는 Origin 이 이 사이트와 같아야 함
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host   = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($origin === '' || parse_url($origin, PHP_URL_HOST) !== parse_url('https://' . $host, PHP_URL_HOST)) {
    pk_out(['ok' => false, 'error' => '홈페이지에서 신청해 주세요.'], 403);
}

$in = fn(string $k, int $max) => mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($_POST[$k] ?? ''))), 0, $max);

// 봇 거르기 — 숨김 칸 · 너무 빠른 제출
if ($in('website', 100) !== '') {
    pk_out(['ok' => true, 'req_no' => 'P' . date('ymd') . '-000']);   // 봇에게는 성공처럼 보이고 저장 안 함
}
$ts = (int)($_POST['ts'] ?? 0);
if ($ts <= 0 || (int)(microtime(true) * 1000) - $ts < 3000) {
    pk_out(['ok' => false, 'error' => '잠시 후 다시 신청해 주세요.'], 400);
}

$d = [
    'pickup_type'  => in_array($_POST['type'] ?? '', ['내부픽업', '외부픽업'], true) ? $_POST['type'] : '내부픽업',
    'company_name' => $in('company', 100),
    'manager'      => $in('manager', 50),
    'phone'        => $in('tel', 30),
    'email'        => $in('email', 120),
    'pickup_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : null,
    'pickup_time'  => preg_match('/^(\d{2})시$/', (string)($_POST['hour'] ?? ''), $h) && preg_match('/^(\d{2})분$/', (string)($_POST['minute'] ?? ''), $mi)
                      ? $h[1] . ':' . $mi[1] : null,
    'area'         => $in('area', 20),
    'address'      => $in('addr', 255),
    'weight_kg'    => $in('kg', 20),
    'ship_mode'    => in_array($_POST['mode'] ?? '', ['AIR', 'SEA', 'COB'], true) ? $_POST['mode'] : null,
    'pay_terms'    => in_array($_POST['pay'] ?? '', ['Prepaid', 'Collect'], true) ? $_POST['pay'] : null,
    'memo'         => $in('memo', 2000),
];
if (($_POST['agree'] ?? '') !== '1') { pk_out(['ok' => false, 'error' => '개인정보 수집 · 이용에 동의해 주세요.'], 400); }
if ($d['company_name'] === '' || $d['manager'] === '' || $d['phone'] === '' || $d['address'] === '') {
    pk_out(['ok' => false, 'error' => '회사명 · 담당자 · 연락처 · 주소는 꼭 적어 주세요.'], 400);
}
if (!preg_match('/^[0-9+\-() ]{8,30}$/', $d['phone'])) { pk_out(['ok' => false, 'error' => '연락처를 확인해 주세요.'], 400); }
if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { pk_out(['ok' => false, 'error' => '이메일 주소를 확인해 주세요.'], 400); }
if ($d['pickup_date'] !== null && ($d['pickup_date'] < date('Y-m-d') || $d['pickup_date'] > date('Y-m-d', strtotime('+90 days')))) {
    pk_out(['ok' => false, 'error' => '픽업일자를 확인해 주세요 (오늘부터 90일 안).'], 400);
}

try {
    $pdo = db();
    track_ensure_tables($pdo);
    pickup_ensure_table($pdo);
    $xff = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    $ip  = substr((string)($_SERVER['HTTP_X_REAL_IP'] ?? '') ?: ($xff ?: (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    $st = $pdo->prepare("SELECT SUM(hit_at > NOW() - INTERVAL 10 MINUTE), COUNT(*) FROM public_track_hits
                          WHERE ip = ? AND src = 'pickup' AND hit_at > NOW() - INTERVAL 1 DAY");
    $st->execute([$ip]);
    [$n10, $nDay] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
    $all = (int)$pdo->query("SELECT COUNT(*) FROM public_track_hits WHERE src = 'pickup' AND hit_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
    if ($n10 >= 3 || $nDay >= 10 || $all >= 200) {
        pk_out(['ok' => false, 'error' => '신청이 너무 많습니다. 전화(02-6929-0666)나 카카오톡으로 문의해 주세요.'], 429);
    }
    $pdo->prepare("INSERT INTO public_track_hits (ip, src, hit_at) VALUES (?, 'pickup', NOW())")->execute([$ip]);

    $pdo->beginTransaction();
    $no = pickup_next_no($pdo);
    $pdo->prepare('INSERT INTO web_pickups
                     (req_no, pickup_type, company_name, manager, phone, email, pickup_date, pickup_time, area, address,
                      weight_kg, ship_mode, pay_terms, memo, ip, user_agent)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$no, $d['pickup_type'], $d['company_name'], $d['manager'], $d['phone'], $d['email'] ?: null,
                   $d['pickup_date'], $d['pickup_time'], $d['area'] ?: null, $d['address'], $d['weight_kg'] ?: null,
                   $d['ship_mode'], $d['pay_terms'], $d['memo'] ?: null, $ip,
                   mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('온라인 접수 저장 실패: ' . $e->getMessage());
    pk_out(['ok' => false, 'error' => '접수하지 못했습니다. 전화(02-6929-0666)로 문의해 주세요.'], 500);
}

// 응답을 먼저 돌려주고 알림은 그 뒤에 (메일 서버가 느려도 고객이 기다리지 않게)
$resp = json_encode(['ok' => true, 'req_no' => $no], JSON_UNESCAPED_UNICODE);
ignore_user_abort(true);
header('Content-Length: ' . strlen($resp));
header('Connection: close');
echo $resp;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}
try {
    $st = $pdo->prepare('SELECT * FROM web_pickups WHERE id = ?');
    $st->execute([$id]);
    if ($p = $st->fetch()) { pickup_notify_new($pdo, $p); }
    // 개인정보 보관 1년 (홈페이지 동의 문구와 같음) — 가끔 한 번씩 지난 것을 지웁니다
    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM web_pickups WHERE created_at < NOW() - INTERVAL 1 YEAR');
    }
} catch (Throwable $e) {
    error_log('온라인 접수 알림 실패: ' . $e->getMessage());
}
exit;
