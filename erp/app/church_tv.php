<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 교회 TV 화면 (시온의빛교회 1층 · 3층 모니터 테스트용, 2026-10-09)
 *
 *   · 관리: ERP > 시스템 > 교회 TV 화면 (?p=church_tv)
 *   · 송출: /erp/tv.php?s=1f  (로그인 없음. TV 스틱 브라우저 시작 주소로 넣습니다)
 *
 * ERP 업무 표와 섞이지 않게 표 이름을 모두 church_tv_ 로 시작합니다.
 * 사진은 영속 폴더(/app/user_data/church_tv) 에 두고 tv.php?img= 로만 내줍니다.
 */

const CTV_TRANSITIONS = ['fade' => '겹쳐 바뀌기', 'slide' => '옆으로 밀기', 'zoom' => '살짝 커지며'];
const CTV_FONT_SIZES  = ['normal' => '보통', 'large' => '크게'];
const CTV_BOX_POS     = ['auto' => '장마다 바뀜 (잔상 방지)', 'left' => '왼쪽', 'center' => '가운데', 'right' => '오른쪽'];
const CTV_THEMES      = ['morning' => '아침빛 (노랑)', 'navy' => '남색', 'green' => '초록', 'plum' => '보라'];
const CTV_IMG_EXT     = ['jpg', 'jpeg', 'png', 'webp'];

/** 표가 없으면 만들고, 처음 한 번 1층 · 3층 화면과 예시 슬라이드를 넣습니다 */
function ctv_ensure_tables(): void
{
    static $done = false;
    if ($done) { return; }
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS church_tv_screens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(50) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        seconds SMALLINT NOT NULL DEFAULT 10,
        transition VARCHAR(10) NOT NULL DEFAULT 'fade',
        font_size VARCHAR(10) NOT NULL DEFAULT 'normal',
        box_pos VARCHAR(10) NOT NULL DEFAULT 'auto',
        show_clock TINYINT(1) NOT NULL DEFAULT 1,
        on_from CHAR(5) NOT NULL DEFAULT '00:00',
        on_to CHAR(5) NOT NULL DEFAULT '24:00',
        version INT NOT NULL DEFAULT 1,
        sort_no INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS church_tv_slides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(40) NOT NULL DEFAULT '',
        title VARCHAR(120) NOT NULL,
        body VARCHAR(600) NOT NULL DEFAULT '',
        date_text VARCHAR(40) NOT NULL DEFAULT '',
        image_file VARCHAR(120) NULL,
        theme VARCHAR(10) NOT NULL DEFAULT 'morning',
        seconds SMALLINT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        start_date DATE NULL,
        end_date DATE NULL,
        sort_no INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS church_tv_slide_screens (
        slide_id INT NOT NULL,
        screen_id INT NOT NULL,
        PRIMARY KEY (slide_id, screen_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ((int)$pdo->query('SELECT COUNT(*) FROM church_tv_screens')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO church_tv_screens (slug, name, sort_no, font_size) VALUES (?,?,?,?)');
        $ins->execute(['1f', '1층 모니터', 1, 'normal']);
        $ins->execute(['3f', '3층 모니터', 2, 'large']);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM church_tv_slides')->fetchColumn() === 0) {
        ctv_seed($pdo);
    }
    $done = true;
}

/** 예시 슬라이드 3장 (관리자 화면에서 고치거나 지우면 됩니다) */
function ctv_seed(PDO $pdo): void
{
    $ids = $pdo->query('SELECT slug, id FROM church_tv_screens')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [
        ['선교소식 · 인도네시아', '받은 복을 나누는 동역자로 자라는 학생들',
         '기도제목: 학생들이 배운 것으로 이웃을 섬기는 일꾼으로 자라도록', '2026년 9월', 'asset:church.jpg', 'morning', ['1f', '3f']],
        ['선교소식 · 몽골', '새 학기를 맞은 몽골 청년들을 위해',
         '기도제목: 청년들이 말씀 안에서 새 학기를 시작하도록', '2026년 9월', null, 'navy', ['1f', '3f']],
        ['교회 안내', '처음 오신 분을 환영합니다',
         '예배 후 안내 데스크에서 새가족 등록을 도와드립니다', '', 'asset:church.jpg', 'green', ['1f']],
    ];
    $ins = $pdo->prepare('INSERT INTO church_tv_slides (label, title, body, date_text, image_file, theme, sort_no)
                          VALUES (?,?,?,?,?,?,?)');
    $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
    foreach ($rows as $i => $r) {
        $ins->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $i + 1]);
        $sid = (int)$pdo->lastInsertId();
        foreach ($r[6] as $slug) {
            if (isset($ids[$slug])) { $link->execute([$sid, (int)$ids[$slug]]); }
        }
    }
}

/** 사진 보관 폴더 (재배포해도 남는 /app/user_data 우선) */
function ctv_image_dir(): string
{
    $base = dirname(storage_root());          // …/user_data 또는 …/storage
    $dir = $base . DIRECTORY_SEPARATOR . 'church_tv';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "# 교회 TV 사진 — tv.php?img= 로만 내줍니다\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n");
    }
    return $dir;
}

