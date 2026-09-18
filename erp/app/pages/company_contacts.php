<?php
require_once APP_DIR . '/layout.php';

$err = '';
$cid = (int)query('company_id', '0');
$TYPE = ['GENERAL' => '일반', 'LOGISTICS' => '물류', 'SALES' => '영업',
         'ACCOUNTING' => '회계', 'TAX' => '세금계산서', 'OTHER' => '기타'];

$companies = db()->prepare(
    'SELECT c.id, c.company_code, c.name_ko,
            (SELECT COUNT(*) FROM company_contacts k
              WHERE k.company_id = c.id AND k.deleted_at IS NULL) AS cnt
       FROM companies c WHERE c.deleted_at IS NULL ORDER BY c.name_ko');
$companies->execute();
$companies = $companies->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'add' || $act === 'edit') {
            $cid  = (int)post('company_id');
            $kid  = (int)post('id');
            $name = post('name');
            $type = post('contact_type', 'GENERAL');
            if ($cid <= 0 || $name === '') {
                $err = '거래처와 이름은 필수입니다.';
            } elseif (!isset($TYPE[$type])) {
                $err = '담당 구분이 올바르지 않습니다.';
            } else {
                $prim = post('is_primary') ? 1 : 0;
                $pdo->beginTransaction();
                if ($prim) {
                    // 주담당은 한 명만. 전표 작성 시 기본값으로 쓰이므로 둘이면 곤란합니다
                    $pdo->prepare('UPDATE company_contacts SET is_primary = 0 WHERE company_id = ?')
                        ->execute([$cid]);
                }
                $args = [$name, post('department') ?: null, post('position') ?: null,
                         post('phone') ?: null, post('mobile') ?: null,
                         post('email') ?: null, $type, $prim, post('memo') ?: null];
                if ($act === 'edit' && $kid > 0) {
                    $args[] = $kid; $args[] = $cid;
                    $pdo->prepare(
                        'UPDATE company_contacts
                            SET name=?, department=?, position=?, phone=?, mobile=?,
                                email=?, contact_type=?, is_primary=?, memo=?
                          WHERE id=? AND company_id=?')->execute($args);
                    log_action('거래처', 'UPDATE', 'company_contacts', $kid, $name);
                    flash('담당자를 수정했습니다.');
                } else {
                    array_unshift($args, $cid);
                    $pdo->prepare(
                        'INSERT INTO company_contacts
                           (company_id, name, department, position, phone, mobile,
                            email, contact_type, is_primary, memo)
                         VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($args);
                    log_action('거래처', 'CREATE', 'company_contacts',
                               (int)$pdo->lastInsertId(), $name);
                    flash('담당자를 추가했습니다.');
                }
                $pdo->commit();
                redirect('?p=company_contacts&company_id=' . $cid);
            }
        } elseif ($act === 'remove') {
            $kid = (int)post('id');
            $cid = (int)post('company_id');
            // 지우지 않고 deleted_at 만 찍습니다. 과거 전표가 참조할 수 있습니다
            $pdo->prepare('UPDATE company_contacts SET deleted_at = NOW()
                            WHERE id = ? AND company_id = ?')->execute([$kid, $cid]);
            log_action('거래처', 'DELETE', 'company_contacts', $kid, null, null, '비활성 처리');
            flash('담당자를 목록에서 내렸습니다. 기록은 남아 있습니다.');
            redirect('?p=company_contacts&company_id=' . $cid);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('담당자 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

$rows = [];
if ($cid > 0) {
    $st = db()->prepare(
        'SELECT * FROM company_contacts
          WHERE company_id = ? AND deleted_at IS NULL
          ORDER BY is_primary DESC, contact_type, name');
    $st->execute([$cid]);
    $rows = $st->fetchAll();
}
$editId = (int)query('edit', '0');
$edit = null;
foreach ($rows as $r) { if ((int)$r['id'] === $editId) { $edit = $r; } }

layout_head('업체 담당자', 'company_contacts');
?>
<div class="head">
  <h1>업체 담당자</h1>
  <div class="crumb">기준정보 &gt; 업체 담당자</div>
  <?php if ($cid > 0): ?>
    <div class="right"><a class="btn" href="?p=company_form&amp;id=<?= $cid ?>">거래처 정보</a></div>
  <?php endif; ?>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="company_contacts">
    <div class="fw w3"><label for="cc">거래처</label>
      <select id="cc" name="company_id" onchange="this.form.submit()">
        <option value="0">선택하세요</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= $cid===(int)$c['id']?' selected':'' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>) — <?= (int)$c['cnt'] ?>명</option>
        <?php endforeach; ?>
      </select></div>
  </form>
</div></div>

<?php if ($cid > 0): ?>
<div class="card">
  <div class="ch"><?= $edit ? '담당자 수정' : '담당자 추가' ?></div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="<?= $edit ? 'edit' : 'add' ?>">
      <input type="hidden" name="company_id" value="<?= $cid ?>">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="fw w1"><label>이름 *</label>
        <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>"></div>
      <div class="fw w1"><label>부서</label>
        <input type="text" name="department" value="<?= h($edit['department'] ?? '') ?>"></div>
      <div class="fw w1"><label>직위</label>
        <input type="text" name="position" value="<?= h($edit['position'] ?? '') ?>"></div>
      <div class="fw w1"><label>담당 구분</label>
        <select name="contact_type">
          <?php foreach ($TYPE as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= ($edit['contact_type'] ?? 'GENERAL')===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>전화</label>
        <input type="text" name="phone" value="<?= h($edit['phone'] ?? '') ?>"></div>
      <div class="fw w1"><label>휴대전화</label>
        <input type="text" name="mobile" value="<?= h($edit['mobile'] ?? '') ?>"></div>
      <div class="fw w2"><label>이메일</label>
        <input type="text" name="email" value="<?= h($edit['email'] ?? '') ?>"></div>
      <div class="fw w1"><label>&nbsp;</label>
        <label style="font-weight:400;font-size:12.5px;display:flex;gap:6px;align-items:center;height:34px">
          <input type="checkbox" name="is_primary" value="1"
                 <?= ($edit['is_primary'] ?? 0) ? 'checked' : '' ?>> 주담당</label></div>
      <div class="fw gr" style="min-width:150px"><label>메모</label>
        <input type="text" name="memo" value="<?= h($edit['memo'] ?? '') ?>"></div>
      <button class="btn pri"><?= $edit ? '수정' : '추가' ?></button>
      <?php if ($edit): ?>
        <a class="btn" href="?p=company_contacts&amp;company_id=<?= $cid ?>">새로 입력</a>
      <?php endif; ?>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      주담당은 한 명만 지정됩니다. 새로 지정하면 기존 주담당은 자동으로 해제됩니다.
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">등록된 담당자가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:100px">이름</th><th style="width:100px">부서</th>
      <th style="width:90px">직위</th><th class="c" style="width:100px">구분</th>
      <th style="width:130px">전화</th><th style="width:130px">휴대전화</th>
      <th>이메일</th><th class="c" style="width:70px">주담당</th>
      <th class="c" style="width:110px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-weight:600"><?= h($r['name']) ?></td>
        <td><?= h($r['department'] ?: '-') ?></td>
        <td><?= h($r['position'] ?: '-') ?></td>
        <td class="c"><?= h($TYPE[$r['contact_type']] ?? $r['contact_type']) ?></td>
        <td class="tnum"><?= h($r['phone'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['mobile'] ?: '-') ?></td>
        <td><?= h($r['email'] ?: '-') ?></td>
        <td class="c"><?= $r['is_primary'] ? '<span class="badge b-ok">주담당</span>' : '' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=company_contacts&amp;company_id=<?= $cid ?>&amp;edit=<?= (int)$r['id'] ?>">수정</a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('이 담당자를 목록에서 내립니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="remove">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="company_id" value="<?= $cid ?>">
            <button class="btn sm">내리기</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>기존 시스템에는 담당자 <b>전화번호만</b> 두 개 있었고 이름 칸이 없었습니다.
    이관하면 '담당자1' '담당자2' 로 들어오니 실제 이름으로 고쳐 주세요.</span></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();
