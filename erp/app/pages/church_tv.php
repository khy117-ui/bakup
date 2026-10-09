<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/church_tv.php';
require_once APP_DIR . '/imgshrink.php';

/**
 * 교회 TV 화면 (시스템 > 교회 TV 화면)
 *   · 위: TV 목록 — 주소 · 미리보기 · 켜기/끄기 · 지금 새로고침 · 설정
 *   · 사진 · 영상 여러 개 한꺼번에 올리기 — 한 개가 한 장이 되고, 사진은 보여 줄 시간을 고릅니다
 *   · 유튜브 링크 넣기 — 링크 한 줄이 한 장 (TV 에서 소리 없이 자동 재생, 끝나면 다음 장)
 *   · 아래: 슬라이드 — 묶음(인도네시아 선교 · 몽골 선교 …) > 올린 날짜 별로 모아 보여 줍니다.
 *     TV 마다 넣고 빼기, 순서, 기간, 시간, 사진 · 영상, 여러 개 골라 다른 묶음으로 옮기기
 * TV 는 1분마다 tv.php 에서 내용을 받아 가므로 여기서 저장하면 저절로 바뀝니다.
 * 이 화면에 권한 표(ROUTE_PERMS) 항목이 없으므로 최고관리자만 엽니다.
 */

$err = '';
try {
    ctv_ensure_tables();
} catch (PDOException $e) {
    error_log('교회 TV 표 준비 실패: ' . $e->getMessage());
    $err = '교회 TV 표를 준비하지 못했습니다: ' . $e->getMessage();
}
$pdo = db();

/** 올린 사진 · 영상을 보관 폴더에 넣고 파일 이름을 돌려줍니다 */
function ctv_store_upload(array $f): ?string
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) { return null; }
    $orig = (string)($f['name'] ?? '');
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException($orig . ': 파일이 너무 큽니다 (한 개 100MB 까지).');
    }
    if ($err !== UPLOAD_ERR_OK) { throw new RuntimeException($orig . ': 올리지 못했습니다.'); }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, CTV_VIDEO_EXT, true);
    if ($isVideo) {
        if ((int)$f['size'] > 100 * 1024 * 1024) { throw new RuntimeException($orig . ': 영상은 한 개 100MB 까지입니다.'); }
    } elseif (!in_array($ext, CTV_IMG_EXT, true) || !@getimagesize((string)$f['tmp_name'])) {
        throw new RuntimeException($orig . ': 사진은 JPG · PNG · WEBP, 영상은 MP4 · WEBM 만 올릴 수 있습니다.');
    }
    $name = ($isVideo ? 'video_' : 'slide_') . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $path = ctv_image_dir() . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string)$f['tmp_name'], $path)) {
        throw new RuntimeException($orig . ': 저장하지 못했습니다.');
    }
    if ($isVideo) { return $name; }
    // TV 는 1920 이면 충분합니다. 휴대폰 사진을 그대로 두면 낡은 스틱이 느려집니다
    $newExt = img_shrink($path, $ext, 1920, 600 * 1024);
    if ($newExt !== $ext && $newExt !== '' && $ext !== 'jpg') {
        $renamed = preg_replace('/\.[a-z]+$/', '.' . $newExt, $path);
        if ($renamed && @rename($path, $renamed)) { $name = basename($renamed); }
    }
    return $name;
}

/** 여러 파일 칸(name[]) 을 파일 한 개씩으로 풀어 줍니다 */
function ctv_files(string $field): array
{
    $F = $_FILES[$field] ?? null;
    if (!$F || !is_array($F['name'] ?? null)) { return []; }
    $out = [];
    foreach ($F['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $F['type'][$i] ?? '', 'tmp_name' => $F['tmp_name'][$i] ?? '',
                  'error' => $F['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $F['size'][$i] ?? 0];
    }
    return $out;
}

/** 올리기 칸에서 고른 묶음 (새 이름을 적었으면 묶음을 새로 만듭니다) */
function ctv_post_group(PDO $pdo): ?int
{
    $new = trim(mb_substr(post('new_group'), 0, 60));
    if ($new !== '') {
        $st = $pdo->prepare('SELECT id FROM church_tv_groups WHERE name = ?');
        $st->execute([$new]);
        if ($id = (int)$st->fetchColumn()) { return $id; }
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0)+1 FROM church_tv_groups')->fetchColumn();
        $pdo->prepare('INSERT INTO church_tv_groups (name, sort_no) VALUES (?, ?)')->execute([$new, $next]);
        return (int)$pdo->lastInsertId();
    }
    $gid = (int)post('group_id');
    return $gid > 0 ? $gid : null;
}

/** 날짜 칸 (비우면 오늘) */
function ctv_post_date(): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', post('post_date')) ? post('post_date') : date('Y-m-d');
}

