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
 * 사진 · 영상은 영속 폴더(/app/user_data/church_tv) 에 두고 tv.php?img= 로만 내줍니다.
 * 제목을 비운 슬라이드는 글 상자 없이 사진 · 영상만 화면 가득 보여 줍니다.
 * 유튜브 링크는 영상 번호(youtube 칸)만 저장하고 TV 에서 유튜브 플레이어로 틉니다.
 * 묶음(church_tv_groups, 예: 인도네시아 선교) 안의 슬라이드는 날짜(post_date)별로 모아 최근 날짜부터 나옵니다.
 */

const CTV_TRANSITIONS = ['fade' => '겹쳐 바뀌기', 'slide' => '옆으로 밀기', 'zoom' => '살짝 커지며'];
const CTV_FONT_SIZES  = ['normal' => '보통', 'large' => '크게'];
const CTV_BOX_POS     = ['auto' => '장마다 바뀜 (잔상 방지)', 'left' => '왼쪽', 'center' => '가운데', 'right' => '오른쪽'];
const CTV_THEMES      = ['morning' => '아침빛 (노랑)', 'navy' => '남색', 'green' => '초록', 'plum' => '보라'];
const CTV_IMG_EXT     = ['jpg', 'jpeg', 'png', 'webp'];
const CTV_VIDEO_EXT   = ['mp4', 'm4v', 'webm', 'mov'];
const CTV_SECONDS     = [5, 7, 10, 15, 20, 30, 45, 60];   // 사진 한 장 보여 줄 시간 고르기
const CTV_PER_SCREEN  = [1, 2, 3, 4];                       // 묶음마다 한 화면에 사진 몇 장

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

    // 2026-10-09 영상 소리 칸 추가 (먼저 만든 표에도 붙입니다)
    $cols = $pdo->query('SHOW COLUMNS FROM church_tv_slides')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sound', $cols, true)) {
        $pdo->exec('ALTER TABLE church_tv_slides ADD COLUMN sound TINYINT(1) NOT NULL DEFAULT 0 AFTER seconds');
    }
    // 2026-10-09 유튜브 영상 번호 칸 추가
    if (!in_array('youtube', $cols, true)) {
        $pdo->exec('ALTER TABLE church_tv_slides ADD COLUMN youtube VARCHAR(20) NULL AFTER image_file');
    }
    // 2026-10-09 묶음 · 날짜 (예: 인도네시아 선교 > 2026-10-09 에 올린 것)
    $pdo->exec("CREATE TABLE IF NOT EXISTS church_tv_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        show_caption TINYINT(1) NOT NULL DEFAULT 1,
        sort_no INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // 2026-10-09 한 화면에 사진 몇 장 (묶음마다. 글 없는 사진을 N장씩 나란히)
    $gcols = $pdo->query('SHOW COLUMNS FROM church_tv_groups')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('per_screen', $gcols, true)) {
        $pdo->exec('ALTER TABLE church_tv_groups ADD COLUMN per_screen TINYINT NOT NULL DEFAULT 1 AFTER show_caption');
        $pdo->exec("UPDATE church_tv_groups SET per_screen = 3 WHERE name = '인도네시아 선교'");
    }
    if (!in_array('group_id', $cols, true)) {
        $pdo->exec('ALTER TABLE church_tv_slides ADD COLUMN group_id INT NULL AFTER id');
    }
    if (!in_array('post_date', $cols, true)) {
        $pdo->exec('ALTER TABLE church_tv_slides ADD COLUMN post_date DATE NULL AFTER group_id');
        $pdo->exec('UPDATE church_tv_slides SET post_date = DATE(created_at) WHERE post_date IS NULL');
    }
    // 처음 한 번 선교지 묶음 3개를 만들고, 라벨에 선교지 이름이 있는 슬라이드를 넣어 둡니다
    if ((int)$pdo->query('SELECT COUNT(*) FROM church_tv_groups')->fetchColumn() === 0) {
        $g = $pdo->prepare('INSERT INTO church_tv_groups (name, sort_no) VALUES (?, ?)');
        $mv = $pdo->prepare("UPDATE church_tv_slides SET group_id = ? WHERE group_id IS NULL AND label LIKE ?");
        foreach (['인도네시아', '몽골', '태국'] as $i => $place) {
            $g->execute([$place . ' 선교', $i + 1]);
            $gid = (int)$pdo->lastInsertId();
            $mv->execute([$gid, '%' . $place . '%']);
            if ($place === '인도네시아') { $pdo->exec('UPDATE church_tv_groups SET per_screen = 3 WHERE id = ' . $gid); }
        }
    }

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
    $ins = $pdo->prepare('INSERT INTO church_tv_slides (label, title, body, date_text, image_file, theme, sort_no, post_date)
                          VALUES (?,?,?,?,?,?,?, CURDATE())');
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

/** 영상 파일인지 (확장자로 봅니다) */
function ctv_is_video(?string $file): bool
{
    return $file !== null && $file !== ''
        && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), CTV_VIDEO_EXT, true);
}

