<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$sid = (int)query('shipment_id', '0');

// 올릴 수 있는 형식 · 크기(DOC_EXT · DOC_MAX)와 저장은 bootstrap 의 doc_store_upload()

$types = db()->query('SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order')
             ->fetchAll();

$sh = null;
if ($sid > 0) {
    $st = db()->prepare(
        'SELECT s.id, s.awb_no, s.voucher_date, c.name_ko, c.id AS cid
           FROM shipments s JOIN companies c ON c.id = s.company_id
          WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
    $st->execute([$sid, $eid]);
    $sh = $st->fetch();
    if (!$sh) { exit('전표를 찾을 수 없습니다.'); }
}

/** 업로드 보관 경로. bootstrap 의 storage_root() 가 환경에 맞는 곳을 고릅니다 */
function doc_root(): string
{
    return storage_root();
}

// ---------------------------------------------------------------- 업로드
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'upload') {
    csrf_check();
    $tid   = (int)post('document_type_id');
    $shId  = (int)post('shipment_id');
    $cid   = (int)post('company_id');
    $title = post('title');
    $f     = $_FILES['file'] ?? null;

    // 여러 개(files[]) — 매출전표 화면의 관련서류에서 한 번에 올립니다
    $many = uploaded_files('files');
    if ($many) {
        if ($shId > 0) {
            // 전표의 사업자 · 거래처를 그대로 따릅니다 (다른 사업자 전표에는 못 붙임)
            $st = db()->prepare('SELECT company_id FROM shipments
                                  WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
            $st->execute([$shId, $eid]);
            if (($cid = (int)$st->fetchColumn()) === 0) { exit('전표를 찾을 수 없습니다.'); }
        }
        $ok = 0;
        $bad = [];
        foreach ($many as $one) {
            $e = doc_store_upload($one, $eid, $tid, $shId ?: null, $cid ?: null);
            if ($e === '') { $ok++; } else { $bad[] = $e; }
        }
        flash(($ok ? '서류 ' . $ok . '개를 올렸습니다.' : '올린 서류가 없습니다.')
              . ($bad ? ' 못 올린 것: ' . implode(' / ', $bad) : ''));
        redirect(query('back') === 'sf' && $shId > 0
                 ? '?p=shipment_form&id=' . $shId
                 : '?p=documents' . ($shId ? '&shipment_id=' . $shId : ''));
    } elseif (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $err = '파일을 고르세요.';
        if (query('back') === 'sf' && $shId > 0) {
            flash('올릴 파일을 고르세요.');
            redirect('?p=shipment_form&id=' . $shId);
        }
    } else {
        $err = doc_store_upload($f, $eid, $tid, $shId ?: null, $cid ?: null, $title, post('doc_date') ?: null);
        if ($err === '') {
            flash('문서를 올렸습니다.');
            redirect('?p=documents' . ($shId ? '&shipment_id=' . $shId : ''));
        }
    }
}

// ---------------------------------------------------------------- 삭제(비활성)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'remove') {
    csrf_check();
    $did = (int)post('id');
    // 파일은 지우지 않고 목록에서만 내립니다. 실수로 지운 경우를 되돌릴 수 있게
    db()->prepare('UPDATE documents SET deleted_at = NOW()
                    WHERE id = ? AND business_entity_id = ?')->execute([$did, $eid]);
    log_action('문서', 'DELETE', 'documents', $did, null, null, '목록에서 내림 (파일은 보존)');
    flash('문서를 목록에서 내렸습니다. 파일 자체는 서버에 남아 있습니다.');
    // 매출전표 화면의 관련서류 목록에서 내린 경우 그 전표로 돌아갑니다
    if (query('back') === 'sf' && $sid > 0) {
        redirect('?p=shipment_form&id=' . $sid);
    }
    redirect('?p=documents' . ($sid ? '&shipment_id=' . $sid : ''));
}

// ---------------------------------------------------------------- 목록
$kw    = query('kw');
$selTy = (int)query('type', '0');
$where = ['d.business_entity_id = ?', 'd.deleted_at IS NULL'];
$params = [$eid];
if ($sid > 0) { $where[] = 'd.shipment_id = ?'; $params[] = $sid; }
if ($selTy > 0) { $where[] = 'd.document_type_id = ?'; $params[] = $selTy; }
if ($kw !== '') {
    $where[] = '(d.title LIKE ? OR d.original_name LIKE ? OR s.awb_no LIKE ? OR c.name_ko LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
$w = implode(' AND ', $where);

$st = db()->prepare(
    "SELECT d.*, t.name AS type_name, s.awb_no, c.name_ko
       FROM documents d
       JOIN document_types t ON t.id = d.document_type_id
       LEFT JOIN shipments s ON s.id = d.shipment_id
       LEFT JOIN companies c ON c.id = d.company_id
      WHERE $w ORDER BY d.created_at DESC, d.id DESC LIMIT 200");
$st->execute($params);
$rows = $st->fetchAll();

$companies = db()->prepare('SELECT id, company_code, name_ko FROM companies WHERE deleted_at IS NULL
                             ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

layout_head('문서보관함', 'documents');
?>
<div class="head">
  <h1>문서보관함</h1>
  <div class="crumb">물류관리 &gt; 문서보관함<?= $sh ? ' &gt; ' . h($sh['awb_no']) : '' ?></div>
  <?php if ($sh): ?>
    <div class="right">
      <a class="btn" href="?p=documents">전체 문서</a>
      <a class="btn" href="?p=shipment_form&amp;id=<?= $sid ?>">전표 열기</a></div>
  <?php endif; ?>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">문서 올리기
    <?php if ($sh): ?>
      <span style="font-weight:400;color:var(--ink3)">
        <?= h($sh['awb_no']) ?> · <?= h($sh['name_ko']) ?> 에 붙습니다</span>
    <?php endif; ?>
  </div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <input type="hidden" name="shipment_id" value="<?= $sid ?>">
      <div class="fw w2"><label>문서 종류 *</label>
        <select name="document_type_id" required>
          <option value="">선택</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if (!$sh): ?>
      <div class="fw w2"><label>거래처</label>
        <select name="company_id">
          <option value="">해당없음</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <?php else: ?>
        <input type="hidden" name="company_id" value="<?= (int)$sh['cid'] ?>">
      <?php endif; ?>
      <div class="fw w1"><label>문서 일자</label>
        <input type="date" name="doc_date" value="<?= h($sh['voucher_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="fw w2"><label>제목 (비우면 파일명)</label>
        <input type="text" name="title"></div>
      <div class="fw w3"><label>파일 *</label>
        <input type="file" name="file" required
               style="height:34px;padding:5px 8px;border:1px solid #D3DEE6;border-radius:5px;background:#fff"></div>
      <button class="btn pri">올리기</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      <?= DOC_MAX_LABEL ?> 까지 · <?= h(implode(', ', DOC_EXT)) ?> 만 올릴 수 있습니다.
      파일은 <b>웹에서 직접 열 수 없는 위치</b>에 저장되고, 로그인한 사람만 이 화면을 통해 내려받습니다.
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">문서 목록
    <form class="f" method="get" style="margin-left:auto;align-items:flex-end;gap:8px">
      <input type="hidden" name="p" value="documents">
      <?php if ($sid): ?><input type="hidden" name="shipment_id" value="<?= $sid ?>"><?php endif; ?>
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="제목 · AWB · 거래처" style="width:200px">
      <select name="type" style="width:150px">
        <option value="0">전체 종류</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= $selTy===(int)$t['id']?' selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm">검색</button>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">문서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:130px">종류</th><th>제목</th>
      <th style="width:150px">AWB</th><th style="width:130px">거래처</th>
      <th style="width:100px">문서일자</th><th class="r" style="width:85px">크기</th>
      <th class="c" style="width:85px">백업</th><th style="width:140px">올린 일시</th>
      <th class="c" style="width:120px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><span class="badge b-info"><?= h($r['type_name']) ?></span></td>
        <td style="font-weight:600"><?= h($r['title']) ?>
          <?php if ($r['title'] !== $r['original_name']): ?>
            <span style="font-weight:400;color:var(--ink3);font-size:11.5px">
              · <?= h($r['original_name']) ?></span>
          <?php endif; ?></td>
        <td class="tnum" style="font-size:11.5px"><?= $r['awb_no']
            ? '<a href="?p=documents&amp;shipment_id=' . (int)$r['shipment_id'] . '">' . h($r['awb_no']) . '</a>'
            : '-' ?></td>
        <td><?= h($r['name_ko'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['doc_date'] ?: '-') ?></td>
        <td class="r tnum"><?= $r['size_bytes'] !== null
            ? money(round($r['size_bytes']/1024)) . ' KB' : '-' ?></td>
        <td class="c"><?= $r['backup_status'] === 'SYNCED'
            ? '<span class="badge b-ok">완료</span>'
            : ($r['backup_status'] === 'FAILED'
               ? '<span class="badge b-err">실패</span>'
               : '<span class="badge b-warn">대기</span>') ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['created_at']) ?></td>
        <td class="c">
          <a class="btn sm" href="?p=file_download&amp;id=<?= (int)$r['id'] ?>">받기</a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('목록에서 내립니다. 파일 자체는 남습니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="remove">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn sm">내리기</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>백업 상태는 NAS 동기화 결과입니다.
    동기화 작업은 아직 붙이지 않아서 전부 <b>대기</b>로 표시됩니다 —
    서버 구성이 정해지면 그때 연결합니다.</span></div>
  <?php endif; ?>
</div>
<?php layout_foot();
