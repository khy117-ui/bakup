<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 작업로그 — 누가 무엇을 언제 바꿨는지.
 * 금액과 거래처가 걸린 시스템이라 이 화면이 사후 확인의 마지막 수단입니다.
 */

$ACTION = [
    'CREATE' => ['등록', 'b-ok'], 'UPDATE' => ['수정', 'b-info'],
    'DELETE' => ['삭제', 'b-err'], 'CANCEL' => ['취소', 'b-err'],
    'ISSUE'  => ['발행', 'b-info'], 'CONVERT' => ['전환', 'b-ok'],
    'PRINT'  => ['출력', 'b-warn'], 'DOWNLOAD' => ['다운로드', 'b-warn'],
    'LOGIN'  => ['로그인', 'b-info'], 'LOGOUT' => ['로그아웃', 'b-info'],
    'EXPORT' => ['내보내기', 'b-warn'],
];

$kw     = query('kw');
$module = query('module');
$action = query('action');
$who    = query('who');
$from   = query('from');
$to     = query('to');
$page   = max(1, (int)query('page', '1'));
$per    = 40;
$off    = ($page - 1) * $per;

$where  = ['1=1'];
$params = [];
if ($kw !== '') {
    $where[] = '(l.ref_label LIKE ? OR l.reason LIKE ? OR l.before_value LIKE ?
                 OR l.after_value LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($module !== '') { $where[] = 'l.module = ?';      $params[] = $module; }
if (isset($ACTION[$action])) { $where[] = 'l.action = ?'; $params[] = $action; }
if ($who !== '')    { $where[] = 'l.admin_name = ?';  $params[] = $who; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'l.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'l.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM activity_logs l WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT l.* FROM activity_logs l WHERE $w
                      ORDER BY l.id DESC LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

$modules = db()->query('SELECT module, COUNT(*) c FROM activity_logs
                         GROUP BY module ORDER BY c DESC')->fetchAll();
$people  = db()->query('SELECT admin_name, COUNT(*) c FROM activity_logs
                         GROUP BY admin_name ORDER BY c DESC LIMIT 30')->fetchAll();

// 오늘 무슨 일이 있었는지 한눈에
$today = date('Y-m-d');
$st = db()->prepare(
    "SELECT COUNT(*) FROM activity_logs WHERE created_at >= ?");
$st->execute([$today . ' 00:00:00']);
$todayCnt = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT COUNT(*) FROM activity_logs
      WHERE created_at >= ? AND action IN ('DELETE','CANCEL')");
$st->execute([$today . ' 00:00:00']);
$todayRisk = (int)$st->fetchColumn();

layout_head('작업로그', 'activity_log');
?>
<div class="head">
  <h1>작업로그</h1>
  <div class="crumb">시스템 &gt; 작업로그</div>
</div>

<div class="kpis">
  <div class="kpi"><div class="lab">오늘 작업</div>
    <div class="val tnum"><?= money($todayCnt) ?></div>
    <div class="sub"><?= h($today) ?></div></div>
  <div class="kpi"><div class="lab">오늘 삭제 · 취소</div>
    <div class="val tnum" style="color:<?= $todayRisk>0?'var(--err-fg)':'var(--ink)' ?>">
      <?= money($todayRisk) ?></div>
    <div class="sub">되돌릴 일이 생기면 여기부터 봅니다</div></div>
  <div class="kpi"><div class="lab">전체 기록</div>
    <div class="val tnum"><?= money($total) ?></div>
    <div class="sub">조건에 맞는 건수</div></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="activity_log">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>"
             placeholder="AWB · 업체명 · 사유 · 변경 내용"></div>
    <div class="fw w1"><label for="md">모듈</label>
      <select id="md" name="module">
        <option value="">전체</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= h($m['module']) ?>"<?= $module===$m['module']?' selected':'' ?>>
            <?= h($m['module']) ?> (<?= (int)$m['c'] ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label for="ac">작업</label>
      <select id="ac" name="action">
        <option value="">전체</option>
        <?php foreach ($ACTION as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $action===$k?' selected':'' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label for="wh">담당</label>
      <select id="wh" name="who">
        <option value="">전체</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= h($p['admin_name']) ?>"<?= $who===$p['admin_name']?' selected':'' ?>>
            <?= h($p['admin_name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label for="f">시작일</label>
      <input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">종료일</label>
      <input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">검색</button>
    <a class="btn" href="?p=activity_log">초기화</a>
  </form>
</div></div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">조건에 맞는 기록이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:150px">일시</th><th style="width:85px">모듈</th>
      <th class="c" style="width:85px">작업</th><th style="width:85px">담당</th>
      <th style="width:120px">대상</th><th>사유 · 변경 내용</th>
      <th style="width:110px">IP</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      [$lab, $cls] = $ACTION[$r['action']] ?? [$r['action'], 'b-info']; ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h($r['created_at']) ?></td>
        <td><?= h($r['module']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td><?= h($r['admin_name']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['ref_label'] ?: '-') ?></td>
        <td style="font-size:11.5px">
          <?php if ($r['reason']): ?>
            <b><?= h($r['reason']) ?></b><br>
          <?php endif; ?>
          <?php if ($r['before_value']): ?>
            <span style="color:var(--ink3)">전 · <?= h(mb_strimwidth((string)$r['before_value'], 0, 140, '…')) ?></span><br>
          <?php endif; ?>
          <?php if ($r['after_value']): ?>
            <span style="color:var(--ink3)">후 · <?= h(mb_strimwidth((string)$r['after_value'], 0, 140, '…')) ?></span>
          <?php endif; ?>
          <?php if (!$r['reason'] && !$r['before_value'] && !$r['after_value']): ?>
            <span style="color:var(--ink3)">-</span>
          <?php endif; ?>
        </td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs = 'p=activity_log&kw=' . urlencode($kw) . '&module=' . urlencode($module)
                . '&action=' . urlencode($action) . '&who=' . urlencode($who)
                . '&from=' . urlencode($from) . '&to=' . urlencode($to); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">이 기록으로 무엇을 할 수 있나</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · <b>금액이 이상할 때</b> — 해당 AWB 로 검색하면 언제 누가 얼마에서 얼마로 바꿨는지 나옵니다.
      매출전표 수정에는 사유 입력이 필수라 이유도 같이 남습니다.<br>
    · <b>거래처가 사라졌을 때</b> — 모듈 <b>거래처</b> + 작업 <b>삭제</b> 로 거르면 바로 보입니다.
      실제 행은 지워지지 않고 <code>deleted_at</code> 만 찍히므로 되돌릴 수 있습니다.<br>
    · <b>문서가 밖으로 나간 기록</b> — 작업 <b>출력</b> · <b>다운로드</b> 로 거르면
      누가 인보이스를 뽑았고 어떤 파일을 받아갔는지 남아 있습니다.<br>
    · 이 로그는 <b>화면에서 지울 수 없습니다.</b> 보관 기간은 환경설정에서 정합니다.
  </div>
</div>
<?php layout_foot();