/** 유튜브 주소에서 영상 번호(11자)를 뽑습니다. 아니면 null */
function ctv_youtube_id(string $url): ?string
{
    $url = trim($url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) { return $url; }
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** '2026-10-09' → '2026년 10월 9일' */
function ctv_date_ko(?string $d): string
{
    if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) { return ''; }
    return $m[1] . '년 ' . (int)$m[2] . '월 ' . (int)$m[3] . '일';
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

/** 슬라이드 순서: 묶음 없는 것 먼저 → 묶음 순서 → 묶음 안에서는 최근 날짜부터 → 같은 날은 정한 순서 */
const CTV_ORDER = 's.group_id IS NOT NULL, g.sort_no, g.id, s.post_date DESC, s.sort_no, s.id';

/** 이 화면에 지금 나갈 슬라이드 (켜짐 · 기간 안 · 순서대로) */
function ctv_slides_for(int $screenId): array
{
    $st = db()->prepare("SELECT s.*, g.name AS group_name, g.show_caption, g.per_screen FROM church_tv_slides s
                           JOIN church_tv_slide_screens l ON l.slide_id = s.id AND l.screen_id = ?
                           LEFT JOIN church_tv_groups g ON g.id = s.group_id
                          WHERE s.is_active = 1
                            AND (s.start_date IS NULL OR s.start_date <= CURDATE())
                            AND (s.end_date IS NULL OR s.end_date >= CURDATE())
                          ORDER BY " . CTV_ORDER);
    $st->execute([$screenId]);
    return $st->fetchAll();
}

/** TV 가 1분마다 받아 가는 내용 */
function ctv_feed(array $scr): array
{
    $slides = [];
    $packKey = [];
    foreach (ctv_slides_for((int)$scr['id']) as $s) {
        $img = null;
        $video = null;
        if ($s['image_file']) {
            $url = str_starts_with((string)$s['image_file'], 'asset:')
                ? 'tv.php?a=' . rawurlencode(substr((string)$s['image_file'], 6))
                : 'tv.php?img=' . (int)$s['id'] . '&v=' . rawurlencode((string)($s['updated_at'] ?? $s['created_at']));
            if (ctv_is_video((string)$s['image_file'])) { $video = $url; } else { $img = $url; }
        }
        $slides[] = [
            'id'      => (int)$s['id'],
            'label'   => (string)$s['label'],
            'title'   => (string)$s['title'],
            'body'    => (string)$s['body'],
            'date'    => (string)$s['date_text'],
            'img'     => $img,
            'video'   => $video,
            'yt'      => ($s['youtube'] ?? null) ?: null,
            'sound'   => (int)($s['sound'] ?? 0) === 1,
            // 묶음 슬라이드는 TV 구석에 '인도네시아 선교 · 2026년 10월 9일' 을 작게 붙입니다
            'cap'     => ($s['group_name'] ?? null) !== null && (int)($s['show_caption'] ?? 0) === 1
                ? $s['group_name'] . ($s['post_date'] ? ' · ' . ctv_date_ko((string)$s['post_date']) : '') : null,
            'theme'   => (string)$s['theme'],
            'seconds' => $s['seconds'] !== null ? (int)$s['seconds'] : null,
        ];
        // 같은 묶음 · 같은 날짜의 글 없는 사진끼리만 한 화면에 모읍니다
        $n = max(1, min(4, (int)($s['per_screen'] ?? 1)));
        $packKey[] = ($n > 1 && $img !== null && $s['title'] === '' && empty($s['youtube']))
            ? $s['group_id'] . '|' . $s['post_date'] . '|' . $n : null;
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
        'slides'  => ctv_pack_photos($slides, $packKey),
    ];
}

/** 한 화면에 N장: 이어지는 사진 슬라이드를 N장씩 묶어 imgs 로 내보냅니다 (시간은 그중 가장 긴 것) */
function ctv_pack_photos(array $slides, array $keys): array
{
    $out = [];
    $i = 0;
    $cnt = count($slides);
    while ($i < $cnt) {
        $k = $keys[$i];
        if ($k === null) { $out[] = $slides[$i++]; continue; }
        $n = (int)substr($k, strrpos($k, '|') + 1);
        $pack = $slides[$i];
        $pack['imgs'] = [$pack['img']];
        $sec = $pack['seconds'];
        $i++;
        while ($i < $cnt && $keys[$i] === $k && count($pack['imgs']) < $n) {
            $pack['imgs'][] = $slides[$i]['img'];
            if ($slides[$i]['seconds'] !== null && ($sec === null || $slides[$i]['seconds'] > $sec)) { $sec = $slides[$i]['seconds']; }
            $i++;
        }
        $pack['seconds'] = $sec;
        $out[] = $pack;
    }
    return $out;
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
