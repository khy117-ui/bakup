<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$sid = (int)query('shipment_id', '0');

// 올릴 수 있는 확장자. 실행 가능한 형식은 넣지 않습니다
const DOC_EXT = ['pdf','jpg','jpeg','png','gif','webp','xlsx','xls','csv',
                 'docx','doc','pptx','ppt','hwp','hwpx','txt','zip'];
const DOC_MAX = 20 * 1024 * 1024;   // 20MB

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

    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $err = '파일을 고르세요.';
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        $err = ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
             ? '파일이 서버 허용 크기를 넘습니다. php.ini 의 upload_max_filesize 를 확인하세요.'
             : '업로드에 실패했습니다 (오류 ' . (int)$f['error'] . ').';
    } elseif (!is_uploaded_file($f['tmp_name'])) {
        $err = '정상적인 업로드가 아닙니다.';
    } elseif ($f['size'] > DOC_MAX) {
        $err = '파일이 20MB 를 넘습니다.';
    } else {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, DOC_EXT, true)) {
            $err = '올릴 수 없는 형식입니다. 허용: ' . implode(', ', DOC_EXT);
        } elseif ($tid <= 0) {
            $err = '문서 종류를 고르세요.';
        }
    }

    if ($err === '') {
        $dir = doc_root() . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $err = '저장 폴더를 만들지 못했습니다: ' . h($dir);
        }
    }

    if ($err === '') {
        // 저장 이름은 원본과 무관하게 새로 만듭니다.
        // 원본 이름을 그대로 쓰면 경로 조작·덮어쓰기·실행 위험이 생깁니다
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $full   = $dir . DIRECTORY_SEPARATOR . $stored;
        $rel    = date('Y') . '/' . date('m') . '/' . $stored;

        if (!@move_uploaded_file($f['tmp_name'], $full)) {
            $err = '파일을 저장하지 못했습니다. 폴더 쓰기 권한을 확인하세요.';
        } else {
            @chmod($full, 0640);
            $hash = hash_file('sha256', $full) ?: null;
            $mime = function_exists('mime_content_type')
                  ? (mime_content_type($full) ?: null) : null;
            try {
                db()->prepare(
                    'INSERT INTO documents
                       (business_entity_id, document_type_id, shipment_id, company_id,
                        doc_date, title, original_name, stored_path, mime_type,
                        size_bytes, checksum_sha256, backup_status, uploaded_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,\'PENDING\',?)')
                    ->execute([$eid, $tid, $shId ?: null, $cid ?: null,
                               post('doc_date') ?: null,
                               $title !== '' ? $title : (string)$f['name'],
                               (string)$f['name'], $rel, $mime, (int)$f['size'], $hash,
                               $_SESSION['admin_id'] ?? null]);
                log_action('문서', 'CREATE', 'documents', (int)db()->lastInsertId(),
                           (string)$f['name'], null, number_format((int)$f['size']) . ' bytes');
                flash('문서를 올렸습니다.');
                redirect('?p=documents' . ($shId ? '&shipment_id=' . $shId : ''));
            } catch (PDOException $e) {
                @unlink($full);
                error_log('문서 저장 실패: ' . $e->getMessage());
                $err = '기록을 남기지 못해 업로드를 취소했습니다.';
            }
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

$companies = db()->prepare('SELECT id, name_ko FROM companies WHERE deleted_at IS NULL
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
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name_ko']) ?></option>
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
      20MB 까지 · <?= h(implode(', ', DOC_EXT)) ?> 만 올릴 수 있습니다.
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
