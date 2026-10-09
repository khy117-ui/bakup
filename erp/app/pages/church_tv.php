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
 *   · 아래: 슬라이드 — TV 마다 넣고 빼기, 순서, 기간, 시간, 사진 · 영상
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
            $ins = $pdo->prepare('INSERT INTO church_tv_slides (label, title, image_file, seconds, is_active, start_date, end_date, sort_no)
                                  VALUES (?, \'\', ?, ?, 1, ?, ?, ?)');
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
                    $ins->execute([mb_substr(post('label'), 0, 40), $name, $isV ? null : $sec, $sd, $ed, ++$sort]);
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
            $ins = $pdo->prepare('INSERT INTO church_tv_slides (label, title, youtube, seconds, sound, is_active, start_date, end_date, sort_no)
                                  VALUES (\'\', \'\', ?, ?, ?, 1, ?, ?, ?)');
            $link = $pdo->prepare('INSERT IGNORE INTO church_tv_slide_screens (slide_id, screen_id) VALUES (?,?)');
            $ok = 0; $bad = [];
            foreach (preg_split('/\s+/', post('links')) as $line) {
                if ($line === '') { continue; }
                $yid = ctv_youtube_id($line);
                if ($yid === null) { $bad[] = mb_strimwidth($line, 0, 40, '…'); continue; }
                $ins->execute([$yid, $sec, post('sound') === '1' ? 1 : 0, $sd, $ed, ++$sort]);
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
            ];
            if ($id > 0) {
                $vals[] = $id;
                $pdo->prepare('UPDATE church_tv_slides SET label=?, title=?, body=?, date_text=?, image_file=?, theme=?,
                               seconds=?, sound=?, is_active=?, start_date=?, end_date=?, youtube=?, updated_at=NOW() WHERE id = ?')->execute($vals);
            } else {
                $vals[] = (int)$pdo->query('SELECT COALESCE(MAX(sort_no),0)+1 FROM church_tv_slides')->fetchColumn();
                $pdo->prepare('INSERT INTO church_tv_slides (label, title, body, date_text, image_file, theme, seconds, sound,
                               is_active, start_date, end_date, youtube, sort_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
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
            // 위 · 아래 화살표: 이웃과 순서를 맞바꿉니다
            $ids = $pdo->query('SELECT id FROM church_tv_slides ORDER BY sort_no, id')->fetchAll(PDO::FETCH_COLUMN);
            $i = array_search((int)post('id'), array_map('intval', $ids), true);
            $j = $i === false ? false : (post('dir') === 'up' ? $i - 1 : $i + 1);
            if ($i !== false && $j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                $up = $pdo->prepare('UPDATE church_tv_slides SET sort_no = ? WHERE id = ?');
                foreach ($ids as $n => $sid) { $up->execute([$n + 1, (int)$sid]); }
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
$allSlides = $pdo->query('SELECT * FROM church_tv_slides ORDER BY sort_no, id')->fetchAll();
$slides = $tabScreen
    ? array_values(array_filter($allSlides, fn($s) => isset($links[(int)$s['id']][(int)$tabScreen['id']])))
    : $allSlides;
$liveIds = $tabScreen ? array_map(fn($s) => (int)$s['id'], ctv_slides_for((int)$tabScreen['id'])) : [];

$editSlide = null;
if (query('slide') === 'new') {
    $editSlide = ['id' => 0, 'label' => '', 'title' => '', 'body' => '', 'date_text' => '', 'image_file' => null,
                  'theme' => 'morning', 'seconds' => null, 'sound' => 0, 'youtube' => null, 'is_active' => 1, 'start_date' => null, 'end_date' => null];
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
  <?php if (!$slides): ?>
    <div class="empty">슬라이드가 없습니다. [전체 슬라이드] 에서 이 TV 칸을 누르거나 [슬라이드 추가] 를 누르세요.</div>
  <?php else: ?>
  <table>
    <thead><tr><th class="c" style="width:60px">순서</th><th style="width:110px"></th><th>슬라이드</th>
      <th style="width:<?= 70 * count($screens) ?>px">TV</th><th class="c" style="width:140px">시간</th><th class="c" style="width:90px">상태</th><th class="c" style="width:120px"></th></tr></thead>
    <tbody>
    <?php foreach ($slides as $s):
      $sid = (int)$s['id'];
      $today = date('Y-m-d');
      $expired = $s['end_date'] && $s['end_date'] < $today;
      $notyet = $s['start_date'] && $s['start_date'] > $today; ?>
      <tr<?= (int)$s['is_active'] && !$expired ? '' : ' style="opacity:.55"' ?>>
        <td class="c" style="white-space:nowrap">
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
  <?php endif; ?>
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