function ctv_remove_file(?string $f): void
{
    if ($f && !str_starts_with($f, 'asset:')) {
        @unlink(ctv_image_dir() . DIRECTORY_SEPARATOR . basename($f));
    }
}

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // 한 번에 보낸 양이 서버 한도를 넘으면 PHP 가 내용을 통째로 버립니다
    $err = '한 번에 올린 파일이 너무 큽니다. 200MB 아래로 나눠서 올려 주세요.';
    if (isset($_GET['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => $err], JSON_UNESCAPED_UNICODE); exit; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    csrf_check();
    $act = post('act');
    $back = '?p=church_tv' . (query('tab') !== '' ? '&tab=' . rawurlencode(query('tab')) : '');
    try {
        if ($act === 'screen_save') {
            $id = (int)post('id');
            $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', post('slug')));
            $name = mb_substr(post('name'), 0, 50);
            if ($slug === '' || $name === '') { throw new RuntimeException('TV 이름과 주소 글자를 넣어 주세요.'); }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM church_tv_screens WHERE slug = ? AND id <> ?');
            $dup->execute([$slug, $id]);
            if ((int)$dup->fetchColumn() > 0) { throw new RuntimeException('주소 글자 "' . $slug . '" 는 이미 다른 TV 가 씁니다.'); }
            $vals = [
                $slug, $name, post('is_active') === '1' ? 1 : 0,
                max(4, min(120, (int)post('seconds', '10'))),
                array_key_exists(post('transition'), CTV_TRANSITIONS) ? post('transition') : 'fade',
                array_key_exists(post('font_size'), CTV_FONT_SIZES) ? post('font_size') : 'normal',
                array_key_exists(post('box_pos'), CTV_BOX_POS) ? post('box_pos') : 'auto',
                post('show_clock') === '1' ? 1 : 0,
                ctv_clean_time(post('on_from'), '00:00'), ctv_clean_time(post('on_to'), '24:00'),
            ];
            if ($id > 0) {
                $vals[] = $id;
                $pdo->prepare('UPDATE church_tv_screens SET slug=?, name=?, is_active=?, seconds=?, transition=?, font_size=?,
                               box_pos=?, show_clock=?, on_from=?, on_to=?, version = version + 1 WHERE id = ?')->execute($vals);
                flash($name . ' 설정을 저장했습니다. 1분 안에 TV 에 반영됩니다.');
            } else {
                if ((int)$pdo->query('SELECT COUNT(*) FROM church_tv_screens')->fetchColumn() >= 10) {
                    throw new RuntimeException('TV 는 10대까지 만들 수 있습니다.');
                }
                $vals[] = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0)+1 FROM church_tv_screens')->fetchColumn();
                $pdo->prepare('INSERT INTO church_tv_screens (slug, name, is_active, seconds, transition, font_size,
                               box_pos, show_clock, on_from, on_to, sort_no) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                $id = (int)$pdo->lastInsertId();
                flash($name . ' 을(를) 만들었습니다. 아래 슬라이드 목록에서 이 TV 에 보여 줄 것을 체크하세요.');
            }
            log_action('교회TV', 'UPDATE', 'church_tv_screens', $id, $name);
            redirect('?p=church_tv&tab=' . rawurlencode($slug));
        }
        if ($act === 'screen_del') {
            $id = (int)post('id');
            $pdo->prepare('DELETE FROM church_tv_slide_screens WHERE screen_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM church_tv_screens WHERE id = ?')->execute([$id]);
            log_action('교회TV', 'DELETE', 'church_tv_screens', $id, 'TV 삭제');
            flash('TV 를 지웠습니다. 그 주소는 이제 열리지 않습니다.');
            redirect('?p=church_tv');
        }
        if ($act === 'screen_power') {
            $id = (int)post('id');
            $pdo->prepare('UPDATE church_tv_screens SET is_active = 1 - is_active, version = version + 1 WHERE id = ?')->execute([$id]);
            flash('켜기 · 끄기를 바꿨습니다. 1분 안에 TV 에 반영됩니다.');
            redirect($back);
        }
        if ($act === 'refresh') {
            $id = (int)post('id');
            ctv_bump($id > 0 ? $id : null);
            flash('1분 안에 TV 가 화면을 새로 엽니다.');
            redirect($back);
        }
        if ($act === 'bulk_upload') {
            // 사진 · 영상 여러 개 → 한 개당 슬라이드 한 장 (글 없이 화면 가득)
            $sec = (int)post('seconds');
            $sec = $sec >= 4 ? min(120, $sec) : null;
            $screenIds = array_map('intval', (array)($_POST['screens'] ?? []));
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0) FROM church_tv_slides')->fetchColumn();
            $gid = ctv_post_group($pdo);
            $pd = ctv_post_date();
            $ins = $pdo->prepare('INSERT INTO church_tv_slides (group_id, post_date, label, title, image_file, seconds, is_active, start_date, end_date, sort_no)
                                  VALUES (?, ?, ?, \'\', ?, ?, 1, ?, ?, ?)');
            $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
            $sd = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('start_date')) ? post('start_date') : null;
            $ed = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('end_date')) ? post('end_date') : null;
            $ok = 0; $vid = 0; $bad = [];
            foreach (ctv_files('files') as $f) {
                try {
                    $name = ctv_store_upload($f);
                    if ($name === null) { continue; }
                    $isV = ctv_is_video($name);
                    // 영상은 고른 시간 대신 끝까지 재생합니다
                    $ins->execute([$gid, $pd, mb_substr(post('label'), 0, 40), $name, $isV ? null : $sec, $sd, $ed, ++$sort]);
                    $sid = (int)$pdo->lastInsertId();
                    foreach ($screenIds as $scId) { $link->execute([$sid, $scId]); }
                    $ok++;
                    if ($isV) { $vid++; }
                } catch (RuntimeException $e) {
                    $bad[] = $e->getMessage();
                }
            }
            if ($ok > 0) { log_action('교회TV', 'CREATE', 'church_tv_slides', 0, $ok . '개 한꺼번에 올림'); }
            $msg = $ok > 0
                ? $ok . '개를 올렸습니다' . ($vid ? ' (영상 ' . $vid . '개)' : '') . '. 1분 안에 TV 에 반영됩니다.'
                : '올린 파일이 없습니다.';
            if ($bad) { $msg .= ' 못 올린 것: ' . implode(' / ', $bad); }
            flash($msg);
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok > 0, 'url' => $back, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect($back);
        }
        if ($act === 'yt_add') {
            // 유튜브 링크 여러 줄 → 한 줄당 슬라이드 한 장
            $sec = (int)post('seconds');
            $sec = $sec >= 4 ? min(3600, $sec) : null;
            $screenIds = array_map('intval', (array)($_POST['screens'] ?? []));
            $sd = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('start_date')) ? post('start_date') : null;
            $ed = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('end_date')) ? post('end_date') : null;
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0) FROM church_tv_slides')->fetchColumn();
            $gid = ctv_post_group($pdo);
            $pd = ctv_post_date();
            $ins = $pdo->prepare('INSERT INTO church_tv_slides (group_id, post_date, label, title, youtube, seconds, sound, is_active, start_date, end_date, sort_no)
                                  VALUES (?, ?, \'\', \'\', ?, ?, ?, 1, ?, ?, ?)');
            $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
            $ok = 0; $bad = [];
            foreach (preg_split('/\s+/', post('links')) as $line) {
                if ($line === '') { continue; }
                $yid = ctv_youtube_id($line);
                if ($yid === null) { $bad[] = mb_strimwidth($line, 0, 40, '…'); continue; }
                $ins->execute([$gid, $pd, $yid, $sec, post('sound') === '1' ? 1 : 0, $sd, $ed, ++$sort]);
                $sid = (int)$pdo->lastInsertId();
                foreach ($screenIds as $scId) { $link->execute([$sid, $scId]); }
                $ok++;
            }
            if ($ok > 0) { log_action('교회TV', 'CREATE', 'church_tv_slides', 0, '유튜브 ' . $ok . '개 넣음'); }
            $msg = $ok > 0 ? '유튜브 ' . $ok . '개를 넣었습니다. 1분 안에 TV 에 반영됩니다.' : '넣은 유튜브 링크가 없습니다.';
            if ($bad) { $msg .= ' 유튜브 주소가 아닌 것: ' . implode(' / ', $bad); }
            flash($msg);
            redirect($back);
        }
        if ($act === 'slide_seconds') {
            // 목록에서 바로 시간 고르기
            $sec = (int)post('seconds');
            $pdo->prepare('UPDATE church_tv_slides SET seconds = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$sec >= 4 ? min(3600, $sec) : null, (int)post('id')]);
            redirect($back);
        }
        if ($act === 'slide_save') {
            $id = (int)post('id');
            $title = mb_substr(post('title'), 0, 120);
            $old = null;
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM church_tv_slides WHERE id = ?');
                $st->execute([$id]);
                $old = $st->fetch() ?: null;
            }
            $img = $old['image_file'] ?? null;
            $up = ctv_store_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if ($up !== null) {
                ctv_remove_file($img);
                $img = $up;
            } elseif (post('remove_image') === '1') {
                ctv_remove_file($img);
                $img = null;
            }
            $yt = null;
            if (trim(post('youtube')) !== '') {
                $yt = ctv_youtube_id(post('youtube'));
                if ($yt === null) { throw new RuntimeException('유튜브 주소를 알아보지 못했습니다. 유튜브에서 [공유] > [복사] 한 주소를 넣어 주세요.'); }
            }
            if ($title === '' && !$img && !$yt) { throw new RuntimeException('제목을 넣거나 사진 · 영상 · 유튜브 링크를 넣어 주세요.'); }
            $sd = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('start_date')) ? post('start_date') : null;
            $ed = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('end_date')) ? post('end_date') : null;
            $sec = (int)post('seconds');
            $vals = [
                mb_substr(post('label'), 0, 40), $title, mb_substr(post('body'), 0, 600), mb_substr(post('date_text'), 0, 40),
                $img, array_key_exists(post('theme'), CTV_THEMES) ? post('theme') : 'morning',
                $sec >= 4 ? min(3600, $sec) : null, post('sound') === '1' ? 1 : 0, post('is_active') === '1' ? 1 : 0, $sd, $ed, $yt,
                ctv_post_group($pdo), ctv_post_date(),
            ];
            if ($id > 0) {
                $vals[] = $id;
                $pdo->prepare('UPDATE church_tv_slides SET label=?, title=?, body=?, date_text=?, image_file=?, theme=?,
                               seconds=?, sound=?, is_active=?, start_date=?, end_date=?, youtube=?, group_id=?, post_date=?, updated_at=NOW() WHERE id = ?')->execute($vals);
            } else {
                $vals[] = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0)+1 FROM church_tv_slides')->fetchColumn();
                $pdo->prepare('INSERT INTO church_tv_slides (label, title, body, date_text, image_file, theme, seconds, sound,
                               is_active, start_date, end_date, youtube, group_id, post_date, sort_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM church_tv_slide_screens WHERE slide_id = ?')->execute([$id]);
            $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
            foreach ((array)($_POST['screens'] ?? []) as $sid) {
                $link->execute([$id, (int)$sid]);
            }
            log_action('교회TV', $old ? 'UPDATE' : 'CREATE', 'church_tv_slides', $id, $title !== '' ? $title : '(사진 · 영상 · 유튜브만)');
            flash(($title !== '' ? '"' . $title . '" 슬라이드를' : '슬라이드를') . ' 저장했습니다. 1분 안에 TV 에 반영됩니다.');
            redirect($back);
        }
        if ($act === 'slide_del') {
            $id = (int)post('id');
            $st = $pdo->prepare('SELECT image_file, title FROM church_tv_slides WHERE id = ?');
            $st->execute([$id]);
            if ($r = $st->fetch()) {
                ctv_remove_file($r['image_file']);
                $pdo->prepare('DELETE FROM church_tv_slide_screens WHERE slide_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM church_tv_slides WHERE id = ?')->execute([$id]);
                log_action('교회TV', 'DELETE', 'church_tv_slides', $id, (string)$r['title']);
                flash(($r['title'] !== '' ? '"' . $r['title'] . '" ' : '') . '슬라이드를 지웠습니다.');
            }
            redirect($back);
        }
        if ($act === 'slide_toggle') {
            // 목록에서 [1층] [3층] 칸을 눌러 그 TV 에 넣고 빼기
            $id = (int)post('id');
            $sid = (int)post('screen_id');
            $ex = $pdo->prepare('SELECT COUNT(*) FROM church_tv_slide_screens WHERE slide_id = ? AND screen_id = ?');
            $ex->execute([$id, $sid]);
            if ((int)$ex->fetchColumn() > 0) {
                $pdo->prepare('DELETE FROM church_tv_slide_screens WHERE slide_id = ? AND screen_id = ?')->execute([$id, $sid]);
            } else {
                $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)')->execute([$id, $sid]);
            }
            redirect($back);
        }
        if ($act === 'slide_active') {
            $pdo->prepare('UPDATE church_tv_slides SET is_active = 1 - is_active WHERE id = ?')->execute([(int)post('id')]);
            redirect($back);
        }
        if ($act === 'slide_move') {
            // 위 · 아래 화살표: 같은 묶음 · 같은 날짜 안에서 이웃과 순서를 맞바꿉니다
            $st = $pdo->prepare('SELECT group_id, post_date FROM church_tv_slides WHERE id = ?');
            $st->execute([(int)post('id')]);
            if ($me = $st->fetch()) {
                $q = $pdo->prepare('SELECT id FROM church_tv_slides WHERE group_id <=> ? AND post_date <=> ? ORDER BY sort_no, id');
                $q->execute([$me['group_id'], $me['post_date']]);
                $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                $i = array_search((int)post('id'), $ids, true);
                $j = $i === false ? false : (post('dir') === 'up' ? $i - 1 : $i + 1);
                if ($i !== false && $j >= 0 && $j < count($ids)) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    // 이 날짜 안의 순서만 1, 2, 3 … 으로 다시 매깁니다 (다른 묶음 · 날짜와는 섞이지 않습니다)
                    $up = $pdo->prepare('UPDATE church_tv_slides SET sort_no = ? WHERE id = ?');
                    foreach ($ids as $n => $x) { $up->execute([$n + 1, $x]); }
                }
            }
            redirect($back);
        }
        if ($act === 'move_group' && post('do') === 'del') {
            // 체크한 슬라이드를 한꺼번에 지우기 (사진 · 영상 파일도 지웁니다)
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) { throw new RuntimeException('지울 슬라이드를 체크해 주세요.'); }
            $in = implode(',', $ids);
            foreach ($pdo->query("SELECT image_file FROM church_tv_slides WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $f) {
                ctv_remove_file($f);
            }
            $pdo->exec("DELETE FROM church_tv_slide_screens WHERE slide_id IN ($in)");
            $pdo->exec("DELETE FROM church_tv_slides WHERE id IN ($in)");
            log_action('교회TV', 'DELETE', 'church_tv_slides', 0, count($ids) . '개 한꺼번에 삭제');
            flash(count($ids) . '개를 지웠습니다.');
            redirect($back);
        }
        if ($act === 'move_group') {
            // 체크한 슬라이드를 다른 묶음으로 (묶음 없음도 됩니다)
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) { throw new RuntimeException('옮길 슬라이드를 체크해 주세요.'); }
            $gid = ctv_post_group($pdo);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE church_tv_slides SET group_id = ?, updated_at = NOW() WHERE id IN ($in)")->execute(array_merge([$gid], $ids));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', post('post_date'))) {
                $pdo->prepare("UPDATE church_tv_slides SET post_date = ? WHERE id IN ($in)")->execute(array_merge([post('post_date')], $ids));
            }
            flash(count($ids) . '개를 옮겼습니다.');
            redirect($back);
        }
        if ($act === 'group_save') {
            $id = (int)post('id');
            $name = trim(mb_substr(post('name'), 0, 60));
            if ($name === '') { throw new RuntimeException('묶음 이름을 넣어 주세요.'); }
            if ($id > 0) {
                $per = in_array((int)post('per_screen'), CTV_PER_SCREEN, true) ? (int)post('per_screen') : 1;
                $pdo->prepare('UPDATE church_tv_groups SET name = ?, show_caption = ?, per_screen = ? WHERE id = ?')
                    ->execute([$name, post('show_caption') === '1' ? 1 : 0, $per, $id]);
                flash('"' . $name . '" 묶음을 저장했습니다.');
            } else {
                $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0)+1 FROM church_tv_groups')->fetchColumn();
                $pdo->prepare('INSERT INTO church_tv_groups (name, sort_no) VALUES (?, ?)')->execute([$name, $next]);
                flash('"' . $name . '" 묶음을 만들었습니다. 올릴 때 이 묶음을 고르세요.');
            }
            redirect($back);
        }
        if ($act === 'group_del') {
            $id = (int)post('id');
            $pdo->prepare('UPDATE church_tv_slides SET group_id = NULL WHERE group_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM church_tv_groups WHERE id = ?')->execute([$id]);
            flash('묶음을 지웠습니다. 안에 있던 슬라이드는 지우지 않고 "묶음 없음" 으로 옮겼습니다.');
            redirect($back);
        }
        if ($act === 'group_move') {
            $ids = array_map('intval', $pdo->query('SELECT id FROM church_tv_groups ORDER BY sort_no, id')->fetchAll(PDO::FETCH_COLUMN));
            $i = array_search((int)post('id'), $ids, true);
            $j = $i === false ? false : (post('dir') === 'up' ? $i - 1 : $i + 1);
            if ($i !== false && $j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                $up = $pdo->prepare('UPDATE church_tv_groups SET sort_no = ? WHERE id = ?');
                foreach ($ids as $n => $x) { $up->execute([$n + 1, $x]); }
            }
            redirect($back);
        }
        if ($act === 'bunch_screen' || $act === 'bunch_active') {
            // 묶음 전체 (또는 묶음 안 한 날짜) 를 한 번에: TV 넣기 · 빼기 / 켜기 · 끄기
            $gid = (int)post('group_id');
            $where = $gid > 0 ? 'group_id = ?' : 'group_id IS NULL';
            $args = $gid > 0 ? [$gid] : [];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', post('post_date'))) { $where .= ' AND post_date = ?'; $args[] = post('post_date'); }
            $st = $pdo->prepare("SELECT id FROM church_tv_slides WHERE $where");
            $st->execute($args);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($ids && $act === 'bunch_screen') {
                $sid = (int)post('screen_id');
                $in = implode(',', $ids);
                $have = (int)$pdo->query("SELECT COUNT(*) FROM church_tv_slide_screens WHERE screen_id = $sid AND slide_id IN ($in)")->fetchColumn();
                if ($have === count($ids)) {
                    $pdo->exec("DELETE FROM church_tv_slide_screens WHERE screen_id = $sid AND slide_id IN ($in)");
                } else {
                    $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
                    foreach ($ids as $x) { $link->execute([$x, $sid]); }
                }
            }
            if ($ids && $act === 'bunch_active') {
                $pdo->exec('UPDATE church_tv_slides SET is_active = ' . (post('on') === '1' ? 1 : 0) . ' WHERE id IN (' . implode(',', $ids) . ')');
            }
            redirect($back);
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
}

