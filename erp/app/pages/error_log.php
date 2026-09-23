<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 오류 기록 — 화면에 500 이 떴을 때 무엇이 잘못됐는지 여기서 봅니다.
 * 서버 로그를 열어 볼 수 없는 환경이라 bootstrap 의 gp_log_error 가 파일로 남긴 것을 보여 줍니다.
 */

$dir  = storage_root() . DIRECTORY_SEPARATOR . 'logs';
$path = $dir . DIRECTORY_SEPARATOR . 'php_errors.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'clear') {
    csrf_check();
    @unlink($path);
    @unlink($path . '.1');
    log_action('시스템', 'DELETE', null, null, '오류 기록', null, '오류 기록 지움');
    flash('오류 기록을 지웠습니다.');
    redirect('?p=error_log');
}

$lines = [];
foreach ([$path, $path . '.1'] as $f) {
    if (is_file($f)) {
        $txt = (string)@file_get_contents($f);
        foreach (preg_split('/\r\n|\r|\n/', trim($txt)) as $l) {
            if ($l !== '') { $lines[] = $l; }
        }
    }
}
$lines = array_slice(array_reverse($lines), 0, 400);
$size  = is_file($path) ? filesize($path) : 0;

layout_head('오류 기록', 'error_log');
?>
<div class="head">
  <h1>오류 기록</h1>
  <div class="crumb">시스템 &gt; 오류 기록</div>
  <div class="right">
    <?php if ($lines): ?>
      <form method="post" onsubmit="return confirm('오류 기록을 모두 지울까요?')">
        <?= csrf_field() ?><input type="hidden" name="act" value="clear">
        <button class="btn">기록 지우기</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="ch">최근 오류 <span style="font-weight:400;color:var(--ink3)">새것부터 · 최대 400줄</span></div>
  <?php if (!$lines): ?>
    <div class="empty">기록된 오류가 없습니다. 화면에 500 이 떴다면 그 뒤에 새로고침하고 다시 보세요.</div>
  <?php else: ?>
    <div class="cb">
      <pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:12px;line-height:1.7;
                  max-height:70vh;overflow:auto"><?= h(implode("\n", $lines)) ?></pre>
    </div>
    <div class="pager"><span>
      파일 <?= h($path) ?> · <?= number_format((int)$size) ?> B.
      한 오류는 두 줄입니다 — 첫 줄은 시간 · 번호 · 종류 · 사용자 · 파일:줄 · 주소, 둘째 줄은 내용입니다.
    </span></div>
  <?php endif; ?>
</div>
<?php layout_foot();
