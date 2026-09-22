<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 사업자 관리 — 인보이스·명세서·견적서에 찍히는 정보가 여기서 나옵니다.
 * 두 사업자((주)굿배송항공 / 굿포스트미디어)를 나눠 관리합니다.
 */

$err = '';
$id  = (int)query('id', '0');

$list = db()->query('SELECT id, code, name_ko, business_number, is_active
                       FROM business_entities ORDER BY is_active DESC, code')->fetchAll();
if ($id === 0 && $list) { $id = (int)$list[0]['id']; }

$FIELDS = [
    'code' => '코드', 'name_ko' => '상호 (국문)', 'name_en' => '상호 (영문)',
    'business_number' => '사업자등록번호', 'corp_number' => '법인등록번호',
    'representative' => '대표자', 'doc_manager' => '문서 담당자',
    'business_type' => '업태', 'business_item' => '종목',
    'zipcode' => '우편번호', 'address_ko' => '주소 (국문)', 'address_en' => '주소 (영문)',
    'phone' => '전화', 'fax' => '팩스', 'email' => '이메일',
    'tax_api_provider' => '전자세금계산서 연동사', 'tax_api_account' => '연동 계정',
    'doc_prefix_quote' => '견적번호 머리말', 'doc_prefix_stmt' => '명세서번호 머리말',
    'doc_prefix_invoice' => '청구번호 머리말',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'save') {
            $id = (int)post('id');
            $vals = [];
            foreach (array_keys($FIELDS) as $k) {
                $v = post($k);
                $vals[] = $v === '' ? null : $v;
            }
            if (post('code') === '' || post('name_ko') === '' || post('business_number') === '') {
                $err = '코드 · 상호 · 사업자등록번호는 필수입니다.';
            } elseif (post('representative') === '') {
                $err = '대표자는 필수입니다. 세금계산서와 인보이스에 찍힙니다.';
            } else {
                $dup = $pdo->prepare('SELECT id FROM business_entities WHERE code = ? AND id <> ?');
                $dup->execute([post('code'), $id]);
                if ($dup->fetchColumn()) {
                    $err = '이미 쓰이는 사업자 코드입니다.';
                } else {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($FIELDS)));
                    $vals[] = post('is_active') ? 1 : 0;
                    $vals[] = $id;
                    $pdo->prepare("UPDATE business_entities SET $set, is_active = ? WHERE id = ?")
                        ->execute($vals);
                    log_action('시스템', 'UPDATE', 'business_entities', $id, post('name_ko'),
                               null, null, post('reason') ?: null);
                    flash('사업자 정보를 저장했습니다.');
                    redirect('?p=business_entity&id=' . $id);
                }
            }

        } elseif ($act === 'bank_add') {
            $eid  = (int)post('entity_id');
            $bank = post('bank_name');
            $acct = post('account_no');
            $hold = post('account_holder');
            if ($bank === '' || $acct === '' || $hold === '') {
                $err = '은행 · 계좌번호 · 예금주를 모두 입력하세요.';
            } else {
                $pdo->prepare(
                    'INSERT INTO business_bank_accounts
                       (business_entity_id, bank_name, account_no, account_holder,
                        sort_order, is_active)
                     VALUES (?,?,?,?,?,1)')
                    ->execute([$eid, $bank, $acct, $hold, (int)post('sort_order')]);
                log_action('시스템', 'CREATE', 'business_bank_accounts',
                           (int)$pdo->lastInsertId(), $bank . ' ' . $acct);
                flash('입금계좌를 추가했습니다.');
                redirect('?p=business_entity&id=' . $eid);
            }

        } elseif ($act === 'bank_toggle') {
            $bid = (int)post('bid');
            $pdo->prepare('UPDATE business_bank_accounts SET is_active = 1 - is_active WHERE id = ?')
                ->execute([$bid]);
            log_action('시스템', 'UPDATE', 'business_bank_accounts', $bid, null, null, '사용 여부 변경');
            redirect('?p=business_entity&id=' . (int)post('entity_id'));

        } elseif ($act === 'stamp_upload' || $act === 'stamp_remove') {
            // 직인 — 서버 보관 폴더에만 (공개 저장소 · 웹 주소로는 못 엶). 청구서 등 인쇄 화면에 찍힙니다
            $eidS = (int)post('entity_id');
            $old = $pdo->prepare('SELECT stamp_path FROM business_entities WHERE id = ?');
            $old->execute([$eidS]);
            $oldPath = (string)$old->fetchColumn();
            $newRel = null;
            if ($act === 'stamp_upload') {
                $f = $_FILES['stamp'] ?? null;
                $info = ($f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name']))
                      ? @getimagesize((string)$f['tmp_name']) : false;
                $ext = $info ? (['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']] ?? '') : '';
                if (!$info || $ext === '') {
                    throw new RuntimeException('PNG · JPG · WEBP 그림 파일을 골라 주세요.');
                }
                if ((int)$f['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('직인 그림은 2MB 이하로 올려 주세요.');
                }
                // 비공개 구역 uploads/stamps — 서버에 먼저 두고, 파일 저장소가 NAS 면 NAS /erp/uploads/stamps 로
                require_once APP_DIR . '/filestore.php';
                $dir = entity_stamp_dir();
                if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('보관 폴더를 만들지 못했습니다.');
                }
                doc_protect_root(storage_root());
                $newRel = 'stamps/' . preg_replace('/[^A-Za-z0-9]/', '', (string)$eidS) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (!@move_uploaded_file((string)$f['tmp_name'], fs_local_path('uploads', $newRel))) {
                    throw new RuntimeException('직인 파일을 저장하지 못했습니다.');
                }
                // 직인은 인쇄물에 작게 찍히므로 긴 변 800px · 200KB 면 넉넉합니다
                require_once APP_DIR . '/imgshrink.php';
                $newExt = img_shrink(fs_local_path('uploads', $newRel), $ext, 800, 200 * 1024, $shrunk);
                // 파일 이름은 img_shrink 가 이미 바꿔 두었습니다 — DB 에 적을 이름만 맞춥니다
                if ($newExt !== $ext) {
                    $newRel = preg_replace('/\.[A-Za-z0-9]+$/', '', $newRel) . '.' . $newExt;
                }
                if (fs_cfg()['nas'] && !fs_push('uploads', $newRel, $why)) {
                    error_log('직인 NAS 저장 실패: ' . $why);   // 서버 사본으로 계속 씀
                }
            }
            $pdo->prepare('UPDATE business_entities SET stamp_path = ? WHERE id = ?')->execute([$newRel, $eidS]);
            if ($oldPath !== '' && preg_match('/^stamps\/[A-Za-z0-9_-]+\.(png|jpg|jpeg|webp)$/', $oldPath)) {
                require_once APP_DIR . '/filestore.php';
                @unlink(fs_local_path('uploads', $oldPath));
                @unlink(storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPath));   // 예전 위치
                if (fs_cfg()['nas']) { fs_dav('DELETE', fs_dav_url('uploads', $oldPath), null, null, 15); }
            }
            log_action('시스템', 'UPDATE', 'business_entities', $eidS, '직인', null, $newRel ? '직인 이미지 등록' : '직인 이미지 삭제');
            flash($newRel
                ? '직인을 등록했습니다. 청구서 인쇄 화면에 찍힙니다.'
                    . (!empty($shrunk) ? ' 그림이 커서 줄였습니다 — ' . $shrunk : '')
                : '직인을 지웠습니다.');
            redirect('?p=business_entity&id=' . $eidS . '#stamp');
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('사업자 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$id]);
$be = $st->fetch();
if (!$be && $list) { exit('사업자를 찾을 수 없습니다.'); }

$banks = [];
if ($be) {
    $st = db()->prepare('SELECT * FROM business_bank_accounts
                          WHERE business_entity_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $banks = $st->fetchAll();
}

// 비어 있는 필수 항목 — 문서에 빈칸으로 찍히므로 눈에 띄게 알려줍니다
$missing = [];
if ($be) {
    foreach (['business_number' => '사업자등록번호', 'representative' => '대표자',
              'address_ko' => '주소', 'phone' => '전화'] as $k => $lab) {
        if (trim((string)$be[$k]) === '' || str_starts_with((string)$be[$k], 'TBD')) {
            $missing[] = $lab;
        }
    }
    if (!$banks) { $missing[] = '입금계좌'; }
}

layout_head('사업자 관리', 'business_entity');
?>
<div class="head">
  <h1>사업자 관리</h1>
  <div class="crumb">시스템 &gt; 사업자 관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb" style="display:flex;gap:8px;align-items:center">
  <span style="font-size:12px;color:var(--ink2)">사업자</span>
  <?php foreach ($list as $l): ?>
    <a class="btn sm<?= (int)$l['id']===$id?' pri':'' ?>"
       href="?p=business_entity&amp;id=<?= (int)$l['id'] ?>">
      <?= h($l['code']) ?> · <?= h($l['name_ko']) ?>
      <?= $l['is_active'] ? '' : ' (중지)' ?></a>
  <?php endforeach; ?>
</div></div>

<?php if (!$be): ?>
  <div class="card"><div class="empty">등록된 사업자가 없습니다. 12_seed.sql 을 실행하세요.</div></div>
<?php else: ?>

<?php if ($missing): ?>
  <div class="msg err">
    <b>문서에 빈칸으로 찍히는 항목이 있습니다</b> — <?= h(implode(' · ', $missing)) ?><br>
    인보이스·거래명세서·견적서에 그대로 나갑니다. 채워 주세요.
  </div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">
<input type="hidden" name="id" value="<?= $id ?>">

<div class="card">
  <div class="ch">기본 정보
    <span style="font-weight:400;color:var(--ink3)">세금계산서·인보이스에 그대로 찍힙니다</span>
    <label style="margin-left:auto;font-weight:400;font-size:12.5px;display:flex;gap:6px;align-items:center">
      <input type="checkbox" name="is_active" value="1" <?= $be['is_active'] ? 'checked' : '' ?>>
      이 사업자로 전표 작성 허용</label>
  </div>
  <div class="cb f">
    <div class="fw w1"><label>코드 *</label>
      <input type="text" name="code" required value="<?= h($be['code']) ?>"></div>
    <div class="fw w3"><label>상호 (국문) *</label>
      <input type="text" name="name_ko" required value="<?= h($be['name_ko']) ?>"></div>
    <div class="fw w3"><label>상호 (영문)</label>
      <input type="text" name="name_en" value="<?= h($be['name_en']) ?>"
             placeholder="인보이스 상단에 찍힙니다"></div>
    <div class="fw w2"><label>사업자등록번호 *</label>
      <input type="text" name="business_number" required class="tnum"
             value="<?= h($be['business_number']) ?>" placeholder="000-00-00000"></div>
    <div class="fw w2"><label>법인등록번호</label>
      <input type="text" name="corp_number" class="tnum" value="<?= h($be['corp_number']) ?>"></div>
    <div class="fw w1"><label>대표자 *</label>
      <input type="text" name="representative" required value="<?= h($be['representative']) ?>"></div>
    <div class="fw w1"><label>문서 담당자</label>
      <input type="text" name="doc_manager" value="<?= h($be['doc_manager']) ?>"
             placeholder="인보이스 PERSON 란"></div>
    <div class="fw w1"><label>업태</label>
      <input type="text" name="business_type" value="<?= h($be['business_type']) ?>"></div>
    <div class="fw w2"><label>종목</label>
      <input type="text" name="business_item" value="<?= h($be['business_item']) ?>"></div>
  </div>
</div>

<div class="card">
  <div class="ch">주소 · 연락처</div>
  <div class="cb f">
    <div class="fw w1"><label>우편번호</label>
      <input type="text" name="zipcode" class="tnum" value="<?= h($be['zipcode']) ?>"></div>
    <div class="fw gr" style="min-width:340px"><label>주소 (국문)</label>
      <input type="text" name="address_ko" value="<?= h($be['address_ko']) ?>"></div>
    <div class="fw" style="width:100%"><label>주소 (영문)
        <span style="font-weight:400;color:var(--ink3)">— 인보이스 상단</span></label>
      <input type="text" name="address_en" value="<?= h($be['address_en']) ?>"></div>
    <div class="fw w2"><label>전화</label>
      <input type="text" name="phone" class="tnum" value="<?= h($be['phone']) ?>"></div>
    <div class="fw w2"><label>팩스</label>
      <input type="text" name="fax" class="tnum" value="<?= h($be['fax']) ?>"></div>
    <div class="fw w3"><label>이메일</label>
      <input type="text" name="email" value="<?= h($be['email']) ?>"></div>
  </div>
</div>

<div class="card">
  <div class="ch">문서번호 머리말
    <span style="font-weight:400;color:var(--ink3)">사업자가 둘이라 갈라놔야 회계가 안 섞입니다</span>
  </div>
  <div class="cb f">
    <div class="fw w2"><label>견적서</label>
      <input type="text" name="doc_prefix_quote" class="tnum"
             value="<?= h($be['doc_prefix_quote']) ?>" placeholder="GPA-Q-"></div>
    <div class="fw w2"><label>거래명세서</label>
      <input type="text" name="doc_prefix_stmt" class="tnum"
             value="<?= h($be['doc_prefix_stmt']) ?>" placeholder="GPA-S-"></div>
    <div class="fw w2"><label>청구서</label>
      <input type="text" name="doc_prefix_invoice" class="tnum"
             value="<?= h($be['doc_prefix_invoice']) ?>" placeholder="GPA-INV-"></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:11.5px;color:var(--ink3)">
    이미 발행된 문서의 번호는 바뀌지 않습니다. 앞으로 만들 문서부터 적용됩니다.
  </div>
</div>

<div class="card">
  <div class="ch">전자세금계산서 연동</div>
  <div class="cb f">
    <div class="fw w2"><label>연동사</label>
      <input type="text" name="tax_api_provider" value="<?= h($be['tax_api_provider']) ?>"
             placeholder="팝빌 / 바로빌"></div>
    <div class="fw w3"><label>연동 계정</label>
      <input type="text" name="tax_api_account" value="<?= h($be['tax_api_account']) ?>"></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:11.5px;color:var(--ink3)">
    <b>인증키는 여기서 입력하지 않습니다.</b> 화면에 입력하면 로그와 백업에 평문으로 남습니다.
    연동사가 정해지면 서버에 암호화해서 넣는 방식으로 붙이겠습니다.
  </div>
</div>

<div class="card">
  <div class="ch">변경 사유</div>
  <div class="cb">
    <input type="text" name="reason" placeholder="예) 본사 이전에 따른 주소 변경 (선택)">
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      적어두면 작업로그에 남습니다. 세금계산서에 찍히는 정보라 나중에 왜 바뀌었는지 찾을 일이 생깁니다.
    </div>
  </div>
</div>

<div style="display:flex;gap:8px">
  <button class="btn pri">저장</button>
</div>
</form>

<?php $stampUri = entity_stamp_data_uri($be); ?>
<div class="card" id="stamp">
  <div class="ch">직인
    <span style="font-weight:400;color:var(--ink3)">청구서(INVOICE) 회사명 옆에 찍힙니다 · 서버 비공개 폴더에만 보관</span>
  </div>
  <div class="cb" style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
    <div style="width:110px;height:110px;border:1px dashed var(--line);border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff">
      <?php if ($stampUri): ?>
        <img src="<?= h($stampUri) ?>" alt="직인" style="max-width:96px;max-height:96px;mix-blend-mode:multiply">
      <?php else: ?>
        <span style="font-size:11.5px;color:var(--ink3)">없음</span>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="stamp_upload">
      <input type="hidden" name="entity_id" value="<?= $id ?>">
      <div class="fw gr" style="min-width:240px"><label for="stampf">직인 그림 (PNG · JPG · WEBP, 2MB 이하)</label>
        <input type="file" id="stampf" name="stamp" accept="image/png,image/jpeg,image/webp" required></div>
      <button class="btn pri"><?= $stampUri ? '바꾸기' : '올리기' ?></button>
    </form>
    <?php if ($stampUri): ?>
    <form method="post" onsubmit="return confirm('직인을 지울까요? 청구서에 더 이상 찍히지 않습니다.');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="stamp_remove">
      <input type="hidden" name="entity_id" value="<?= $id ?>">
      <button class="btn">지우기</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="cb" style="padding-top:0;font-size:11.5px;color:var(--ink3)">
    흰 바탕 그림이어도 인쇄 화면에서는 바탕이 비쳐 보이게 찍습니다. 배경이 투명한 PNG 면 더 깔끔합니다.</div>
</div>

<div class="card">
  <div class="ch">입금계좌
    <span style="font-weight:400;color:var(--ink3)">인보이스·견적서 하단에 찍힙니다</span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="bank_add">
      <input type="hidden" name="entity_id" value="<?= $id ?>">
      <div class="fw w1"><label>은행 *</label>
        <input type="text" name="bank_name" required placeholder="국민은행"></div>
      <div class="fw w2"><label>계좌번호 *</label>
        <input type="text" name="account_no" class="tnum" required></div>
      <div class="fw w2"><label>예금주 *</label>
        <input type="text" name="account_holder" required
               value="<?= h($be['name_ko']) ?>"></div>
      <div class="fw w1"><label>정렬</label>
        <input type="text" name="sort_order" class="tnum" value="<?= count($banks) + 1 ?>"></div>
      <button class="btn">추가</button>
    </form>
  </div>
  <?php if (!$banks): ?>
    <div class="empty">입금계좌가 없습니다. <b>인보이스에 계좌 칸이 비어서 나갑니다.</b></div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:120px">은행</th><th style="width:200px">계좌번호</th>
      <th>예금주</th><th class="c" style="width:70px">정렬</th>
      <th class="c" style="width:80px">사용</th><th class="c" style="width:80px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($banks as $b): ?>
      <tr<?= $b['is_active'] ? '' : ' style="opacity:.55"' ?>>
        <td><?= h($b['bank_name']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($b['account_no']) ?></td>
        <td><?= h($b['account_holder']) ?></td>
        <td class="c tnum"><?= (int)$b['sort_order'] ?></td>
        <td class="c"><?= $b['is_active']
            ? '<span class="badge b-ok">사용</span>'
            : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="bank_toggle">
            <input type="hidden" name="bid" value="<?= (int)$b['id'] ?>">
            <input type="hidden" name="entity_id" value="<?= $id ?>">
            <button class="btn sm"><?= $b['is_active'] ? '중지' : '사용' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>계좌는 지우지 않습니다 — 과거 인보이스에 이미 찍혀 나갔기 때문입니다.
    안 쓰면 <b>중지</b>로 내리세요.</span></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();