// ---------------------------------------------------------------- 보기
$screens = $pdo->query('SELECT * FROM church_tv_screens ORDER BY sort_no, id')->fetchAll();
$tab = query('tab');
$tabScreen = null;
foreach ($screens as $s) { if ($s['slug'] === $tab) { $tabScreen = $s; } }
if ($tab === '' && $screens) { $tabScreen = $screens[0]; $tab = $screens[0]['slug']; }

$links = [];
foreach ($pdo->query('SELECT slide_id, screen_id FROM church_tv_slide_screens')->fetchAll() as $l) {
    $links[(int)$l['slide_id']][(int)$l['screen_id']] = true;
}
$groups = $pdo->query('SELECT * FROM church_tv_groups ORDER BY sort_no, id')->fetchAll();
$allSlides = $pdo->query('SELECT s.* FROM church_tv_slides s LEFT JOIN church_tv_groups g ON g.id = s.group_id ORDER BY ' . CTV_ORDER)->fetchAll();
$slides = $tabScreen
    ? array_values(array_filter($allSlides, fn($s) => isset($links[(int)$s['id']][(int)$tabScreen['id']])))
    : $allSlides;
$liveIds = $tabScreen ? array_map(fn($s) => (int)$s['id'], ctv_slides_for((int)$tabScreen['id'])) : [];

