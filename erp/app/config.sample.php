<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
// 이 파일을 config.local.php 로 복사해서 값을 채우세요.
// config.local.php 는 배포본에 넣지 않습니다.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'goodpost',
        'user'    => 'gp_app',
        'pass'    => '',          // ← 여기에만 적습니다
    ],
    // 기본 사업자 코드. business_entities.code 값
    'entity_code' => 'GPA',
    // 로그인 실패 허용 횟수와 잠금 시간(분)
    'login_max_fail' => 5,
    'login_lock_min' => 10,
    // NAS 접속 비밀번호 같은 값을 암호화할 열쇠. 32자 이상 아무 임의 문자열.
    // 한 번 정하면 바꾸지 마세요 — 바꾸면 이미 저장된 비밀값을 못 읽습니다.
    'app_key' => '',
    // 화면에 표시할 시스템 이름
    'app_name' => 'GOODPOST 통합 업무관리 시스템',
];
