<?php
/**
 * 굿배송항공 홈페이지 DB 계층 (게시판)
 *
 *  - AI SPACE / 실서버가 주입하는 환경변수(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD)가 있으면 MySQL 을 씁니다.
 *  - 없으면 영속 폴더(/app/user_data, 없으면 프로젝트의 data/)에 SQLite 파일을 만들어 씁니다.
 *    → DB 를 아직 붙이지 않은 서버에서도 게시판이 바로 동작하고, MySQL 이 주입되면 자동으로 전환됩니다.
 *  - 테이블(board_post)이 없으면 만들고, 비어 있으면 db/seed/*.json 의 예시 글을 넣습니다.
 *  - 같은 테이블을 ERP(/erp/)에서도 그대로 읽고 쓸 수 있습니다. 스키마: db/schema.sql
 */
declare(strict_types=1);

function board_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $host = getenv('DB_HOST');
    if ($host) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_PORT') ?: '3306', getenv('DB_NAME'));
        $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), $opt);
    } else {
        $dir = is_dir('/app/user_data') ? '/app/user_data' : dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/board.sqlite', null, null, $opt);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    board_init($pdo);
    return $pdo;
}

function board_is_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function board_now(): string
{
    return date('Y-m-d H:i:s');
}

function board_init(PDO $pdo): void
{
    if (board_is_mysql($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS board_post (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board       VARCHAR(20)  NOT NULL,
            title       VARCHAR(200) NOT NULL,
            content     MEDIUMTEXT   NOT NULL,
            writer      VARCHAR(50)  NOT NULL DEFAULT '관리자',
            views       INT UNSIGNED NOT NULL DEFAULT 0,
            pinned      TINYINT(1)   NOT NULL DEFAULT 0,
            is_secret   TINYINT(1)   NOT NULL DEFAULT 0,
            password    VARCHAR(255) NULL,
            answer      MEDIUMTEXT   NULL,
            answered_at DATETIME     NULL,
            created_at  DATETIME     NOT NULL,
            updated_at  DATETIME     NOT NULL,
            INDEX idx_board (board, pinned, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS board_post (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            board       TEXT    NOT NULL,
            title       TEXT    NOT NULL,
            content     TEXT    NOT NULL,
            writer      TEXT    NOT NULL DEFAULT '관리자',
            views       INTEGER NOT NULL DEFAULT 0,
            pinned      INTEGER NOT NULL DEFAULT 0,
            is_secret   INTEGER NOT NULL DEFAULT 0,
            password    TEXT    NULL,
            answer      TEXT    NULL,
            answered_at TEXT    NULL,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_board ON board_post (board, pinned, id)");
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM board_post')->fetchColumn() === 0) {
        board_seed($pdo);
    }
}

/** db/seed/{notice,qna}.json 의 예시 글을 넣습니다(테이블이 비어 있을 때 한 번). */
function board_seed(PDO $pdo): void
{
    $ins = $pdo->prepare('INSERT INTO board_post (board, title, content, writer, views, pinned, is_secret, created_at, updated_at)
                          VALUES (:board, :title, :content, :writer, :views, :pinned, :is_secret, :created_at, :updated_at)');
    foreach (['notice', 'qna'] as $board) {
        $file = dirname(__DIR__) . '/db/seed/' . $board . '.json';
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($file), true);
        $items = $data['items'] ?? [];
        foreach (array_reverse($items) as $it) {           // 오래된 글부터 넣어 id 순서를 맞춤
            $ts = ($it['date'] ?? date('Y-m-d')) . ' 09:00:00';
            $ins->execute([
                ':board' => $board,
                ':title' => (string) ($it['title'] ?? ''),
                ':content' => (string) ($it['content'] ?? ''),
                ':writer' => (string) ($it['writer'] ?? '관리자'),
                ':views' => (int) ($it['views'] ?? 0),
                ':pinned' => !empty($it['pinned']) ? 1 : 0,
                ':is_secret' => ($it['writer'] ?? '') === '비공개' ? 1 : 0,
                ':created_at' => $ts,
                ':updated_at' => $ts,
            ]);
        }
    }
}

/** 목록 항목을 API 형식으로 변환 */
function board_row_public(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'title' => $r['title'],
        'date' => substr((string) $r['created_at'], 0, 10),
        'views' => (int) $r['views'],
        'writer' => $r['writer'],
        'pinned' => (bool) $r['pinned'],
        'secret' => (bool) $r['is_secret'],
        'answered' => !empty($r['answer']),
    ];
}

function board_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 본문 HTML 정리: script · iframe · 인라인 이벤트 · javascript: 제거 */
function board_clean_html(string $html): string
{
    $html = preg_replace('#<(script|iframe|object|embed|style)[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*#i', '$1=$2#', $html) ?? '';
    return $html;
}