$editSlide = null;
if (query('slide') === 'new') {
    $editSlide = ['id' => 0, 'label' => '', 'title' => '', 'body' => '', 'date_text' => '', 'image_file' => null,
                  'theme' => 'morning', 'seconds' => null, 'sound' => 0, 'youtube' => null, 'group_id' => null,
                  'post_date' => date('Y-m-d'), 'is_active' => 1, 'start_date' => null, 'end_date' => null];
} elseif ((int)query('slide') > 0) {
    foreach ($allSlides as $s) { if ((int)$s['id'] === (int)query('slide')) { $editSlide = $s; } }
}
$editScreen = null;
if (query('screen') === 'new') {
    $editScreen = ['id' => 0, 'slug' => '', 'name' => '', 'is_active' => 1, 'seconds' => 10, 'transition' => 'fade',
                   'font_size' => 'normal', 'box_pos' => 'auto', 'show_clock' => 1, 'on_from' => '00:00', 'on_to' => '24:00'];
} elseif ((int)query('screen') > 0) {
    foreach ($screens as $s) { if ((int)$s['id'] === (int)query('screen')) { $editScreen = $s; } }
}

/** 묶음 고르는 칸 + 새 묶음 이름 + 날짜 (올리기 · 수정 · 옮기기 공통) */
function ctv_group_fields(array $groups, ?int $sel, ?string $date, bool $dateRequired = true): string
{
    $o = '<option value="0">묶음 없음</option>';
    foreach ($groups as $g) {
        $o .= '<option value="' . (int)$g['id'] . '"' . ($sel === (int)$g['id'] ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    }
    return '<div class="fw w2"><label>묶음</label><select name="group_id">' . $o . '</select></div>'
         . '<div class="fw w2"><label>또는 새 묶음 이름</label><input type="text" name="new_group" maxlength="60" placeholder="예: 필리핀 선교"></div>'
         . '<div class="fw w2"><label>' . ($dateRequired ? '날짜 (묶음 안에서 날짜별로 모입니다)' : '날짜도 바꾸기 (비우면 그대로)') . '</label>'
         . '<input type="date" name="post_date" value="' . h((string)$date) . '"></div>';
}

/** 시간 고르는 칸 (빈 값 = TV 설정 / 영상은 끝까지) */
function ctv_seconds_options(?int $cur, bool $video): string
{
    $html = '<option value="">' . ($video ? '끝까지 재생' : 'TV 기본') . '</option>';
    $list = CTV_SECONDS;
    if ($cur !== null && !in_array($cur, $list, true)) { $list[] = $cur; sort($list); }
    foreach ($list as $n) {
        $html .= '<option value="' . $n . '"' . ($cur === $n ? ' selected' : '') . '>' . $n . '초</option>';
    }
    return $html;
}

/** 끝까지 재생하는 것 (영상 · 유튜브) 인지 */
function ctv_is_motion(array $s): bool
{
    return !empty($s['youtube']) || ctv_is_video($s['image_file'] ?? null);
}

function ctv_thumb(array $s): string
{
    if (!empty($s['youtube'])) {
        return '<div style="position:relative;width:96px;height:54px"><img src="https://i.ytimg.com/vi/' . h((string)$s['youtube'])
             . '/mqdefault.jpg" alt="" style="width:96px;height:54px;object-fit:cover;border-radius:4px;display:block">'
             . '<span style="position:absolute;left:4px;bottom:4px;background:#C00;color:#fff;font-size:10px;font-weight:700;'
             . 'padding:1px 5px;border-radius:3px">YouTube</span></div>';
    }
    if ($s['image_file'] && ctv_is_video((string)$s['image_file'])) {
        return '<div style="width:96px;height:54px;border-radius:4px;background:#111;color:#fff;display:flex;'
             . 'align-items:center;justify-content:center;font-size:12px;font-weight:700">▶ 영상</div>';
    }
    if ($s['image_file']) {
        $src = str_starts_with((string)$s['image_file'], 'asset:')
            ? 'tv.php?a=' . rawurlencode(substr((string)$s['image_file'], 6))
            : 'tv.php?img=' . (int)$s['id'] . '&v=' . rawurlencode((string)($s['updated_at'] ?? ''));
        return '<img src="' . h($src) . '" alt="" style="width:96px;height:54px;object-fit:cover;border-radius:4px;display:block">';
    }
    $c = ['morning' => '#F2B632', 'navy' => '#23406E', 'green' => '#2E5A43', 'plum' => '#5A3463'][$s['theme']] ?? '#F2B632';
    return '<div style="width:96px;height:54px;border-radius:4px;background:' . $c . '"></div>';
}

layout_head('교회 TV 화면', 'church_tv');
?>
<div class="head">
  <h1>교회 TV 화면</h1>
  <div class="crumb">시스템 &gt; 교회 TV 화면 · 1층 · 3층 모니터에 나가는 안내 슬라이드 (테스트)</div>
  <div class="right">
    <a class="btn" href="?p=church_tv&amp;screen=new">TV 추가</a>
    <a class="btn" href="?p=church_tv&amp;tab=<?= h($tab) ?>&amp;slide=new">글 슬라이드 추가</a>
    <a class="btn pri" href="#ctv-bulk">사진 · 영상 올리기</a>
    <a class="btn" href="#ctv-yt">유튜브 넣기</a>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">TV 목록 <span style="font-weight:400;color:var(--ink3)">TV 스틱 브라우저의 시작 주소로 아래 주소를 한 번만 넣으면, 그다음부터는 여기서 바꾼 내용이 1분 안에 TV 에 나옵니다.</span></div>
  <table>
    <thead><tr><th style="width:140px">TV</th><th>주소 (TV 스틱에 넣을 것)</th><th class="c" style="width:110px">지금</th>
      <th class="c" style="width:80px">슬라이드</th><th style="width:330px"></th></tr></thead>
    <tbody>
    <?php foreach ($screens as $s):
      $url = ctv_public_url((string)$s['slug']);
      $n = count(ctv_slides_for((int)$s['id']));
      $on = ctv_is_on($s); ?>
      <tr>
        <td style="font-weight:700"><?= h($s['name']) ?></td>
        <td><input type="text" readonly value="<?= h($url) ?>" onclick="this.select()" style="width:100%;max-width:420px;font-size:12px"></td>
        <td class="c"><?php if (!(int)$s['is_active']): ?><span class="badge b-info">꺼짐</span>
          <?php elseif ($on): ?><span class="badge b-ok">송출 중</span>
          <?php else: ?><span class="badge b-warn">쉬는 시간</span><?php endif; ?>
          <div style="font-size:11px;color:var(--ink3)"><?= h($s['on_from']) ?>~<?= h($s['on_to']) ?></div></td>
        <td class="c tnum"><?= $n ?>장</td>
        <td style="white-space:nowrap">
          <button type="button" class="btn sm" onclick="ctvCopy('<?= h($url) ?>')">링크 복사</button>
          <a class="btn sm" href="tv.php?s=<?= h(rawurlencode((string)$s['slug'])) ?>" target="_blank">미리보기</a>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="screen_power"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn sm"><?= (int)$s['is_active'] ? '끄기' : '켜기' ?></button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="refresh"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn sm" title="TV 화면을 처음부터 다시 엽니다">새로고침</button></form>
          <a class="btn sm" href="?p=church_tv&amp;tab=<?= h($s['slug']) ?>&amp;screen=<?= (int)$s['id'] ?>">설정</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($editScreen): ?>
<div class="card">
  <div class="ch"><?= $editScreen['id'] ? h($editScreen['name']) . ' 설정' : 'TV 추가' ?></div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="screen_save">
      <input type="hidden" name="id" value="<?= (int)$editScreen['id'] ?>">
      <div class="fw w2"><label>TV 이름 *</label>
        <input type="text" name="name" required maxlength="50" value="<?= h($editScreen['name']) ?>" placeholder="2층 카페 모니터"></div>
      <div class="fw w1"><label>주소 글자 *</label>
        <input type="text" name="slug" required maxlength="20" value="<?= h($editScreen['slug']) ?>" placeholder="2f"></div>
      <div class="fw w1"><label>한 장 시간(초)</label>
        <input type="number" name="seconds" min="4" max="120" value="<?= (int)$editScreen['seconds'] ?>"></div>
      <div class="fw w2"><label>바뀌는 모양</label><select name="transition">
        <?php foreach (CTV_TRANSITIONS as $k => $v): ?><option value="<?= $k ?>"<?= $editScreen['transition'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
      <div class="fw w1"><label>글씨</label><select name="font_size">
        <?php foreach (CTV_FONT_SIZES as $k => $v): ?><option value="<?= $k ?>"<?= $editScreen['font_size'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
      <div class="fw w2"><label>글 상자 자리</label><select name="box_pos">
        <?php foreach (CTV_BOX_POS as $k => $v): ?><option value="<?= $k ?>"<?= $editScreen['box_pos'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
      <div class="fw w1"><label>켜는 시각</label>
        <input type="text" name="on_from" value="<?= h($editScreen['on_from']) ?>" placeholder="07:00" maxlength="5"></div>
      <div class="fw w1"><label>끄는 시각</label>
        <input type="text" name="on_to" value="<?= h($editScreen['on_to']) ?>" placeholder="22:00" maxlength="5"></div>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" name="show_clock" value="1"<?= (int)$editScreen['show_clock'] ? ' checked' : '' ?>> 시계 · 로고</label>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" name="is_active" value="1"<?= (int)$editScreen['is_active'] ? ' checked' : '' ?>> 송출</label>
      <button class="btn pri">저장</button>
      <a class="btn" href="?p=church_tv&amp;tab=<?= h($tab) ?>">닫기</a>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      켜는 · 끄는 시각 밖에는 까만 화면이 됩니다 (예: 07:00 ~ 22:00). 00:00 ~ 24:00 이면 늘 켜 둡니다.
      플라즈마 TV 는 같은 그림을 오래 두면 자국이 남으니 밤에는 꺼 두세요.</div>
    <?php if ($editScreen['id']): ?>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('이 TV 를 지우면 그 주소가 더 이상 열리지 않습니다. 슬라이드는 남습니다. 지울까요?');">
      <?= csrf_field() ?><input type="hidden" name="act" value="screen_del"><input type="hidden" name="id" value="<?= (int)$editScreen['id'] ?>">
      <button class="btn sm" style="color:#8A1C1C">이 TV 지우기</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($editSlide): ?>
<div class="card">
  <div class="ch"><?= $editSlide['id'] ? '슬라이드 수정' : '슬라이드 추가' ?></div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data" class="f" style="align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="slide_save">
      <input type="hidden" name="id" value="<?= (int)$editSlide['id'] ?>">
      <?= ctv_group_fields($groups, $editSlide['group_id'] !== null ? (int)$editSlide['group_id'] : null, $editSlide['post_date'] ?? date('Y-m-d')) ?>
      <div class="fw w2"><label>위 작은 글씨</label>
        <input type="text" name="label" maxlength="40" value="<?= h($editSlide['label']) ?>" placeholder="선교소식 · 태국"></div>
      <div class="fw w4"><label>제목 (비우면 사진 · 영상만 화면 가득)</label>
        <input type="text" name="title" maxlength="120" value="<?= h($editSlide['title']) ?>"></div>
      <div class="fw w6" style="flex-basis:100%"><label>내용 (기도제목 · 안내문, 2~3줄이 보기 좋습니다)</label>
        <textarea name="body" maxlength="600" rows="3" style="width:100%"><?= h($editSlide['body']) ?></textarea></div>
      <div class="fw w2"><label>날짜 글씨</label>
        <input type="text" name="date_text" maxlength="40" value="<?= h($editSlide['date_text']) ?>" placeholder="2026년 10월 12일"></div>
      <div class="fw w3"><label>사진 또는 영상 (없으면 색 배경)</label>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,.mp4,.m4v,.webm,.mov"></div>
      <div class="fw w2"><label>사진 없을 때 색</label><select name="theme">
        <?php foreach (CTV_THEMES as $k => $v): ?><option value="<?= $k ?>"<?= $editSlide['theme'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select></div>
      <div class="fw w4"><label>또는 유튜브 링크 (넣으면 이 장은 유튜브로 나옵니다)</label>
        <input type="text" name="youtube" maxlength="200" placeholder="https://youtu.be/..."
               value="<?= !empty($editSlide['youtube']) ? 'https://youtu.be/' . h((string)$editSlide['youtube']) : '' ?>"></div>
      <?php $isV = ctv_is_motion($editSlide); ?>
      <div class="fw w1"><label>보여 줄 시간</label>
        <select name="seconds"><?= ctv_seconds_options($editSlide['seconds'] !== null ? (int)$editSlide['seconds'] : null, $isV) ?></select></div>
      <?php if ($isV): ?>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" name="sound" value="1"<?= (int)($editSlide['sound'] ?? 0) ? ' checked' : '' ?>> 소리 켜기</label>
      <?php endif; ?>
      <div class="fw w2"><label>보여 줄 시작일</label>
        <input type="date" name="start_date" value="<?= h($editSlide['start_date'] ?? '') ?>"></div>
      <div class="fw w2"><label>마지막 날</label>
        <input type="date" name="end_date" value="<?= h($editSlide['end_date'] ?? '') ?>"></div>
      <div class="fw" style="flex-basis:100%"><label>보여 줄 TV</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
        <?php foreach ($screens as $s):
          $chk = $editSlide['id'] ? isset($links[(int)$editSlide['id']][(int)$s['id']]) : ($tabScreen && (int)$tabScreen['id'] === (int)$s['id']); ?>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="screens[]" value="<?= (int)$s['id'] ?>"<?= $chk ? ' checked' : '' ?>> <?= h($s['name']) ?></label>
        <?php endforeach; ?>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400;margin-left:12px">
            <input type="checkbox" name="is_active" value="1"<?= (int)$editSlide['is_active'] ? ' checked' : '' ?>> 켜기</label>
        </div></div>
      <?php if ($editSlide['image_file']): ?>
        <div style="display:flex;gap:10px;align-items:center"><?= ctv_thumb($editSlide) ?>
          <label style="font-weight:400;font-size:12.5px"><input type="checkbox" name="remove_image" value="1"> <?= ctv_is_video($editSlide['image_file']) ? '영상' : '사진' ?> 빼기</label></div>
      <?php endif; ?>
      <button class="btn pri">저장</button>
      <a class="btn" href="?p=church_tv&amp;tab=<?= h($tab) ?>">닫기</a>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      교인만 볼 내용(가족 기도제목 · 경조사 · 연락처)은 넣지 마세요. 이 화면은 로그인 없이 누구나 볼 수 있습니다.
      사진은 가로 사진이 좋고, 큰 사진은 저장할 때 TV 에 맞게 줄입니다.</div>
  </div>
</div>
<?php endif; ?>

<div class="card" id="ctv-bulk">
  <div class="ch">사진 · 영상 한꺼번에 올리기 <span style="font-weight:400;color:var(--ink3)">한 개가 한 장이 되어 글 없이 화면 가득 나옵니다. 영상은 끝까지 재생한 뒤 넘어갑니다.</span></div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data" class="f" id="ctv-bulk-form" style="align-items:flex-end;flex-wrap:wrap"
          action="?p=church_tv&amp;tab=<?= h($tab) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="bulk_upload">
      <?= ctv_group_fields($groups, $groups ? (int)$groups[0]['id'] : null, date('Y-m-d')) ?>
      <div class="fw w4"><label>사진 · 영상 고르기 (여러 개 한 번에 골라도 됩니다)</label>
        <input type="file" name="files[]" multiple required id="ctv-files"
               accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,.mp4,.m4v,.webm,.mov"></div>
      <div class="fw w2"><label>사진 한 장 보여 줄 시간</label>
        <select name="seconds"><?= ctv_seconds_options(10, false) ?></select></div>
      <div class="fw w2"><label>보여 줄 시작일</label><input type="date" name="start_date"></div>
      <div class="fw w2"><label>마지막 날</label><input type="date" name="end_date"></div>
      <div class="fw" style="flex-basis:100%"><label>보여 줄 TV</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
        <?php foreach ($screens as $s): ?>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="screens[]" value="<?= (int)$s['id'] ?>"<?= $tabScreen && (int)$tabScreen['id'] === (int)$s['id'] ? ' checked' : '' ?>> <?= h($s['name']) ?></label>
        <?php endforeach; ?>
        </div></div>
      <button class="btn pri" id="ctv-bulk-btn">올리기</button>
      <span id="ctv-bulk-info" style="font-size:12.5px;color:var(--ink3)"></span>
    </form>
    <div id="ctv-bar" style="display:none;margin-top:10px;height:8px;background:#eee;border-radius:4px;overflow:hidden">
      <div id="ctv-bar-in" style="height:100%;width:0;background:#F2B632;transition:width .3s"></div></div>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      사진은 JPG · PNG · WEBP, 영상은 MP4 가 TV 스틱에서 가장 잘 나옵니다. 영상은 한 개 100MB, 한 번에 모두 합쳐 200MB 까지 올릴 수 있습니다.
      사진마다 시간을 다르게 하려면 아래 목록의 시간 칸에서 바로 고르세요.</div>
  </div>
</div>

<div class="card" id="ctv-yt">
  <div class="ch">유튜브 링크 넣기 <span style="font-weight:400;color:var(--ink3)">링크 한 줄이 한 장이 됩니다. TV 에서 자동 재생하고 끝나면 다음 장으로 넘어갑니다.</span></div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end;flex-wrap:wrap" action="?p=church_tv&amp;tab=<?= h($tab) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="yt_add">
      <?= ctv_group_fields($groups, $groups ? (int)$groups[0]['id'] : null, date('Y-m-d')) ?>
      <div class="fw w6" style="flex-basis:100%"><label>유튜브 주소 (여러 개면 한 줄에 하나씩)</label>
        <textarea name="links" rows="3" required style="width:100%" placeholder="https://youtu.be/...&#10;https://www.youtube.com/watch?v=..."></textarea></div>
      <div class="fw w2"><label>보여 줄 시간</label>
        <select name="seconds"><?= ctv_seconds_options(null, true) ?>
          <?php foreach ([120, 180, 300, 600] as $n): ?><option value="<?= $n ?>"><?= $n / 60 ?>분까지</option><?php endforeach; ?></select></div>
      <div class="fw w2"><label>보여 줄 시작일</label><input type="date" name="start_date"></div>
      <div class="fw w2"><label>마지막 날</label><input type="date" name="end_date"></div>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" name="sound" value="1"> 소리 켜기</label>
      <div class="fw" style="flex-basis:100%"><label>보여 줄 TV</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
        <?php foreach ($screens as $s): ?>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="screens[]" value="<?= (int)$s['id'] ?>"<?= $tabScreen && (int)$tabScreen['id'] === (int)$s['id'] ? ' checked' : '' ?>> <?= h($s['name']) ?></label>
        <?php endforeach; ?>
        </div></div>
      <button class="btn pri">넣기</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      유튜브에서 [공유] &gt; [복사] 한 주소를 붙여 넣으면 됩니다. 실시간 방송처럼 끝이 없는 영상은 '보여 줄 시간'을 꼭 정해 주세요.
      올린 사람이 다른 곳에서 틀지 못하게 막아 둔 영상은 TV 에서 건너뜁니다. 유튜브 광고가 나올 수 있습니다.</div>
  </div>
</div>

<div class="card">
  <div class="ch" style="gap:6px;flex-wrap:wrap;height:auto;min-height:44px">
    <?php foreach ($screens as $s): ?>
      <a class="btn sm<?= $tabScreen && (int)$tabScreen['id'] === (int)$s['id'] ? ' pri' : '' ?>" href="?p=church_tv&amp;tab=<?= h($s['slug']) ?>"><?= h($s['name']) ?></a>
    <?php endforeach; ?>
    <a class="btn sm<?= $tab === 'all' ? ' pri' : '' ?>" href="?p=church_tv&amp;tab=all">전체 슬라이드</a>
    <span style="margin-left:auto;font-weight:400;color:var(--ink3);font-size:12px">
      <?= $tabScreen ? h($tabScreen['name']) . '에 넣은 슬라이드 ' . count($slides) . '장 · 지금 나가는 것 ' . count($liveIds) . '장' : '모든 슬라이드 ' . count($slides) . '장' ?></span>
  </div>
  <?php
    // 묶음 > 날짜 > 슬라이드 로 나누기
    $bunches = [];
    foreach ($slides as $s) { $bunches[(int)($s['group_id'] ?? 0)][(string)($s['post_date'] ?? '')][] = $s; }
    $sections = [];
    if (!empty($bunches[0])) { $sections[] = ['id' => 0, 'name' => '묶음 없음', 'show_caption' => 0]; }
    foreach ($groups as $g) { $sections[] = $g; }
    $editGroup = (int)query('group');
    $today = date('Y-m-d');
    $tabQ = '?p=church_tv&amp;tab=' . h($tab);
  ?>
  <form id="ctv-move" method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid var(--line);font-size:12.5px">
    <?= csrf_field() ?><input type="hidden" name="act" value="move_group">
    <b>체크한 것을</b>
    <select name="group_id" style="height:30px;width:auto;min-width:150px"><option value="0">묶음 없음</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select>
    <span>으로</span>
    <input type="date" name="post_date" title="날짜도 바꾸려면 고르세요 (비우면 그대로)" style="height:30px;width:auto">
    <button class="btn sm pri" name="do" value="move">옮기기</button>
    <span style="color:var(--ink3)">날짜를 비워 두면 올린 날짜 그대로 옮깁니다.</span>
    <button class="btn sm" name="do" value="del" style="margin-left:auto;color:#8A1C1C"
            onclick="var n = document.querySelectorAll('input[form=ctv-move]:checked').length;
                     if (!n) { alert('지울 슬라이드를 체크해 주세요.'); return false; }
                     return confirm('체크한 ' + n + '개를 지울까요? 사진 · 영상 파일도 함께 지워집니다.');">체크한 것 삭제</button>
  </form>
  <?php if (!$slides): ?>
    <div class="empty">슬라이드가 없습니다. [전체 슬라이드] 에서 이 TV 칸을 누르거나 [슬라이드 추가] 를 누르세요.</div>
  <?php endif; ?>
  <?php foreach ($sections as $g):
    $gid = (int)$g['id'];
    $dates = $bunches[$gid] ?? [];
    $cnt = 0; $gIds = [];
    foreach ($dates as $list) { foreach ($list as $x) { $cnt++; $gIds[] = (int)$x['id']; } } ?>
  <div id="g<?= $gid ?>" style="margin-top:6px">
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:10px 14px;background:#FFF8DC;border-top:2px solid #F2C200">
      <?php if ($gid): foreach (['up' => '▲', 'down' => '▼'] as $d => $sym): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="group_move"><input type="hidden" name="id" value="<?= $gid ?>">
          <input type="hidden" name="dir" value="<?= $d ?>"><button class="btn sm" style="padding:0 6px" title="묶음 순서"><?= $sym ?></button></form>
      <?php endforeach; endif; ?>
      <b style="font-size:15px"><?= h($g['name']) ?></b>
      <span style="color:var(--ink3);font-size:12px"><?= $cnt ?>개<?= (int)($g['per_screen'] ?? 1) > 1 ? ' · 사진은 한 화면에 ' . (int)$g['per_screen'] . '장씩' : '' ?></span>
      <?php if ($cnt): ?>
        <span style="margin-left:8px;font-size:12px;color:var(--ink3)">묶음 전체</span>
        <?php foreach ($screens as $sc):
          $all = true; foreach ($gIds as $x) { if (!isset($links[$x][(int)$sc['id']])) { $all = false; } } ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="bunch_screen"><input type="hidden" name="group_id" value="<?= $gid ?>">
          <input type="hidden" name="screen_id" value="<?= (int)$sc['id'] ?>">
          <button class="btn sm<?= $all ? ' pri' : '' ?>" title="<?= $all ? '묶음 전체를 이 TV 에서 빼기' : '묶음 전체를 이 TV 에 넣기' ?>"><?= h(preg_replace('/\s*모니터$/u', '', (string)$sc['name'])) ?></button></form>
        <?php endforeach; ?>
        <?php foreach (['1' => '모두 켜기', '0' => '모두 끄기'] as $on => $lb): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="bunch_active"><input type="hidden" name="group_id" value="<?= $gid ?>">
          <input type="hidden" name="on" value="<?= $on ?>"><button class="btn sm"><?= $lb ?></button></form>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($gid): ?><a class="btn sm" style="margin-left:auto" href="<?= $tabQ ?>&amp;group=<?= $gid ?>#g<?= $gid ?>">이름 · 설정</a><?php endif; ?>
    </div>
    <?php if ($gid && $editGroup === $gid): ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;background:#FFFDF2;font-size:12.5px">
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?>
        <input type="hidden" name="act" value="group_save"><input type="hidden" name="id" value="<?= $gid ?>">
        <input type="text" name="name" value="<?= h($g['name']) ?>" maxlength="60" required style="height:30px;width:220px">
        <label style="display:flex;gap:4px;align-items:center"><input type="checkbox" name="show_caption" value="1" style="width:auto"<?= (int)$g['show_caption'] ? ' checked' : '' ?>>
          TV 아래에 "<?= h($g['name']) ?> · 날짜" 작게 보이기</label>
        <label style="display:flex;gap:4px;align-items:center">글 없는 사진을 한 화면에
          <select name="per_screen" style="height:30px;width:auto">
            <?php foreach (CTV_PER_SCREEN as $n): ?><option value="<?= $n ?>"<?= (int)($g['per_screen'] ?? 1) === $n ? ' selected' : '' ?>><?= $n ?>장</option><?php endforeach; ?>
          </select> 씩</label>
        <button class="btn sm pri">저장</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('묶음을 지울까요? 안의 슬라이드는 지워지지 않고 묶음 없음으로 갑니다.');"><?= csrf_field() ?>
        <input type="hidden" name="act" value="group_del"><input type="hidden" name="id" value="<?= $gid ?>">
        <button class="btn sm">묶음 지우기</button></form>
      <a class="btn sm" href="<?= $tabQ ?>#g<?= $gid ?>">닫기</a>
    </div>
    <?php endif; ?>
    <?php if (!$dates): ?>
      <div style="padding:10px 14px;font-size:12.5px;color:var(--ink3)">아직 없습니다. 위에서 사진 · 영상 · 유튜브를 올릴 때 이 묶음을 고르거나, 아래 목록에서 체크해서 옮기세요.</div>
    <?php endif; ?>
    <?php foreach ($dates as $pd => $list): $pd = (string)$pd; ?>
    <div style="display:flex;gap:6px;align-items:center;padding:8px 14px 4px;font-size:13px">
      <b><?= $pd !== '' ? h(ctv_date_ko($pd)) : '날짜 없음' ?></b>
      <span style="color:var(--ink3);font-size:12px"><?= count($list) ?>개</span>
      <?php if ($pd !== ''): foreach (['1' => '이 날 켜기', '0' => '이 날 끄기'] as $on => $lb): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="act" value="bunch_active"><input type="hidden" name="group_id" value="<?= $gid ?>">
        <input type="hidden" name="post_date" value="<?= h($pd) ?>">
        <input type="hidden" name="on" value="<?= $on ?>"><button class="btn sm"><?= $lb ?></button></form>
      <?php endforeach; endif; ?>
    </div>
  <table>
    <thead><tr><th class="c" style="width:84px">순서</th><th style="width:110px"></th><th>슬라이드</th>
      <th style="width:<?= 70 * count($screens) ?>px">TV</th><th class="c" style="width:140px">시간</th><th class="c" style="width:90px">상태</th><th class="c" style="width:120px"></th></tr></thead>
    <tbody>
    <?php foreach ($list as $s):
      $sid = (int)$s['id'];
      $expired = $s['end_date'] && $s['end_date'] < $today;
      $notyet = $s['start_date'] && $s['start_date'] > $today; ?>
      <tr<?= (int)$s['is_active'] && !$expired ? '' : ' style="opacity:.55"' ?>>
        <td class="c" style="white-space:nowrap">
          <input type="checkbox" name="ids[]" value="<?= $sid ?>" form="ctv-move" style="vertical-align:middle;margin-right:4px">
          <?php foreach (['up' => '▲', 'down' => '▼'] as $d => $sym): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="slide_move"><input type="hidden" name="id" value="<?= $sid ?>">
            <input type="hidden" name="dir" value="<?= $d ?>"><button class="btn sm" style="padding:0 6px"><?= $sym ?></button></form>
          <?php endforeach; ?></td>
        <td><?= ctv_thumb($s) ?></td>
        <td><?php if ($s['label']): ?><div style="font-size:11.5px;color:#A86F00;font-weight:700"><?= h($s['label']) ?></div><?php endif; ?>
          <div style="font-weight:700"><?= $s['title'] !== '' ? h($s['title'])
            : '<span style="color:var(--ink3);font-weight:400">' . (!empty($s['youtube']) ? '유튜브' : (ctv_is_video($s['image_file']) ? '영상' : '사진')) . '만 (글 없음)</span>' ?></div>
          <div style="font-size:12px;color:var(--ink3)"><?= h(mb_strimwidth((string)$s['body'], 0, 90, '…')) ?></div>
          <?php if ($s['start_date'] || $s['end_date']): ?><div style="font-size:11.5px;color:var(--ink3)">기간 <?= h($s['start_date'] ?? '') ?> ~ <?= h($s['end_date'] ?? '') ?></div><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php foreach ($screens as $sc): $in = isset($links[$sid][(int)$sc['id']]); ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="slide_toggle"><input type="hidden" name="id" value="<?= $sid ?>">
            <input type="hidden" name="screen_id" value="<?= (int)$sc['id'] ?>">
            <button class="btn sm<?= $in ? ' pri' : '' ?>" title="<?= $in ? '이 TV 에서 빼기' : '이 TV 에 넣기' ?>"><?= h(preg_replace('/\s*모니터$/u', '', (string)$sc['name'])) ?></button></form>
          <?php endforeach; ?></td>
        <td class="c">
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="slide_seconds"><input type="hidden" name="id" value="<?= $sid ?>">
            <select name="seconds" onchange="this.form.submit()" style="height:28px;font-size:12px;min-width:112px">
              <?= ctv_seconds_options($s['seconds'] !== null ? (int)$s['seconds'] : null, ctv_is_motion($s)) ?></select></form>
          <?php if (ctv_is_motion($s) && (int)($s['sound'] ?? 0)): ?><div style="font-size:11px;color:var(--ink3)">소리 켬</div><?php endif; ?></td>
        <td class="c">
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="act" value="slide_active"><input type="hidden" name="id" value="<?= $sid ?>">
            <button class="btn sm"><?= (int)$s['is_active'] ? '켜짐' : '꺼짐' ?></button></form>
          <?php if ($expired): ?><div style="font-size:11px;color:var(--ink3)">기간 끝남</div>
          <?php elseif ($notyet): ?><div style="font-size:11px;color:var(--ink3)">시작 전</div><?php endif; ?></td>
        <td class="c" style="white-space:nowrap">
          <a class="btn sm" href="?p=church_tv&amp;tab=<?= h($tab) ?>&amp;slide=<?= $sid ?>">수정</a>
          <form method="post" style="display:inline" onsubmit="return confirm('이 슬라이드를 지울까요?');"><?= csrf_field() ?>
            <input type="hidden" name="act" value="slide_del"><input type="hidden" name="id" value="<?= $sid ?>">
            <button class="btn sm">삭제</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:12px 14px;border-top:1px solid var(--line);font-size:12.5px"><?= csrf_field() ?>
    <input type="hidden" name="act" value="group_save"><input type="hidden" name="id" value="0">
    <b>새 묶음 만들기</b>
    <input type="text" name="name" maxlength="60" required placeholder="예: 필리핀 선교" style="height:30px;width:220px">
    <button class="btn sm">만들기</button>
  </form>
</div>
<script>
// 한꺼번에 올리기 — 고른 개수 · 크기를 보여 주고, 올라가는 정도를 막대로 보여 줍니다
(function () {
  var form = document.getElementById('ctv-bulk-form');
  if (!form || !window.FormData || !window.XMLHttpRequest) { return; }
  var input = document.getElementById('ctv-files'), info = document.getElementById('ctv-bulk-info');
  var btn = document.getElementById('ctv-bulk-btn');
  var bar = document.getElementById('ctv-bar'), barIn = document.getElementById('ctv-bar-in');
  function total() { var t = 0; for (var i = 0; i < input.files.length; i++) { t += input.files[i].size; } return t; }
  input.addEventListener('change', function () {
    var n = input.files.length, v = 0;
    for (var i = 0; i < n; i++) { if (/^video\//.test(input.files[i].type) || /\.(mp4|m4v|webm|mov)$/i.test(input.files[i].name)) { v++; } }
    info.textContent = n ? n + '개 고름' + (v ? ' (영상 ' + v + '개)' : '') + ' · ' + (total() / 1048576).toFixed(1) + 'MB' : '';
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!input.files.length) { return; }
    if (!form.querySelector('input[name="screens[]"]:checked')) { alert('보여 줄 TV 를 하나 이상 골라 주세요.'); return; }
    if (total() > 200 * 1048576) { alert('한 번에 200MB 까지 올릴 수 있습니다. 나눠서 올려 주세요.'); return; }
    var x = new XMLHttpRequest();
    x.open('POST', form.getAttribute('action') + '&ajax=1', true);
    btn.disabled = true; btn.textContent = '올리는 중…'; bar.style.display = '';
    x.upload.onprogress = function (ev) { if (ev.lengthComputable) { barIn.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; } };
    x.onload = function () {
      var d = null;
      try { d = JSON.parse(x.responseText); } catch (er) {}
      if (d && d.url) { location.href = d.url; return; }
      alert(d && d.msg ? d.msg : '올리지 못했습니다. 다시 해 주세요.');
      btn.disabled = false; btn.textContent = '올리기'; bar.style.display = 'none';
    };
    x.onerror = function () { alert('인터넷이 끊겨 올리지 못했습니다.'); btn.disabled = false; btn.textContent = '올리기'; };
    x.send(new FormData(form));
  });
})();
function ctvCopy(t) {
  if (navigator.clipboard) { navigator.clipboard.writeText(t).then(function () { alert('주소를 복사했습니다.\n' + t); }); }
  else { prompt('주소를 복사하세요', t); }
}
</script>
<?php
layout_foot();
