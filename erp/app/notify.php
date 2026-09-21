<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 알림 보내기 — 이메일(SMTP) · 카카오 알림톡(SOLAPI, 안 되면 문자로 대체).
 * 설정은 환경설정 '알림 (메일 · 카톡)' — 비밀번호 · 키는 사용자가 직접 넣습니다.
 *
 *   notify_mail($to, $subject, $text)                          → [성공?, 설명]
 *   notify_kakao($to, $text, $templateSetting, $variables)       → [성공?, 설명]
 *     · 알림톡 템플릿 ID 가 환경설정에 있으면 알림톡(ATA), 없으면 같은 글을 문자(SMS/LMS)로
 *     · 알림톡은 카카오 비즈니스 채널 + 승인된 템플릿이 있어야 합니다 (SOLAPI 에서 등록)
 */

function notify_cfg(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [];
    try {
        $st = db()->query("SELECT setting_key, setting_val FROM app_settings
                            WHERE setting_key LIKE 'smtp\\_%' OR setting_key LIKE 'solapi\\_%' OR setting_key LIKE 'notify\\_%'");
        foreach ($st->fetchAll() as $r) { $c[$r['setting_key']] = trim((string)$r['setting_val']); }
    } catch (PDOException $e) {
        // 표가 없으면 빈 설정
    }
    return $c;
}

/** 쉼표 · 줄바꿈으로 나뉜 목록 */
function notify_list(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $s) ?: []), 'strlen'));
}

/** 휴대폰 번호 숫자만 (010…) — 모양이 아니면 '' */
function notify_phone(string $s): string
{
    $d = (string)preg_replace('/\D/', '', $s);
    if (strpos($d, '82') === 0 && strlen($d) >= 11) { $d = '0' . substr($d, 2); }
    return preg_match('/^0\d{8,10}$/', $d) ? $d : '';
}

// ================================================================ 이메일 (SMTP)

/** SMTP 한 줄 읽기 (여러 줄 응답은 마지막 줄까지) */
function notify_smtp_read($fp): array
{
    $all = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $all .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') { break; }
    }
    return [(int)substr($all, 0, 3), trim($all)];
}

function notify_smtp_cmd($fp, string $cmd, array $okCodes): array
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $msg] = notify_smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException('SMTP ' . preg_replace('/\s+/', ' ', strtok($cmd, ' ')) . ' 실패: ' . mb_substr($msg, 0, 160));
    }
    return [$code, $msg];
}

function notify_mime_header(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * 메일 보내기. $to 는 주소 하나 또는 쉼표로 여러 개. 본문은 일반 글(자동으로 HTML 도 같이).
 * 465 = SSL, 587 = STARTTLS. 네이버 · 다음 · 구글 등은 '앱 비밀번호' 가 필요할 수 있습니다.
 */
function notify_mail(string $to, string $subject, string $text, string $replyTo = ''): array
{
    $c = notify_cfg();
    $host = $c['smtp_host'] ?? '';
    $port = (int)($c['smtp_port'] ?? 465) ?: 465;
    $user = $c['smtp_user'] ?? '';
    $pass = $c['smtp_pass'] ?? '';
    $from = $c['smtp_from'] ?? '' ?: $user;
    $fromName = $c['smtp_from_name'] ?? '' ?: 'GOODPOST';
    $rcpt = array_values(array_filter(notify_list($to), fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL)));
    if ($host === '' || $user === '' || $pass === '') { return [false, '메일 서버(SMTP) 설정이 비어 있음']; }
    if (!$rcpt) { return [false, '받는 메일 주소 없음']; }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) { return [false, '보내는 메일 주소가 올바르지 않음']; }

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $eno, $estr, 10,
                                STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return [false, 'SMTP 접속 실패 (' . $host . ':' . $port . ' · ' . $estr . ')']; }
    stream_set_timeout($fp, 20);
    try {
        [$code] = notify_smtp_read($fp);
        if ($code !== 220) { throw new RuntimeException('SMTP 인사 응답 이상 (' . $code . ')'); }
        $me = 'goodpost-erp';
        notify_smtp_cmd($fp, 'EHLO ' . $me, [250]);
        if ($port !== 465) {
            notify_smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS 암호화 실패');
            }
            notify_smtp_cmd($fp, 'EHLO ' . $me, [250]);
        }
        notify_smtp_cmd($fp, 'AUTH LOGIN', [334]);
        notify_smtp_cmd($fp, base64_encode($user), [334]);
        notify_smtp_cmd($fp, base64_encode($pass), [235]);
        notify_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($rcpt as $r) { notify_smtp_cmd($fp, 'RCPT TO:<' . $r . '>', [250, 251]); }
        notify_smtp_cmd($fp, 'DATA', [354]);

        $boundary = 'gp' . bin2hex(random_bytes(8));
        $html = '<div style="font-family:Malgun Gothic,Apple SD Gothic Neo,sans-serif;font-size:14px;line-height:1.7;color:#1b2733">'
              . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . notify_mime_header($fromName) . ' <' . $from . '>',
            'To: ' . implode(', ', $rcpt),
            'Subject: ' . notify_mime_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@') ?: '@goodpost', 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $headers[] = 'Reply-To: ' . $replyTo; }
        $body = implode("\r\n", $headers) . "\r\n\r\n"
              . '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "\r\n"
              . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . '--' . $boundary . "--\r\n";
        // 본문의 줄 맨 앞 '.' 은 두 개로 (SMTP 규칙) — base64 라 생기지 않지만 머리말 대비
        $body = preg_replace('/^\./m', '..', $body);
        notify_smtp_cmd($fp, $body . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [true, '메일 보냄 → ' . implode(', ', $rcpt)];
    } catch (Throwable $e) {
        @fclose($fp);
        return [false, $e->getMessage()];
    }
}

