<?php
/**
 * 교회 TV 화면 — 송출 입구 (로그인 없음)
 *
 *   GET /erp/tv.php?s=1f          TV 스틱 브라우저에 넣는 주소 (전체 화면 슬라이드)
 *   GET /erp/tv.php?s=1f&feed=1   TV 가 1분마다 받아 가는 내용 (JSON)
 *   GET /erp/tv.php?img=12        슬라이드 사진
 *   GET /erp/tv.php?a=logo.png    기본 사진 · 로고
 *
 * 내용은 ERP > 시스템 > 교회 TV 화면 에서 정합니다. TV 에서는 아무것도 누르지 않습니다.
 * 낡은 안드로이드 TV 브라우저에서도 돌도록 스크립트는 옛 문법(ES5)만 씁니다.
 */
declare(strict_types=1);

define('GP_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/church_tv.php';

header('X-Robots-Tag: noindex');

// ---- 올린 파일 확인용 (파일마다 앞 8자리 지문만 보여 줍니다)
if (isset($_GET['chk'])) {
    header('Content-Type: text/plain; charset=utf-8');
    foreach (['index.php', 'app/layout.php', 'app/church_tv.php', 'app/pages/church_tv.php', 'tv.php',
              'app/church_tv_assets.php'] as $f) {
        echo $f, ' ', is_file(__DIR__ . '/' . $f) ? substr(md5_file(__DIR__ . '/' . $f), 0, 8) : 'none', "\n";
    }
    exit;
}

// ---- 기본 사진 · 로고
if (isset($_GET['a'])) {
    $assets = require APP_DIR . '/church_tv_assets.php';
    $name = (string)$_GET['a'];
    if (!isset($assets[$name])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    echo base64_decode($assets[$name]);
    exit;
}

try {
    ctv_ensure_tables();
} catch (PDOException $e) {
    error_log('tv.php DB: ' . $e->getMessage());
    http_response_code(503);
    exit('잠시 후 다시 열어 주세요.');
}

// ---- 사진
if (isset($_GET['img'])) {
    $st = db()->prepare('SELECT image_file FROM church_tv_slides WHERE id = ?');
    $st->execute([(int)$_GET['img']]);
    $f = (string)($st->fetchColumn() ?: '');
    $path = ctv_image_dir() . DIRECTORY_SEPARATOR . basename($f);
    if ($f === '' || str_starts_with($f, 'asset:') || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=604800');
    readfile($path);
    exit;
}

$slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', query('s', '1f')));
$scr = ctv_screen_by_slug($slug);

// ---- TV 가 받아 가는 내용
if (isset($_GET['feed'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$scr) {
        echo json_encode(['ok' => false, 'gone' => true]);
        exit;
    }
    echo json_encode(ctv_feed($scr), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Cache-Control: no-store');
if (!$scr) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><body style="background:#000;color:#666;font:20px sans-serif;'
       . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
       . '없는 화면 주소입니다. ERP &gt; 교회 TV 화면에서 주소를 확인해 주세요.</body>';
    exit;
}
$first = ctv_feed($scr);
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($scr['name']) ?> · 시온의빛교회</title>
<style>
  html, body { margin: 0; height: 100%; background: #000; overflow: hidden; cursor: none; }
  body { font-family: "Pretendard", "Noto Sans KR", "Malgun Gothic", "Apple SD Gothic Neo", sans-serif;
         -webkit-font-smoothing: antialiased; }
  .stage { position: fixed; left: 0; top: 0; right: 0; bottom: 0; overflow: hidden; }
  .slide { position: absolute; left: 0; top: 0; right: 0; bottom: 0; opacity: 0;
           -webkit-transition: opacity 1.4s ease, -webkit-transform 1.4s ease; transition: opacity 1.4s ease, transform 1.4s ease; }
  .slide.on { opacity: 1; }
  .t-slide .slide { -webkit-transform: translateX(6%); transform: translateX(6%); }
  .t-slide .slide.on { -webkit-transform: none; transform: none; }
  .t-slide .slide.out { -webkit-transform: translateX(-6%); transform: translateX(-6%); }
  .t-zoom .slide { -webkit-transform: scale(.94); transform: scale(.94); }
  .t-zoom .slide.on { -webkit-transform: none; transform: none; }
  .bg { position: absolute; left: -4%; top: -4%; width: 108%; height: 108%;
        background-size: cover; background-position: center; }
  .bg.photo { -webkit-filter: saturate(1.05); filter: saturate(1.05); }
  .on .bg { -webkit-animation: kb 30s linear forwards; animation: kb 30s linear forwards; }
  .kb2 .bg { -webkit-animation-name: kb2; animation-name: kb2; }
  @-webkit-keyframes kb { from { -webkit-transform: scale(1) translate(0, 0); } to { -webkit-transform: scale(1.08) translate(-2%, -1%); } }
  @keyframes kb { from { transform: scale(1) translate(0, 0); } to { transform: scale(1.08) translate(-2%, -1%); } }
  @-webkit-keyframes kb2 { from { -webkit-transform: scale(1.08) translate(-2%, 0); } to { -webkit-transform: scale(1) translate(1%, 1%); } }
  @keyframes kb2 { from { transform: scale(1.08) translate(-2%, 0); } to { transform: scale(1) translate(1%, 1%); } }
  .shade { position: absolute; left: 0; top: 0; right: 0; bottom: 0;
           background: linear-gradient(180deg, rgba(0,0,0,.05) 0%, rgba(0,0,0,.35) 100%); }
  .th-morning { background: linear-gradient(135deg, #FFF7DD 0%, #F6D776 55%, #F2B632 100%); }
  .th-navy    { background: linear-gradient(135deg, #1B2A4A 0%, #23406E 60%, #2F5A8F 100%); }
  .th-green   { background: linear-gradient(135deg, #1F3B2E 0%, #2E5A43 60%, #4A7C59 100%); }
  .th-plum    { background: linear-gradient(135deg, #3A2140 0%, #5A3463 60%, #7A4B84 100%); }
  .box { position: absolute; bottom: 9%; max-width: 62%; padding: 3.2vw 3.6vw 3vw;
         background: rgba(255,255,255,.90); color: #111; border-radius: 1.2vw;
         box-shadow: 0 1.2vw 3vw rgba(0,0,0,.28); border-left: .7vw solid #F2B632; }
  .box.p-left { left: 6%; } .box.p-right { right: 6%; }
  .box.p-center { left: 50%; -webkit-transform: translateX(-50%); transform: translateX(-50%); text-align: center;
                  border-left: 0; border-top: .6vw solid #F2B632; }
  .box.p-up { bottom: auto; top: 16%; }
  .lbl { font-size: 1.6vw; font-weight: 700; color: #A86F00; letter-spacing: .02em; margin-bottom: 1vw; }
  .ttl { font-size: 4.2vw; font-weight: 800; line-height: 1.22; letter-spacing: -.02em; word-break: keep-all; }
  .bdy { font-size: 2.1vw; line-height: 1.5; color: #333; margin-top: 1.4vw; word-break: keep-all;
         display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
  .dt { font-size: 1.5vw; color: #777; margin-top: 1.2vw; }
  .f-large .lbl { font-size: 2vw; } .f-large .ttl { font-size: 5.2vw; }
  .f-large .bdy { font-size: 2.7vw; -webkit-line-clamp: 2; } .f-large .dt { font-size: 1.9vw; }
  .f-large .box { max-width: 76%; }
  .corner { position: fixed; display: flex; align-items: center; gap: 1vw; opacity: .55; color: #fff;
            font-size: 1.7vw; font-weight: 600; text-shadow: 0 1px 4px rgba(0,0,0,.6); z-index: 5;
            -webkit-transition: opacity 1s; transition: opacity 1s; }
  .corner img { height: 2.4vw; background: rgba(255,255,255,.85); padding: .3vw .6vw; border-radius: .4vw; }
  .c0 { top: 2.4vh; right: 2.4vw; } .c1 { top: 2.4vh; left: 2.4vw; }
  .c2 { bottom: 2.4vh; right: 2.4vw; } .c3 { bottom: 2.4vh; left: 2.4vw; }
  .black { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: #000; z-index: 9; display: none; }
  .empty { position: fixed; left: 0; top: 0; right: 0; bottom: 0; display: flex; align-items: center;
           justify-content: center; color: #555; font-size: 2vw; }
</style>
</head>
<body>
<div class="stage" id="stage"></div>
<div class="corner c0" id="corner"><img src="tv.php?a=logo.png" alt=""><span id="clock"></span></div>
<div class="black" id="black"></div>
<script>
(function () {
  var FEED = 'tv.php?s=<?= rawurlencode($slug) ?>&feed=1';
  var data = <?= json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var stage = document.getElementById('stage');
  var black = document.getElementById('black');
  var corner = document.getElementById('corner');
  var clock = document.getElementById('clock');
  var idx = -1, timer = null, cur = null, posN = 0, loadedAt = new Date().getTime();
  var POS = ['p-left', 'p-right p-up', 'p-center', 'p-right', 'p-left p-up'];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function applyScreen() {
    var s = data.screen;
    stage.className = 'stage t-' + s.transition + (s.font === 'large' ? ' f-large' : '');
    corner.style.display = s.clock ? '' : 'none';
    black.style.display = data.on ? 'none' : 'block';
  }

  function boxPos() {
    var b = data.screen.box;
    if (b === 'left' || b === 'right' || b === 'center') { return 'p-' + b; }
    posN = (posN + 1) % POS.length;
    return POS[posN];
  }

  function build(sl, n) {
    var el = document.createElement('div');
    el.className = 'slide' + (n % 2 ? ' kb2' : '');
    var bg = sl.img
      ? '<div class="bg photo" style="background-image:url(\'' + esc(sl.img) + '\')"></div><div class="shade"></div>'
      : '<div class="bg th-' + esc(sl.theme || 'morning') + '"></div>';
    el.innerHTML = bg
      + '<div class="box ' + boxPos() + '">'
      + (sl.label ? '<div class="lbl">' + esc(sl.label) + '</div>' : '')
      + '<div class="ttl">' + esc(sl.title) + '</div>'
      + (sl.body ? '<div class="bdy">' + esc(sl.body) + '</div>' : '')
      + (sl.date ? '<div class="dt">' + esc(sl.date) + '</div>' : '')
      + '</div>';
    return el;
  }

  function next() {
    clearTimeout(timer);
    var list = data.slides || [];
    if (!list.length) {
      stage.innerHTML = '<div class="empty">ERP &gt; 교회 TV 화면에서 이 화면에 보여 줄 슬라이드를 넣어 주세요.</div>';
      cur = null;
      timer = setTimeout(next, 15000);
      return;
    }
    idx = (idx + 1) % list.length;
    var sl = list[idx];
    var el = build(sl, idx);
    stage.appendChild(el);
    var old = cur;
    cur = el;
    // 다음 그림에서 클래스를 바꿔야 전환 효과가 보입니다
    setTimeout(function () {
      el.className += ' on';
      if (old) {
        old.className = old.className.replace(' on', '') + ' out';
        setTimeout(function () { if (old.parentNode) { old.parentNode.removeChild(old); } }, 1600);
      }
    }, 60);
    var sec = sl.seconds || data.screen.seconds || 10;
    timer = setTimeout(next, sec * 1000);
  }

  function tick() {
    var d = new Date();
    var h = d.getHours(), m = d.getMinutes();
    clock.innerHTML = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }

  // 잔상 방지 — 시계 · 로고는 3분마다 모서리를 옮깁니다
  var cn = 0;
  function moveCorner() {
    cn = (cn + 1) % 4;
    corner.className = 'corner c' + cn;
  }

  function poll() {
    var x = new XMLHttpRequest();
    x.open('GET', FEED + '&t=' + new Date().getTime(), true);
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      if (x.status !== 200) { return; }            // 인터넷이 잠깐 끊겨도 보던 화면은 계속 돕니다
      var d;
      try { d = JSON.parse(x.responseText); } catch (e) { return; }
      if (!d || !d.ok) { return; }
      if (d.version !== data.version) { location.reload(); return; }   // 관리자가 '지금 새로고침' 을 누름
      var changed = JSON.stringify(d.slides) !== JSON.stringify(data.slides);
      data = d;
      applyScreen();
      if (changed) { idx = -1; next(); }
    };
    x.send();
  }

  applyScreen();
  tick();
  next();
  setInterval(tick, 10000);
  setInterval(moveCorner, 180000);
  setInterval(poll, 60000);
  // 6시간마다 새로 엽니다 (오래 켜 둔 TV 브라우저 메모리 정리)
  setInterval(function () {
    if (new Date().getTime() - loadedAt > 6 * 3600 * 1000) { location.reload(); }
  }, 600000);
})();
</script>
</body>
</html>
