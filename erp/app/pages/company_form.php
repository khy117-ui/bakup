<?php
require_once APP_DIR . '/layout.php';

$id  = (int)query('id', '0');
$err = '';
$row = [
    'company_code' => '', 'name_ko' => '', 'name_en' => '', 'representative' => '',
    'business_number' => '', 'corp_number' => '', 'business_type' => '',
    'business_item' => '', 'phone' => '', 'fax' => '', 'email' => '',
    'address_ko' => '', 'joined_on' => '', 'sales_team' => '', 'sales_rep' => '',
    'trade_status' => 'ACTIVE', 'payment_method' => '', 'payment_terms' => '',
    'tax_email' => '', 'memo' => '',
];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM companies WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) {
        exit('거래처를 찾을 수 없습니다.');
    }
    $row = array_merge($row, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($row) as $k) {
        if ($k !== 'id') {
            $row[$k] = post($k);
        }
    }
    if ($row['company_code'] === '' || $row['name_ko'] === '') {
        $err = '거래처 코드와 업체명은 필수입니다.';
    }
    // 선택값은 화이트리스트로 제한합니다. POST 를 그대로 넣으면
    // 아무 문자열이나 들어가 상태 배지와 통계 집계가 깨집니다
    if ($err === '' && !in_array($row['trade_status'],
                                 ['ACTIVE','SUSPENDED','NEW','CLOSED'], true)) {
        $err = '거래상태 값이 올바르지 않습니다.';
    }
    if ($err === '' && $row['joined_on'] !== ''
        && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['joined_on'])) {
        $err = '가입일 형식이 올바르지 않습니다.';
    }
    if ($err === '') {
        // 코드 중복 검사. UNIQUE 라서 그냥 넣으면 예외가 납니다
        $st = db()->prepare('SELECT id FROM companies WHERE company_code = ? AND id <> ?');
        $st->execute([$row['company_code'], $id]);
        if ($st->fetchColumn()) {
            $err = '이미 쓰이는 거래처 코드입니다.';
        }
    }
    if ($err === '') {
        $cols = ['company_code','name_ko','name_en','representative','business_number',
                 'corp_number','business_type','business_item','phone','fax','email',
                 'address_ko','joined_on','sales_team','sales_rep','trade_status',
                 'payment_method','payment_terms','tax_email','memo'];
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = ($c === 'joined_on' && $row[$c] === '') ? null
                    : ($row[$c] === '' ? null : $row[$c]);
        }
        try {
            if ($id > 0) {
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
                $vals[] = $id;
                db()->prepare("UPDATE companies SET $set WHERE id = ?")->execute($vals);
                log_action('거래처', 'UPDATE', 'companies', $id, $row['name_ko']);
                flash('거래처를 수정했습니다.');
            } else {
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $names = implode(',', array_map(fn($c) => "`$c`", $cols));
                db()->prepare("INSERT INTO companies ($names) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
                log_action('거래처', 'CREATE', 'companies', $id, $row['name_ko']);
                flash('거래처를 등록했습니다.');
            }
            redirect('?p=companies');
        } catch (PDOException $e) {
            error_log('거래처 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다. 입력값을 확인하세요.';
        }
    }
}

layout_head($id ? '거래처 수정' : '거래처 등록', 'companies');
?>
<div class="head">
  <h1><?= $id ? '거래처 수정' : '거래처 등록' ?></h1>
  <div class="crumb">기준정보 &gt; 거래처 관리 &gt; <?= $id ? '수정' : '등록' ?></div>
  <div class="right"><a class="btn" href="?p=companies">목록</a></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<div class="card">
  <div class="ch">기본 정보</div>
  <div class="cb f">
    <div class="fw w1"><label>거래처 코드 *</label>
      <input type="text" name="company_code" required value="<?= h($row['company_code']) ?>"></div>
    <div class="fw w3"><label>업체명 (국문) *</label>
      <input type="text" name="name_ko" required value="<?= h($row['name_ko']) ?>"></div>
    <div class="fw w3"><label>업체명 (영문)</label>
      <input type="text" name="name_en" value="<?= h($row['name_en']) ?>"></div>
    <div class="fw w1"><label>대표자</label>
      <input type="text" name="representative" value="<?= h($row['representative']) ?>"></div>
    <div class="fw w2"><label>사업자등록번호</label>
      <input type="text" name="business_number" value="<?= h($row['business_number']) ?>"
             placeholder="000-00-00000"></div>
    <div class="fw w2"><label>법인등록번호</label>
      <input type="text" name="corp_number" value="<?= h($row['corp_number']) ?>"></div>
    <div class="fw w1"><label>업태</label>
      <input type="text" name="business_type" value="<?= h($row['business_type']) ?>"></div>
    <div class="fw w2"><label>종목</label>
      <input type="text" name="business_item" value="<?= h($row['business_item']) ?>"></div>
  </div>
</div>

<div class="card">
  <div class="ch">연락 · 주소</div>
  <div class="cb f">
    <div class="fw w2"><label>전화</label>
      <input type="text" name="phone" value="<?= h($row['phone']) ?>"></div>
    <div class="fw w2"><label>팩스</label>
      <input type="text" name="fax" value="<?= h($row['fax']) ?>"></div>
    <div class="fw w3"><label>이메일</label>
      <input type="text" name="email" value="<?= h($row['email']) ?>"></div>
    <div class="fw gr" style="min-width:320px"><label>주소</label>
      <input type="text" name="address_ko" value="<?= h($row['address_ko']) ?>"></div>
  </div>
</div>

<div class="card">
  <div class="ch">거래 조건</div>
  <div class="cb f">
    <div class="fw w1"><label>거래상태</label>
      <select name="trade_status">
        <?php foreach (['ACTIVE'=>'거래중','NEW'=>'신규','SUSPENDED'=>'거래중지','CLOSED'=>'종료'] as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $row['trade_status']===$k?' selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label>가입일</label>
      <input type="date" name="joined_on" value="<?= h($row['joined_on']) ?>"></div>
    <div class="fw w1"><label>영업팀</label>
      <input type="text" name="sales_team" value="<?= h($row['sales_team']) ?>"></div>
    <div class="fw w1"><label>영업담당자</label>
      <input type="text" name="sales_rep" value="<?= h($row['sales_rep']) ?>"></div>
    <div class="fw w2"><label>결제방법</label>
      <input type="text" name="payment_method" value="<?= h($row['payment_method']) ?>"
             placeholder="계좌이체"></div>
    <div class="fw w3"><label>결제조건</label>
      <input type="text" name="payment_terms" value="<?= h($row['payment_terms']) ?>"
             placeholder="월말마감 익월 30일"></div>
    <div class="fw w3"><label>세금계산서 수신 이메일</label>
      <input type="text" name="tax_email" value="<?= h($row['tax_email']) ?>"></div>
    <div class="fw" style="width:100%"><label>메모</label>
      <textarea name="memo"><?= h($row['memo']) ?></textarea></div>
  </div>
</div>

<div style="display:flex;gap:8px">
  <button class="btn pri">저장</button>
  <a class="btn" href="?p=companies">취소</a>
</div>
</form>
<?php layout_foot();