// ================================================================ 카카오 알림톡 / 문자 (SOLAPI)

/** SOLAPI 요청 (HMAC-SHA256 서명). [HTTP 코드, 응답 배열] */
function notify_solapi(string $path, array $body): array
{
    $c = notify_cfg();
    $key = $c['solapi_api_key'] ?? '';
    $secret = $c['solapi_api_secret'] ?? '';
    $date = gmdate('Y-m-d\TH:i:s\Z');
    $salt = bin2hex(random_bytes(16));
    $sig  = hash_hmac('sha256', $date . $salt, $secret);
    $ch = curl_init('https://api.solapi.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
                               'Authorization: HMAC-SHA256 apiKey=' . $key . ', date=' . $date . ', salt=' . $salt . ', signature=' . $sig],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) { $j = ['errorMessage' => $err !== '' ? $err : (string)$raw]; }
    return [$code, $j];
}

/**
 * 카톡(알림톡) 보내기 — $tplKey 는 환경설정의 템플릿 ID 항목 이름 (예 'solapi_tpl_pickup_staff').
 * 템플릿이 없거나 채널(pfId)이 없으면 $text 를 문자로 보냅니다. 알림톡이 실패해도 SOLAPI 가 문자로 대신 보냅니다.
 * $vars 는 템플릿 변수 ['#{접수번호}' => 'P…'] — 템플릿 글과 이름이 같아야 합니다.
 */
function notify_kakao(string $to, string $text, string $tplKey = '', array $vars = []): array
{
    $c = notify_cfg();
    $phones = array_values(array_filter(array_map('notify_phone', notify_list($to))));
    $from = notify_phone($c['solapi_sender'] ?? '');
    if (($c['solapi_api_key'] ?? '') === '' || ($c['solapi_api_secret'] ?? '') === '') { return [false, '카톡 · 문자(SOLAPI) 키가 비어 있음']; }
    if ($from === '') { return [false, '보내는 번호(SOLAPI 에 등록한 발신번호)가 비어 있음']; }
    if (!$phones) { return [false, '받는 휴대폰 번호 없음']; }
    if (!function_exists('curl_init')) { return [false, '서버에 curl 이 없음']; }
    $pfId = $c['solapi_pfid'] ?? '';
    $tpl  = $tplKey !== '' ? ($c[$tplKey] ?? '') : '';
    $useKakao = $pfId !== '' && $tpl !== '';
    $done = [];
    $fail = [];
    foreach ($phones as $p) {
        $m = ['to' => $p, 'from' => $from];
        if ($useKakao) {
            $m['kakaoOptions'] = ['pfId' => $pfId, 'templateId' => $tpl, 'variables' => (object)$vars, 'disableSms' => false];
            $m['text'] = $text;   // 알림톡이 안 되면 이 글로 문자 대체발송
        } else {
            $m['text'] = $text;   // 길이에 따라 SOLAPI 가 SMS · LMS 를 알아서 고름
        }
        [$code, $j] = notify_solapi('/messages/v4/send', ['message' => $m]);
        $ok = $code === 200 && empty($j['errorCode']) && (($j['statusCode'] ?? '2000') === '2000' || isset($j['messageId']));
        if ($ok) {
            $done[] = $p;
        } else {
            $fail[] = $p . ' (' . ($j['errorMessage'] ?? $j['statusMessage'] ?? ('HTTP ' . $code)) . ')';
        }
    }
    $kind = $useKakao ? '알림톡' : '문자';
    return [!$fail, ($done ? $kind . ' 보냄 → ' . implode(', ', $done) : '') . ($fail ? ' 실패: ' . implode(' / ', $fail) : '')];
}
