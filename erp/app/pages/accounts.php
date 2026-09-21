<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 계좌 관리 + 비용분류 관리.
 *
 * 계좌 잔액 = 개시잔액 + 들어온 것 − 나간 것. 계좌이체도 잔액에는 반영됩니다
 * (손익에만 안 잡힙니다).
 *
 * 계좌는 지우지 않습니다 — 지난 거래가 물고 있어서 지우면 내역이 끊깁니다.
 * 안 쓰는 계좌는 '중지'로 내립니다.
 */

$err = '';
$eid = entity_id();

require_perm('ACCOUNT_MANAGE', '계좌·비용분류 관리');

// ---------------------------------------------------------------- 계좌 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'acct') {
    csrf_check();
    $id     = (int)post('id');
    $entId  = (int)post('business_entity_id', (string)$eid);
    $bank   = trim(post('bank_name'));
    $no     = trim(post('account_no'));
    $holder = trim(post('account_holder'));
    $open   = (float)str_replace(',', '', post('opening_balance', '0'));
    $openOn = post('opened_on');
    $active = post('is_active') === '1' ? 1 : 0;

    if ($bank === '' || $no === '' || $holder === '') {
        $err = '은행 · 계좌번호 · 예금주를 모두 입력하세요.';
    } elseif ($openOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openOn)) {
        $err = '개시일 형식이 올바르지 않습니다.';
    } else {
        try {
            if ($id > 0) {
                $st = db()->prepare('SELECT * FROM business_bank_accounts WHERE id = ?');
                $st->execute([$id]);
                $before = $st->fetch() ?: [];
                db()->prepare(
                    'UPDATE business_bank_accounts
                        SET business_entity_id = ?, bank_name = ?, account_no = ?,
                            account_holder = ?, account_type = ?, purpose = ?,
                            opening_balance = ?, opened_on = ?, memo = ?,
                            sort_order = ?, is_active = ?
                      WHERE id = ?')
                    ->execute([$entId, $bank, $no, $holder, post('account_type', 'BANK'),
                               post('purpose', 'BOTH'), $open, $openOn ?: null,
                               post('memo') ?: null, (int)post('sort_order', '0'),
                               $active, $id]);
                log_action('입출금', 'UPDATE', 'business_bank_accounts', $id,
                           $bank . ' ' . $no,
                           ($before['bank_name'] ?? '') . ' ' . ($before['account_no'] ?? ''),
                           $bank . ' ' . $no . ' · 개시 ' . money($open));
                flash('계좌를 저장했습니다.');
            } else {
                db()->prepare(
                    'INSERT INTO business_bank_accounts
                       (business_entity_id, bank_name, account_no, account_holder,
                        account_type, purpose, opening_balance, opened_on, memo,
                        sort_order, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$entId, $bank, $no, $holder, post('account_type', 'BANK'),
                               post('purpose', 'BOTH'), $open, $openOn ?: null,
                               post('memo') ?: null, (int)post('sort_order', '0'), $active]);
                $newId = (int)db()->lastInsertId();
                log_action('입출금', 'CREATE', 'business_bank_accounts', $newId,
                           $bank . ' ' . $no, null, '개시잔액 ' . money($open));
                flash('계좌를 추가했습니다.');
            }
            redirect('?p=accounts');
        } catch (Throwable $e) {
            error_log('계좌 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 비용분류 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cat') {
    csrf_check();
    $id   = (int)post('cat_id');
    $code = strtoupper(trim(post('code')));
    $name = trim(post('name'));
    if ($name === '') {
        $err = '분류 이름을 입력하세요.';
    } elseif ($id === 0 && !preg_match('/^[A-Z0-9_]{2,30}$/', $code)) {
        $err = '코드는 영문 대문자·숫자·밑줄 2~30자입니다.';
    } else {
        try {
            if ($id > 0) {
                db()->prepare(
                    'UPDATE expense_categories
                        SET name = ?, is_cogs = ?, default_tax = ?, sort_order = ?,
                            is_active = ?, memo = ?
                      WHERE id = ?')
                    ->execute([$name, post('is_cogs') === '1' ? 1 : 0,
                               post('default_tax', 'TAXABLE'), (int)post('sort_order', '0'),
                               post('cat_active') === '1' ? 1 : 0, post('memo') ?: null, $id]);
                log_action('입출금', 'UPDATE', 'expense_categories', $id, $name);
                flash('비용분류를 저장했습니다.');
            } else {
                db()->prepare(
                    'INSERT INTO expense_categories
                       (code, name, is_cogs, default_tax, sort_order, is_active, memo)
                     VALUES (?,?,?,?,?,?,?)')
                    ->execute([$code, $name, post('is_cogs') === '1' ? 1 : 0,
                               post('default_tax', 'TAXABLE'), (int)post('sort_order', '0'),
                               1, post('memo') ?: null]);
                log_action('입출금', 'CREATE', 'expense_categories',
                           (int)db()->lastInsertId(), $name);
                flash('비용분류를 추가했습니다.');
            }
            redirect('?p=accounts#cat');
        } catch (Throwable $e) {
            error_log('비용분류 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다. 코드가 이미 있는지 확인하세요.';
        }
    }
}

// ---------------------------------------------------------------- 조회
$params = [];
$w = entity_where('v.business_entity_id', $params);
$st = db()->prepare("SELECT v.*, b.opened_on, b.memo, b.sort_order, e.name_ko AS entity_name
                       FROM v_account_balance v
                       JOIN business_bank_accounts b ON b.id = v.bank_account_id
                       JOIN business_entities e ON e.id = v.business_entity_id
                      WHERE $w ORDER BY v.business_entity_id, b.sort_order, v.bank_account_id");
$st->execute($params);
$accts = $st->fetchAll();

$editId = (int)query('edit', '0');
$edit = null;
foreach ($accts as $a) { if ((int)$a['bank_account_id'] === $editId) { $edit = $a; } }

$cats = db()->query('SELECT c.*,
                            (SELECT COUNT(*) FROM financial_transactions f
                              WHERE f.category_id = c.id AND f.status = \'CONFIRMED\') AS used
                       FROM expense_categories c ORDER BY c.sort_order, c.id')->fetchAll();
$catEditId = (int)query('cat', '0');
$catEdit = null;
foreach ($cats as $c) { if ((int)$c['id'] === $catEditId) { $catEdit = $c; } }

$totalBal = 0.0;
foreach ($accts as $a) { if ((int)$a['is_active']) { $totalBal += (float)$a['balance']; } }

$TYPE = ['BANK' => '예금', 'CARD' => '카드', 'CASH' => '현금', 'VIRTUAL' => '가상계좌'];
$PURP = ['IN' => '입금전용', 'OUT' => '지급전용', 'BOTH' => '공용'];

layout_head('계좌 관리', 'accounts');
?>
<div class="head">
  <h1>계좌 관리</h1>
  <div class="crumb">입출금관리 &gt; 계좌 관리 · <?= h(entity_label()) ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">사용중 계좌 잔액 합계</div>
    <div class="val tnum"><?= money($totalBal) ?></div>
    <div class="sub"><?= h(entity_label()) ?></div></div>
  <div class="kpi"><div class="lab">등록된 계좌</div>
    <div class="val tnum"><?= count($accts) ?></div>
    <div class="sub">중지 포함</div></div>
  <div class="kpi"><div class="lab">비용분류</div>
    <div class="val tnum"><?= count($cats) ?></div>
    <div class="sub">출금 등록에서 고르는 항목</div></div>
</div>

<div class="card">
  <div class="ch">계좌 목록</div>
  <?php if (!$accts): ?>
    <div class="empty">등록된 계좌가 없습니다. 아래에서 추가하세요.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:130px">사업자</th><th style="width:110px">은행</th>
      <th style="width:160px">계좌번호</th><th style="width:120px">예금주</th>
      <th class="c" style="width:70px">종류</th><th class="c" style="width:85px">용도</th>
      <th class="r" style="width:130px">개시잔액</th>
      <th class="r" style="width:130px">입금계</th><th class="r" style="width:130px">출금계</th>
      <th class="r" style="width:140px">현재잔액</th>
      <th class="c" style="width:65px">사용</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($accts as $a): ?>
      <tr<?= (int)$a['is_active'] ? '' : ' style="color:var(--ink3)"' ?>>
        <td style="font-size:11.5px"><?= h($a['entity_name']) ?></td>
        <td style="font-weight:600"><?= h($a['bank_name']) ?></td>
        <td class="tnum"><?= h($a['account_no']) ?></td>
        <td><?= h($a['account_holder']) ?></td>
        <td class="c"><?= h($TYPE[$a['account_type']] ?? $a['account_type']) ?></td>
        <td class="c"><?= h($PURP[$a['purpose']] ?? $a['purpose']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($a['opening_balance']) ?></td>
        <td class="r tnum" style="color:#1B7F5A"><?= money($a['in_total']) ?></td>
        <td class="r tnum" style="color:#B3261E"><?= money($a['out_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($a['balance']) ?></td>
        <td class="c"><?= (int)$a['is_active']
             ? '<span class="badge b-ok">사용</span>'
             : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c"><a class="btn sm"
              href="?p=accounts&amp;edit=<?= (int)$a['bank_account_id'] ?>">수정</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>계좌는 지울 수 없습니다.</b> 지난 거래가 물고 있어서 지우면 내역이 끊깁니다.
    안 쓰는 계좌는 <b>중지</b>로 내리세요 — 목록에는 남고 새 거래에서는 안 보입니다.
  </span></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch"><?= $edit ? h($edit['bank_name'] . ' ' . $edit['account_no']) . ' 수정' : '계좌 추가' ?>
    <?php if ($edit): ?><a class="btn sm" style="margin-left:auto" href="?p=accounts">새로 추가</a><?php endif; ?>
  </div>
  <div class="cb">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="acct">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['bank_account_id'] : 0 ?>">
      <div class="f" style="align-items:flex-end">
        <div class="fw w1"><label for="ae">사업자 *</label>
          <select id="ae" name="business_entity_id">
            <?php foreach (entity_list() as $en): ?>
              <option value="<?= (int)$en['id'] ?>"<?=
                (int)$en['id'] === (int)($edit['business_entity_id'] ?? $eid) ? ' selected' : '' ?>>
                <?= h($en['name_ko']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w1"><label for="ab">은행 *</label>
          <input type="text" id="ab" name="bank_name" required
                 value="<?= h($edit['bank_name'] ?? '') ?>" placeholder="국민은행"></div>
        <div class="fw w1"><label for="an">계좌번호 *</label>
          <input type="text" id="an" name="account_no" class="tnum" required
                 value="<?= h($edit['account_no'] ?? '') ?>"></div>
        <div class="fw w1"><label for="ah">예금주 *</label>
          <input type="text" id="ah" name="account_holder" required
                 value="<?= h($edit['account_holder'] ?? '') ?>"></div>
      </div>
      <div class="f" style="align-items:flex-end;margin-top:8px">
        <div class="fw w1"><label for="at">종류</label>
          <select id="at" name="account_type">
            <?php foreach ($TYPE as $k => $v): ?>
              <option value="<?= h($k) ?>"<?= ($edit['account_type'] ?? 'BANK') === $k ? ' selected' : '' ?>>
                <?= h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w1"><label for="ap">용도</label>
          <select id="ap" name="purpose">
            <?php foreach ($PURP as $k => $v): ?>
              <option value="<?= h($k) ?>"<?= ($edit['purpose'] ?? 'BOTH') === $k ? ' selected' : '' ?>>
                <?= h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w1"><label for="ao">개시잔액</label>
          <input type="text" id="ao" name="opening_balance" class="tnum" style="text-align:right"
                 value="<?= h((string)($edit['opening_balance'] ?? '0')) ?>"></div>
        <div class="fw w1"><label for="ad">개시일</label>
          <input type="date" id="ad" name="opened_on" value="<?= h($edit['opened_on'] ?? '') ?>"></div>
        <div class="fw w1"><label for="as">정렬</label>
          <input type="text" id="as" name="sort_order" class="tnum" style="text-align:right"
                 value="<?= h((string)($edit['sort_order'] ?? '0')) ?>"></div>
        <div class="fw w1"><label for="aa">사용</label>
          <select id="aa" name="is_active">
            <option value="1"<?= (int)($edit['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>사용</option>
            <option value="0"<?= (int)($edit['is_active'] ?? 1) === 0 ? ' selected' : '' ?>>중지</option>
          </select></div>
      </div>
      <div class="f" style="align-items:flex-end;margin-top:8px">
        <div class="fw w4"><label for="am">메모</label>
          <input type="text" id="am" name="memo" value="<?= h($edit['memo'] ?? '') ?>"></div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <button class="btn pri">저장</button>
        <span style="font-size:11.5px;color:var(--ink3)">
          <b>개시잔액</b>은 이 시스템을 쓰기 시작한 시점의 통장 잔고입니다.
          한 번 정하고 나면 바꾸지 마세요 — 이후 모든 잔액이 같이 틀어집니다.
        </span>
      </div>
    </form>
  </div>
</div>

<div class="card" id="cat">
  <div class="ch">비용분류
    <span style="font-weight:400;color:var(--ink3)">출금 등록에서 고르는 항목</span>
  </div>
  <table>
    <thead><tr>
      <th style="width:130px">코드</th><th>이름</th>
      <th class="c" style="width:90px">원가구분</th><th class="c" style="width:90px">부가세 기본</th>
      <th class="c" style="width:80px">사용건수</th><th class="c" style="width:70px">사용</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($cats as $c): ?>
      <tr<?= (int)$c['is_active'] ? '' : ' style="color:var(--ink3)"' ?>>
        <td class="tnum"><?= h($c['code']) ?></td>
        <td style="font-weight:600"><?= h($c['name']) ?></td>
        <td class="c"><?= (int)$c['is_cogs']
             ? '<span class="badge b-warn">매출원가</span>'
             : '<span class="badge b-info">판관비</span>' ?></td>
        <td class="c"><?= ['TAXABLE'=>'과세','ZERO'=>'영세','EXEMPT'=>'면세'][$c['default_tax']] ?? h($c['default_tax']) ?></td>
        <td class="c tnum"><?= money($c['used']) ?></td>
        <td class="c"><?= (int)$c['is_active']
             ? '<span class="badge b-ok">사용</span>'
             : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c"><a class="btn sm" href="?p=accounts&amp;cat=<?= (int)$c['id'] ?>#cat">수정</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cat">
      <input type="hidden" name="cat_id" value="<?= $catEdit ? (int)$catEdit['id'] : 0 ?>">
      <div class="f" style="align-items:flex-end">
        <div class="fw w1"><label for="cc">코드 *</label>
          <input type="text" id="cc" name="code" class="tnum"
                 value="<?= h($catEdit['code'] ?? '') ?>"
                 <?= $catEdit ? 'readonly' : 'required' ?> placeholder="FUEL"></div>
        <div class="fw w1"><label for="cn">이름 *</label>
          <input type="text" id="cn" name="name" required
                 value="<?= h($catEdit['name'] ?? '') ?>" placeholder="유류비"></div>
        <div class="fw w1"><label for="cg">원가구분</label>
          <select id="cg" name="is_cogs">
            <option value="0"<?= (int)($catEdit['is_cogs'] ?? 0) === 0 ? ' selected' : '' ?>>판관비</option>
            <option value="1"<?= (int)($catEdit['is_cogs'] ?? 0) === 1 ? ' selected' : '' ?>>매출원가</option>
          </select></div>
        <div class="fw w1"><label for="ct">부가세 기본</label>
          <select id="ct" name="default_tax">
            <?php foreach (['TAXABLE'=>'과세','ZERO'=>'영세','EXEMPT'=>'면세'] as $k=>$v): ?>
              <option value="<?= h($k) ?>"<?= ($catEdit['default_tax'] ?? 'TAXABLE') === $k ? ' selected' : '' ?>>
                <?= h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w1"><label for="cs">정렬</label>
          <input type="text" id="cs" name="sort_order" class="tnum" style="text-align:right"
                 value="<?= h((string)($catEdit['sort_order'] ?? '0')) ?>"></div>
        <?php if ($catEdit): ?>
        <div class="fw w1"><label for="ca">사용</label>
          <select id="ca" name="cat_active">
            <option value="1"<?= (int)$catEdit['is_active'] === 1 ? ' selected' : '' ?>>사용</option>
            <option value="0"<?= (int)$catEdit['is_active'] === 0 ? ' selected' : '' ?>>중지</option>
          </select></div>
        <?php endif; ?>
        <button class="btn pri"><?= $catEdit ? '저장' : '추가' ?></button>
        <?php if ($catEdit): ?><a class="btn" href="?p=accounts#cat">새로 추가</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="pager"><span>
    이미 쓰인 분류는 <b>지우지 말고 중지</b>하세요. 지우면 지난 출금의 분류가 사라집니다.
    <b>매출원가</b>로 표시한 분류는 손익에서 매출총이익 위쪽에 들어갑니다.
  </span></div>
</div>
<?php layout_foot();