/** 'HH:MM' 형식만 받습니다. 24:00 은 하루 끝 */
function ctv_clean_time(string $t, string $def): string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $t) ? $t : $def;
}

/** 지금 이 화면을 켜 둘 시간인지 (22:00~07:00 처럼 자정을 넘는 것도 됩니다) */
function ctv_is_on(array $scr, ?DateTimeImmutable $now = null): bool
{
    if (!(int)$scr['is_active']) { return false; }
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    $cur = $now->format('H:i');
    $from = (string)$scr['on_from'];
    $to = (string)$scr['on_to'];
    if ($from === $to) { return true; }
    return $from < $to ? ($cur >= $from && $cur < $to) : ($cur >= $from || $cur < $to);
}

function ctv_screen_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM church_tv_screens WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** 이 화면에 지금 나갈 슬라이드 (켜짐 · 기간 안 · 순서대로) */
function ctv_slides_for(int $screenId): array
{
    $st = db()->prepare("SELECT s.* FROM church_tv_slides s
                           JOIN church_tv_slide_screens l ON l.slide_id = s.id AND l.screen_id = ?
                          WHERE s.is_active = 1
                            AND (s.start_date IS NULL OR s.start_date <= CURDATE())
                            AND (s.end_date IS NULL OR s.end_date >= CURDATE())
                          ORDER BY s.sort_no, s.id");
    $st->execute([$screenId]);
    return $st->fetchAll();
}

/** TV 가 1분마다 받아 가는 내용 */
function ctv_feed(array $scr): array
{
    $slides = [];
    foreach (ctv_slides_for((int)$scr['id']) as $s) {
        $img = null;
        if ($s['image_file']) {
            $img = str_starts_with((string)$s['image_file'], 'asset:')
                ? 'tv.php?a=' . rawurlencode(substr((string)$s['image_file'], 6))
                : 'tv.php?img=' . (int)$s['id'] . '&v=' . rawurlencode((string)($s['updated_at'] ?? $s['created_at']));
        }
        $slides[] = [
            'id'      => (int)$s['id'],
            'label'   => (string)$s['label'],
            'title'   => (string)$s['title'],
            'body'    => (string)$s['body'],
            'date'    => (string)$s['date_text'],
            'img'     => $img,
            'theme'   => (string)$s['theme'],
            'seconds' => $s['seconds'] !== null ? (int)$s['seconds'] : null,
        ];
    }
    return [
        'ok'      => true,
        'version' => (int)$scr['version'],
        'on'      => ctv_is_on($scr),
        'screen'  => [
            'name'       => (string)$scr['name'],
            'seconds'    => max(4, (int)$scr['seconds']),
            'transition' => (string)$scr['transition'],
            'font'       => (string)$scr['font_size'],
            'box'        => (string)$scr['box_pos'],
            'clock'      => (int)$scr['show_clock'] === 1,
        ],
        'slides'  => $slides,
    ];
}

/** 화면 설정이 바뀌면 TV 가 새로고침하도록 번호를 올립니다 */
function ctv_bump(?int $screenId = null): void
{
    if ($screenId === null) {
        db()->exec('UPDATE church_tv_screens SET version = version + 1');
    } else {
        db()->prepare('UPDATE church_tv_screens SET version = version + 1 WHERE id = ?')->execute([$screenId]);
    }
}

/** 이 서버에서 tv.php 를 여는 주소 (관리 화면에 보여 줄 것) */
function ctv_public_url(string $slug): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/erp/index.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/tv.php?s=' . rawurlencode($slug);
}
