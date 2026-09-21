<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$id  = (int)query('id', '0');          // 0 이면 신규, 그 외는 수정
$cur = null;                            // 수정 대상 원본

$CHARGE = charge_labels();   // 종류와 기본 세금구분은 bootstrap 의 CHARGE_TYPES
$TAX    = ['ZERO' => 0.0, 'TAXABLE' => 10.0, 'EXEMPT' => 0.0];
$MAXLINE = 8;

$companies = db()->prepare(
    'SELECT id, company_code, name_ko FROM companies
      WHERE deleted_at IS NULL AND trade_status <> \'CLOSED\' ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$carriers = db()->query(
    'SELECT id, code, name, default_tax_type FROM carriers
      WHERE is_active = 1 ORDER BY sort_order, code')->fetchAll();

// 도착지 — 도착지 관리 목록에서 고릅니다. 목록이 아직 비어 있으면 예전처럼 직접 적습니다
$dests = [];
try {
    $dests = db()->query('SELECT name, name_ko, country_code FROM destinations WHERE is_active = 1 ORDER BY name')
                 ->fetchAll();
} catch (PDOException $e) {
    $dests = [];
}
/** 도착지 이름 → 국가코드 (대소문자 무시) */
function dest_country_of(array $dests, string $name): ?string
{
    foreach ($dests as $d) {
        if (strcasecmp((string)$d['name'], $name) === 0) { return $d['country_code'] ?: null; }
    }
    return null;
}

// 관련서류 — 문서보관함(documents)에 전표 번호를 달아 둡니다
$canDoc   = route_can_edit('documents');
$seeDoc   = route_can_view('documents');
$docTypes = db()->query('SELECT id, code, name FROM document_types WHERE is_active = 1 ORDER BY sort_order')
                ->fetchAll();
$docDefault = 0;
foreach ($docTypes as $t) { if ($t['code'] === 'ETC') { $docDefault = (int)$t['id']; } }

$in = [
    'company_id' => '', 'carrier_id' => '', 'voucher_date' => date('Y-m-d'),
    'ship_date' => '', 'trade_type' => 'EXPORT', 'dest_city' => '', 'origin_city' => '',
    // 원가격 — 가격표가 없는 운송사(EMS 등)는 여기에 직접 넣습니다. 할인 · 유류할증은 그대로 적용됩니다
    'snap_base_price' => '',
    'actual_weight' => '', 'volume_weight' => '', 'package_count' => '',
    'remark' => '', 'sales_team' => '', 'sales_rep' => '', 'status' => 'CONFIRMED',
    // AWB 번호 — auto 저장할 때 자동 부여 / manual 직접 입력 (운송사 번호 등)
    'awb_mode' => 'auto', 'awb_no' => '',
    // 매입원가 = 운송사 매입가(불러온 값) + 추가 매입가. 둘 다 purchases 에 한 줄씩 남습니다
    'cost_base' => '', 'cost_extra' => '', 'cost_extra_memo' => '',
];
$lines = [];
for ($i = 0; $i < $MAXLINE; $i++) {
    $lines[] = ['charge_type' => $i === 0 ? 'AIR_FREIGHT' : 'OTHER', 'item_name' => '',
                'supply_amount' => '', 'tax_type' => 'ZERO'];
}
$reason = '';

// ---------------------------------------------------------------- 기존 전표 읽기
if ($id > 0) {
    $st = db()->prepare(
        'SELECT * FROM shipments
          WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if (!$cur) {
        exit('전표를 찾을 수 없습니다.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        foreach (array_keys($in) as $k) {
            if (array_key_exists($k, $cur)) {
                $in[$k] = (string)($cur[$k] ?? '');
            }
        }
        // 가격표에서 가져온 단가면 '직접입력' 칸은 비워 둡니다 (그래야 다시 저장할 때도 가격표를 봅니다)
        if (!empty($cur['snap_rate_table_id'])) { $in['snap_base_price'] = ''; }
        $st = db()->prepare(
            'SELECT charge_type, item_name, supply_amount, tax_type
               FROM shipment_charges WHERE shipment_id = ? ORDER BY line_no');
        $st->execute([$id]);
        $old = $st->fetchAll();
        foreach ($old as $i => $o) {
            if ($i >= $MAXLINE) {
                break;
            }
            $lines[$i] = [
                'charge_type'   => $o['charge_type'],
                'item_name'     => $o['item_name'],
                'supply_amount' => (string)(int)round((float)$o['supply_amount']),
                'tax_type'      => $o['tax_type'],
            ];
        }
    }
}

// ---------------------------------------------------------------- 매입원가 (purchases)
//   base  : 운송사 매입가 — charge_type AIR_FREIGHT 첫 줄 (옛 시스템 TSAMOUNT 가 여기로 이관됨)
//   extra : 추가 매입가   — charge_type EXTRA
//   other : 매입관리 화면에서 따로 넣은 나머지 (여기서는 합계만 보여줌)
//   이미 출금으로 지급된 줄은 잠급니다 — 금액을 바꾸면 지급 기록과 어긋납니다
$pur = ['base' => null, 'extra' => null, 'other' => 0.0];
if ($id > 0) {
    $st = db()->prepare('SELECT id, charge_type, supply_amount, tax_type, remark, vendor_name, is_paid
                           FROM purchases WHERE shipment_id = ? AND deleted_at IS NULL ORDER BY id');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $p) {
        $p['locked'] = (int)$p['is_paid'] === 1 || fin_purchase_paid((int)$p['id']) > 0;
        if ($p['charge_type'] === 'AIR_FREIGHT' && $pur['base'] === null) {
            $pur['base'] = $p;
        } elseif ($p['charge_type'] === 'EXTRA' && $pur['extra'] === null) {
            $pur['extra'] = $p;
        } else {
            $pur['other'] += (float)$p['supply_amount'];
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $in['cost_base']  = $pur['base']  ? (string)(int)round((float)$pur['base']['supply_amount'])  : '';
        $in['cost_extra'] = $pur['extra'] ? (string)(int)round((float)$pur['extra']['supply_amount']) : '';
        $in['cost_extra_memo'] = $pur['extra'] ? (string)($pur['extra']['remark'] ?? '') : '';
    }
}

/** 전표 한 건의 현재 상태를 한 줄로. 변경 전후 비교에 씁니다 */
function snapshot(int $sid): string
{
    $st = db()->prepare(
        'SELECT s.voucher_date, s.trade_type, s.status, s.charge_weight, s.awb_no, s.dest_city,
                c.name_ko, ca.code AS carrier
           FROM shipments s
           JOIN companies c  ON c.id = s.company_id
           JOIN carriers  ca ON ca.id = s.carrier_id
          WHERE s.id = ?');
    $st->execute([$sid]);
    $h = $st->fetch() ?: [];
    $st = db()->prepare(
        'SELECT line_no, charge_type, item_name, supply_amount, tax_type, tax_amount
           FROM shipment_charges WHERE shipment_id = ? ORDER BY line_no');
    $st->execute([$sid]);
    $parts = [];
    $sum = 0;
    foreach ($st->fetchAll() as $r) {
        $parts[] = sprintf('%s/%s/%s/%s', $r['charge_type'], $r['item_name'],
                           (int)round((float)$r['supply_amount']), $r['tax_type']);
        $sum += (float)$r['supply_amount'] + (float)$r['tax_amount'];
    }
    $st = db()->prepare('SELECT COALESCE(SUM(supply_amount), 0) FROM purchases
                          WHERE shipment_id = ? AND deleted_at IS NULL');
    $st->execute([$sid]);
    $parts[] = '매입원가=' . number_format((float)$st->fetchColumn());
    return sprintf('AWB=%s %s %s %s 중량%s 거래처=%s 운송사=%s 도착지=%s 합계=%s [%s]',
        $h['awb_no'] ?? '', $h['voucher_date'] ?? '', $h['trade_type'] ?? '', $h['status'] ?? '',
        $h['charge_weight'] ?? '-', $h['name_ko'] ?? '', $h['carrier'] ?? '', $h['dest_city'] ?? '-',
        number_format($sum), implode(' | ', $parts));
}

// ---------------------------------------------------------------- 취소
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cancel' && $id > 0) {
    csrf_check();
    $why = post('cancel_reason');
    if (mb_strlen($why) < 2) {
        $err = '취소 사유를 적어 주세요.';
    } else {
        $before = snapshot($id);
        db()->prepare('UPDATE shipments SET status = \'CANCELLED\' WHERE id = ? AND business_entity_id = ?')
            ->execute([$id, $eid]);
        log_action('매출전표', 'CANCEL', 'shipments', $id, (string)$cur['awb_no'],
                   $before, snapshot($id), $why);
        flash('전표 ' . $cur['awb_no'] . ' 을 취소했습니다. 삭제한 것이 아니라 상태만 바뀌었습니다.');
        redirect('?p=shipments');
    }
}

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') !== 'cancel') {
    csrf_check();
    foreach (array_keys($in) as $k) {
        if ($k !== 'status') {
            $in[$k] = post($k);
        }
    }
    $reason = post('reason');
    // AWB — 공백은 빼고 영문은 대문자로. 수정 화면은 번호를 고친 경우에만 직접 입력으로 봅니다
    $in['awb_no'] = strtoupper((string)preg_replace('/\s+/', '', $in['awb_no']));
    if ($id > 0) {
        $in['awb_mode'] = ($in['awb_no'] !== '' && $in['awb_no'] !== (string)$cur['awb_no']) ? 'manual' : 'keep';
    } elseif ($in['awb_mode'] !== 'manual') {
        $in['awb_mode'] = 'auto';
    }

    $postLines = $_POST['line'] ?? [];
    if (is_array($postLines)) {
        foreach ($postLines as $i => $l) {
            if (!isset($lines[$i]) || !is_array($l)) {
                continue;
            }
            $lines[$i] = [
                'charge_type'   => (string)($l['charge_type'] ?? 'OTHER'),
                'item_name'     => trim((string)($l['item_name'] ?? '')),
                'supply_amount' => (string)($l['supply_amount'] ?? ''),
                'tax_type'      => (string)($l['tax_type'] ?? 'ZERO'),
            ];
        }
    }

    $valid = [];
    foreach ($lines as $l) {
        if ($l['item_name'] === '' && num($l['supply_amount']) == 0.0) {
            continue;
        }
        if (!isset($CHARGE[$l['charge_type']])) {
            $err = '비용항목의 종류가 올바르지 않습니다.';
            break;
        }
        if (!isset($TAX[$l['tax_type']])) {
            $err = '세금구분이 올바르지 않습니다.';
            break;
        }
        if ($l['item_name'] === '') {
            $err = '금액이 있는 항목에는 항목명이 필요합니다.';
            break;
        }
        $valid[] = $l;
    }

    if ($err === '') {
        if ($in['company_id'] === '' || $in['carrier_id'] === '') {
            $err = '거래처와 운송사를 선택하세요.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['voucher_date'])) {
            $err = '전표일을 입력하세요.';
        } elseif ($in['ship_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['ship_date'])) {
            $err = '발송일 형식이 올바르지 않습니다.';
        } elseif (!$valid) {
            $err = '비용항목을 최소 한 줄 입력하세요.';
        } elseif ($id > 0 && mb_strlen($reason) < 2) {
            // 금액이 걸린 화면이라 수정에는 사유를 남깁니다 (스펙 [45])
            $err = '수정 사유를 적어 주세요. 작업로그에 남습니다.';
        } elseif ($in['awb_mode'] === 'manual') {
            if (!preg_match('/^[A-Z0-9-]{4,50}$/', $in['awb_no'])) {
                $err = 'AWB 번호는 영문 · 숫자 · - 로 4~50자입니다.';
            } else {
                // (사업자, AWB) 는 겹치면 안 됩니다 — 어느 전표가 쓰고 있는지 알려 줍니다
                $st = db()->prepare('SELECT voucher_date, c.name_ko FROM shipments s
                                       JOIN companies c ON c.id = s.company_id
                                      WHERE s.business_entity_id = ? AND s.awb_no = ? AND s.id <> ?');
                $st->execute([$eid, $in['awb_no'], $id]);
                if ($dup = $st->fetch()) {
                    $err = 'AWB ' . $in['awb_no'] . ' 은 이미 다른 전표(' . $dup['voucher_date'] . ' · '
                         . $dup['name_ko'] . ')에 쓰였습니다.';
                }
            }
        }
    }

    // 매입원가 — 숫자인지, 이미 지급된 줄을 바꾸려는 건 아닌지
    if ($err === '') {
        foreach (['cost_base' => '운송사 매입가', 'cost_extra' => '추가 매입가'] as $k => $label) {
            $v = str_replace([',', ' '], '', (string)$in[$k]);
            if ($v !== '' && (!is_numeric($v) || (float)$v < 0)) {
                $err = $label . '는 0 이상 숫자로 적어 주세요.';
                break;
            }
            $in[$k] = $v;
        }
        $in['cost_extra_memo'] = mb_substr(trim((string)$in['cost_extra_memo']), 0, 200);
    }
    if ($err === '') {
        foreach (['base' => ['cost_base', '운송사 매입가'], 'extra' => ['cost_extra', '추가 매입가']] as $slot => [$k, $label]) {
            $row = $pur[$slot];
            if ($row && $row['locked'] && round(num($in[$k])) != round((float)$row['supply_amount'])) {
                $err = $label . '는 이미 지급(출금)된 매입이라 여기서 바꿀 수 없습니다. 입출금 내역에서 먼저 정리하세요.';
                break;
            }
        }
    }

    if ($err === '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $aw = num($in['actual_weight']);
            $vw = num($in['volume_weight']);
            $cw = max($aw, $vw);
            $trade = $in['trade_type'] === 'IMPORT' ? 'IMPORT' : 'EXPORT';

            // 단가 스냅샷 — 그때의 원가격 · 할인율 · 유류할증을 전표에 굳혀 둡니다 (나중에 가격표가 바뀌어도 전표는 그대로)
            require_once APP_DIR . '/ratecalc.php';
            $manualBase = $in['snap_base_price'] !== ''
                ? (float)str_replace(',', '', $in['snap_base_price']) : null;
            $Q = rate_quote((int)$in['carrier_id'], (int)$in['company_id'], $trade, $in['voucher_date'],
                            $cw, $in['dest_city'] !== '' ? (string)dest_country_of($dests, $in['dest_city']) : '',
                            0, $manualBase);
            $snap = $Q['ok']
                ? [$Q['base'], $Q['disc'], $Q['disc_amt'], $Q['after'], $Q['fuel'], $Q['fuel_amt'], $Q['supply']]
                : [$manualBase, null, null, null, null, null, null];
            // 가격표에서 가져왔으면 어느 표였는지 남깁니다. 직접 넣은 값이면 NULL —
            // 다시 열었을 때 '직접입력' 칸에 가격표 값이 잘못 남지 않게 하는 표시이기도 합니다
            $snap[] = ($manualBase === null && $Q['ok'] && !empty($Q['table']['tid'])) ? (int)$Q['table']['tid'] : null;

            if ($id > 0) {
                $before = snapshot($id);
                $st = $pdo->prepare(
                    'UPDATE shipments
                        SET company_id = ?, carrier_id = ?, voucher_date = ?, ship_date = ?,
                            trade_type = ?, dest_city = ?, dest_country = ?,
                            origin_city = ?, origin_country = ?, actual_weight = ?, volume_weight = ?,
                            charge_weight = ?, package_count = ?, sales_team = ?, sales_rep = ?,
                            snap_base_price = ?, snap_discount_rate = ?, snap_discount_amt = ?,
                            snap_discounted = ?, snap_fuel_rate = ?, snap_fuel_amt = ?,
                            snap_supply_price = ?, snap_rate_table_id = ?, snap_taken_at = NOW(),
                            remark = ?, updated_by = ?
                      WHERE id = ? AND business_entity_id = ?');
                $st->execute([
                    (int)$in['company_id'], (int)$in['carrier_id'], $in['voucher_date'],
                    $in['ship_date'] !== '' ? $in['ship_date'] : null, $trade,
                    $in['dest_city'] !== '' ? $in['dest_city'] : null,
                    $in['dest_city'] !== '' ? dest_country_of($dests, $in['dest_city']) : null,
                    $in['origin_city'] !== '' ? $in['origin_city'] : null,
                    $in['origin_city'] !== '' ? dest_country_of($dests, $in['origin_city']) : null,
                    $aw > 0 ? $aw : null, $vw > 0 ? $vw : null, $cw > 0 ? $cw : null,
                    $in['package_count'] !== '' ? (int)$in['package_count'] : null,
                    $in['sales_team'] !== '' ? $in['sales_team'] : null,
                    $in['sales_rep'] !== '' ? $in['sales_rep'] : null,
                    $snap[0], $snap[1], $snap[2], $snap[3], $snap[4], $snap[5], $snap[6], $snap[7],
                    $in['remark'] !== '' ? $in['remark'] : null,
                    $_SESSION['admin_id'] ?? null, $id, $eid,
                ]);
                // 비용항목은 지우고 다시 넣습니다.
                // 지우기 전 행은 트리거가 shipments_history 에 남깁니다
                $pdo->prepare('DELETE FROM shipment_charges WHERE shipment_id = ?')
                    ->execute([$id]);
                $sid = $id;
                $awb = (string)$cur['awb_no'];
                if ($in['awb_mode'] === 'manual') {
                    // AWB 를 고친 경우 — 이전 번호는 아래 작업로그(변경 전후)에 남습니다
                    $pdo->prepare("UPDATE shipments SET awb_no = ?, awb_source = 'MANUAL'
                                    WHERE id = ? AND business_entity_id = ?")
                        ->execute([$in['awb_no'], $id, $eid]);
                    $awb = $in['awb_no'];
                }
            } else {
                $manual = $in['awb_mode'] === 'manual';
                $awb = $manual ? $in['awb_no'] : next_doc_no('AWB', 'GPA');
                $st = $pdo->prepare(
                    'INSERT INTO shipments
                       (business_entity_id, company_id, awb_no, awb_source, voucher_date,
                        ship_date, trade_type, carrier_id, dest_city, dest_country,
                        origin_city, origin_country,
                        actual_weight, volume_weight, charge_weight, package_count,
                        snap_base_price, snap_discount_rate, snap_discount_amt, snap_discounted,
                        snap_fuel_rate, snap_fuel_amt, snap_supply_price, snap_rate_table_id, snap_taken_at,
                        status, sales_team, sales_rep, remark, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),\'CONFIRMED\',?,?,?,?)');
                $st->execute([
                    $eid, (int)$in['company_id'], $awb, $manual ? 'MANUAL' : 'HOUSE', $in['voucher_date'],
                    $in['ship_date'] !== '' ? $in['ship_date'] : null, $trade,
                    (int)$in['carrier_id'],
                    $in['dest_city'] !== '' ? $in['dest_city'] : null,
                    $in['dest_city'] !== '' ? dest_country_of($dests, $in['dest_city']) : null,
                    $in['origin_city'] !== '' ? $in['origin_city'] : null,
                    $in['origin_city'] !== '' ? dest_country_of($dests, $in['origin_city']) : null,
                    $aw > 0 ? $aw : null, $vw > 0 ? $vw : null, $cw > 0 ? $cw : null,
                    $in['package_count'] !== '' ? (int)$in['package_count'] : null,
                    $snap[0], $snap[1], $snap[2], $snap[3], $snap[4], $snap[5], $snap[6], $snap[7],
                    $in['sales_team'] !== '' ? $in['sales_team'] : null,
                    $in['sales_rep'] !== '' ? $in['sales_rep'] : null,
                    $in['remark'] !== '' ? $in['remark'] : null,
                    $_SESSION['admin_id'] ?? null,
                ]);
                $sid = (int)$pdo->lastInsertId();
                $before = null;
            }

            $ins = $pdo->prepare(
                'INSERT INTO shipment_charges
                   (shipment_id, line_no, charge_type, item_name, qty, unit_price,
                    supply_amount, tax_type, tax_rate, tax_amount, total_amount)
                 VALUES (?,?,?,?,1,?,?,?,?,?,?)');
            $no = 0;
            foreach ($valid as $l) {
                $no++;
                $supply = round(num($l['supply_amount']));
                $rate   = $TAX[$l['tax_type']];
                $tax    = round($supply * $rate / 100);
                $ins->execute([$sid, $no, $l['charge_type'], $l['item_name'],
                               $supply, $supply, $l['tax_type'], $rate, $tax,
                               $supply + $tax]);
            }

            // 매입원가 — 운송사 매입가 · 추가 매입가를 purchases 에 한 줄씩. 0 이나 빈칸이면 그 줄을 내립니다
            $carrierName = '';
            foreach ($carriers as $c) { if ((int)$c['id'] === (int)$in['carrier_id']) { $carrierName = (string)$c['name']; } }
            $costs = [
                'base'  => ['AIR_FREIGHT', num($in['cost_base']),  $carrierName ?: '운송사', '매출전표에서 입력'],
                'extra' => ['EXTRA',       num($in['cost_extra']), $in['cost_extra_memo'] !== '' ? mb_substr($in['cost_extra_memo'], 0, 100) : '추가매입',
                            $in['cost_extra_memo'] !== '' ? $in['cost_extra_memo'] : '추가 매입'],
            ];
            foreach ($costs as $slot => [$ctype, $amt, $vendor, $memo]) {
                $row = $pur[$slot];
                if ($row && $row['locked']) {
                    continue;   // 지급된 줄 — 위에서 금액이 그대로인 것만 통과시켰습니다
                }
                $amt = round($amt);
                if ($row && $amt <= 0) {
                    $pdo->prepare('UPDATE purchases SET deleted_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
                } elseif ($row) {
                    $tax = $row['tax_type'] === 'TAXABLE' ? round($amt * 0.1) : 0;
                    $pdo->prepare('UPDATE purchases
                                      SET supply_amount = ?, tax_amount = ?, total_amount = ?, carrier_id = ?,
                                          vendor_name = ?, purchase_date = ?, remark = ?
                                    WHERE id = ?')
                        ->execute([$amt, $tax, $amt + $tax, (int)$in['carrier_id'] ?: null, $vendor,
                                   $in['voucher_date'], $memo, (int)$row['id']]);
                } elseif ($amt > 0) {
                    $pdo->prepare('INSERT INTO purchases
                                     (business_entity_id, shipment_id, vendor_name, carrier_id, purchase_date,
                                      charge_type, supply_amount, tax_type, tax_amount, total_amount, remark)
                                   VALUES (?,?,?,?,?,?,?,\'ZERO\',0,?,?)')
                        ->execute([$eid, $sid, $vendor, (int)$in['carrier_id'] ?: null, $in['voucher_date'],
                                   $ctype, $amt, $amt, $memo]);
                }
            }

            $after = snapshot($sid);
            log_action('매출전표', $id > 0 ? 'UPDATE' : 'CREATE', 'shipments', $sid, $awb,
                       $before, $after, $id > 0 ? $reason : null);
            // 이미 청구서에 수록된 전표를 고쳤으면 청구서에 '내용 바뀜' 표시를 남깁니다 (청구서 화면에서 재발행)
            $billNote = '';
            if ($id > 0 && $before !== $after) {
                $bi = $pdo->prepare("SELECT i.id, i.invoice_no FROM invoice_shipments xs
                                       JOIN invoices i ON i.id = xs.invoice_id
                                      WHERE xs.shipment_id = ? AND i.status <> 'CANCELLED' AND i.deleted_at IS NULL LIMIT 1");
                $bi->execute([$sid]);
                if ($biRow = $bi->fetch()) {
                    try {
                        $pdo->prepare('UPDATE invoices SET changed_at = NOW() WHERE id = ?')->execute([(int)$biRow['id']]);
                    } catch (PDOException $e) { /* changed_at 컬럼이 아직 없으면 넘어감 */ }
                    log_action('청구', 'UPDATE', 'invoices', (int)$biRow['id'], (string)$biRow['invoice_no'],
                               null, '수록 전표 ' . $awb . ' 내용 변경 — 재발행 필요', $id > 0 ? $reason : null);
                    $billNote = ' 이 전표는 청구서 ' . $biRow['invoice_no'] . ' 에 수록되어 있습니다. 금액이 바뀌었으면 청구서 화면에서 [재발행] 하세요.';
                }
            }
            $pdo->commit();

            // 새 전표와 같이 고른 관련서류 — 전표가 저장된 뒤에 올립니다
            $files = uploaded_files('docs');
            $docMsg = '';
            if ($files) {
                if (!$canDoc) {
                    $docMsg = ' 서류는 올리지 않았습니다 (문서 입력 권한 없음).';
                } else {
                    $ok = 0;
                    $bad = [];
                    foreach ($files as $f) {
                        $e = doc_store_upload($f, $eid, (int)post('doc_type_id'), $sid, (int)$in['company_id']);
                        if ($e === '') { $ok++; } else { $bad[] = $e; }
                    }
                    $docMsg = ($ok ? ' 서류 ' . $ok . '개를 올렸습니다.' : '')
                            . ($bad ? ' 못 올린 서류: ' . implode(' / ', $bad) : '');
                }
            }
            // 자동 화물추적 — AWB 가 조회되는 운송사(우체국 EMS · DHL · FedEx 등) 번호면 추적번호로 등록.
            // 이력은 ERP 가 열려 있는 동안 자동 추적(track_tick)이 몇 시간마다 가져옵니다
            $trkMsg = '';
            try {
                require_once APP_DIR . '/track_any.php';
                if (($newTid = track_auto_register($pdo, $sid, $id > 0 ? (string)$cur['awb_no'] : '')) > 0) {
                    log_action('물류', 'CREATE', 'tracking_numbers', $newTid, $awb, null, '전표 저장 때 AWB 자동 등록');
                    $trkMsg = ' 화물추적을 자동으로 켰습니다.';
                }
            } catch (PDOException $e) {
                error_log('전표 자동 추적 등록 실패: ' . $e->getMessage());
            }
            flash('매출전표 ' . $awb . ' 을 ' . ($id > 0 ? '수정' : '등록') . '했습니다.' . $docMsg . $trkMsg . $billNote);
            // 서류를 같이 올렸으면 그 전표로 가서 목록을 보여줍니다
            redirect($files ? '?p=shipment_form&id=' . $sid : '?p=shipments');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('전표 저장 실패: ' . $e->getMessage());
            // 확인과 저장 사이에 다른 사람이 같은 AWB 를 먼저 저장한 경우
            $err = strpos($e->getMessage(), 'uq_ship_awb') !== false
                ? 'AWB 번호 ' . ($in['awb_no'] ?: '(자동)') . ' 이(가) 방금 다른 전표에 저장되었습니다. 다른 번호로 다시 저장하세요.'
                : '저장하지 못했습니다. 입력값을 확인하세요.';
        }
    }
}

$title = $id > 0 ? '매출전표 수정' : '매출전표 등록';
layout_head($title, 'shipments');
?>
<div class="head">
  <h1><?= h($title) ?></h1>
  <div class="crumb">물류관리 &gt; 매출전표 &gt; <?= $id > 0 ? '수정' : '등록' ?></div>
  <div class="right">
    <?php if ($id > 0): ?>
      <span class="badge b-info tnum" style="height:28px"><?= h($cur['awb_no']) ?></span>
      <?php if (($cur['status'] ?? '') !== 'CANCELLED' && route_can_view('billing')): ?>
        <a class="btn" href="#bill-card">청구</a>
      <?php endif; ?>
      <?php if (route_can_edit('shipments')): ?>
        <a class="btn" href="#del-card" style="color:#A32020">삭제</a>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn" href="?p=shipments">목록</a>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($id > 0 && ($cur['status'] ?? '') === 'CANCELLED'): ?>
  <div class="msg err">이 전표는 <b>취소</b> 상태입니다. 통계에는 그대로 집계되니 필요하면
    항목을 0 으로 고치거나 회계 담당과 상의하세요.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">

<div class="card">
  <div class="ch">전표 정보</div>
  <div class="cb f">
    <?php $awbManual = $id === 0 && $in['awb_mode'] === 'manual'; ?>
    <div class="fw" style="width:100%">
      <label for="awb_no">AWB 번호</label>
      <?php if ($id === 0): ?>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
        <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:13px;cursor:pointer">
          <input type="radio" name="awb_mode" value="auto"<?= $awbManual ? '' : ' checked' ?> onchange="awbMode()"> 자동 부여</label>
        <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:13px;cursor:pointer">
          <input type="radio" name="awb_mode" value="manual"<?= $awbManual ? ' checked' : '' ?> onchange="awbMode()"> 직접 입력 (운송사 번호 등)</label>
      </div>
      <?php endif; ?>
      <input type="text" id="awb_no" name="awb_no" class="tnum" maxlength="50" autocomplete="off"
             pattern="[A-Za-z0-9\-]{4,50}" title="영문 · 숫자 · - 로 4~50자"
             value="<?= h($id > 0 ? ($in['awb_no'] !== '' ? $in['awb_no'] : (string)$cur['awb_no']) : $in['awb_no']) ?>"
             placeholder="<?= $id > 0 ? '' : '저장할 때 자동으로 부여됩니다' ?>">
      <small style="color:var(--ink3)"><?= $id > 0
        ? '번호를 고치면 직접 입력한 번호로 표시되고, 이전 번호는 작업로그에 남습니다.'
        : '직접 입력한 번호는 다른 전표와 겹치면 저장되지 않습니다.' ?></small>
    </div>
    <?php if ($id === 0): ?>
    <script>
    function awbMode() {
      var manual = document.querySelector('input[name=awb_mode]:checked').value === 'manual';
      var f = document.getElementById('awb_no');
      f.disabled = !manual;
      f.required = manual;
      f.placeholder = manual ? '예: 1234567890 · 1Z999AA10123456784' : '저장할 때 자동으로 부여됩니다';
      if (manual) { f.focus(); } else { f.value = ''; }
    }
    document.addEventListener('DOMContentLoaded', function () {
      var f = document.getElementById('awb_no');
      var manual = document.querySelector('input[name=awb_mode]:checked').value === 'manual';
      f.disabled = !manual;
      f.required = manual;
    });
    </script>
    <?php endif; ?>
    <div class="fw w3"><label>거래처 *</label>
      <select name="company_id" required>
        <option value="">선택하세요</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['company_id']===(string)$c['id']?' selected':'' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (!$companies): ?>
        <small style="color:var(--err-fg)">거래처가 없습니다. <a href="?p=company_form">먼저 등록</a>하세요.</small>
      <?php endif; ?>
    </div>
    <div class="fw w1"><label>운송사 *</label>
      <select name="carrier_id" required data-search="운송사 코드 또는 이름">
        <option value="">선택</option>
        <?php foreach ($carriers as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['carrier_id']===(string)$c['id']?' selected':'' ?>><?= h($c['name']) ?> (<?= h($c['code']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label>구분</label>
      <select name="trade_type">
        <option value="EXPORT"<?= $in['trade_type']==='EXPORT'?' selected':'' ?>>수출</option>
        <option value="IMPORT"<?= $in['trade_type']==='IMPORT'?' selected':'' ?>>수입</option>
      </select></div>
    <div class="fw w1"><label>전표일 *</label>
      <input type="date" name="voucher_date" required value="<?= h($in['voucher_date']) ?>"></div>
    <div class="fw w1"><label>발송일</label>
      <input type="date" name="ship_date" value="<?= h($in['ship_date']) ?>"></div>
    <div class="fw w2"><label>도착지</label>
      <?php if ($dests): ?>
        <?php
        // 지금 값이 목록에 없으면(예전에 직접 적은 것) 그대로 보이게 끼워 둡니다
        $destKnown = $in['dest_city'] === '' || dest_country_of($dests, $in['dest_city']) !== null
                   || count(array_filter($dests, fn($d) => strcasecmp((string)$d['name'], $in['dest_city']) === 0)) > 0;
        ?>
        <select name="dest_city" data-search="도착지 · 한글 이름 · 국가코드">
          <option value="">선택 안 함</option>
          <?php if (!$destKnown): ?>
            <option value="<?= h($in['dest_city']) ?>" selected><?= h($in['dest_city']) ?> (목록에 없음)</option>
          <?php endif; ?>
          <?php foreach ($dests as $d): ?>
            <option value="<?= h($d['name']) ?>"<?= strcasecmp((string)$d['name'], $in['dest_city']) === 0 ? ' selected' : '' ?>>
              <?= h($d['name'] . ($d['name_ko'] ? ' · ' . $d['name_ko'] : '')) ?><?= $d['country_code'] ? ' (' . h($d['country_code']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (route_can_edit('destinations')): ?>
          <small><a href="?p=destinations" target="_blank" rel="noopener">목록에 없으면 도착지 관리에서 추가</a></small>
        <?php endif; ?>
      <?php else: ?>
        <input type="text" name="dest_city" value="<?= h($in['dest_city']) ?>" placeholder="LOS ANGELES">
        <small style="color:var(--ink3)">기준정보 &gt; 도착지 관리를 한 번 열면 목록에서 고를 수 있습니다.</small>
      <?php endif; ?></div>
    <div class="fw w2"><label>출고지 <span style="font-weight:400;color:var(--ink3)">보내는 곳</span></label>
      <?php if ($dests): ?>
        <?php $orgKnown = $in['origin_city'] === ''
            || count(array_filter($dests, fn($d) => strcasecmp((string)$d['name'], $in['origin_city']) === 0)) > 0; ?>
        <select name="origin_city" data-search="출고지 · 한글 이름 · 국가코드">
          <option value="">선택 안 함</option>
          <?php if (!$orgKnown): ?>
            <option value="<?= h($in['origin_city']) ?>" selected><?= h($in['origin_city']) ?> (목록에 없음)</option>
          <?php endif; ?>
          <?php foreach ($dests as $d): ?>
            <option value="<?= h($d['name']) ?>"<?= strcasecmp((string)$d['name'], $in['origin_city']) === 0 ? ' selected' : '' ?>>
              <?= h($d['name'] . ($d['name_ko'] ? ' · ' . $d['name_ko'] : '')) ?><?= $d['country_code'] ? ' (' . h($d['country_code']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" name="origin_city" value="<?= h($in['origin_city']) ?>" placeholder="SEOUL">
      <?php endif; ?></div>
  </div>
  <div class="cb f" style="border-top:1px solid var(--line2)">
    <div class="fw w1"><label>실중량 (kg)</label>
      <input type="text" name="actual_weight" class="tnum" value="<?= h($in['actual_weight']) ?>"></div>
    <div class="fw w1"><label>부피중량 (kg)</label>
      <input type="text" name="volume_weight" class="tnum" value="<?= h($in['volume_weight']) ?>"></div>
    <div class="fw w1"><label>EMS · 단가 직접입력
        <span style="font-weight:400;color:var(--ink3)">원가격</span></label>
      <input type="text" name="snap_base_price" class="tnum" style="text-align:right"
             value="<?= h($in['snap_base_price'] !== '' ? (string)(int)round((float)$in['snap_base_price']) : '') ?>"
             placeholder="63,700"></div>
    <div class="fw w1"><label>수량 (PCS)</label>
      <input type="text" name="package_count" class="tnum" value="<?= h($in['package_count']) ?>"></div>
    <div class="fw w1"><label>영업팀</label>
      <input type="text" name="sales_team" value="<?= h($in['sales_team']) ?>"></div>
    <div class="fw w1"><label>영업담당자</label>
      <input type="text" name="sales_rep" value="<?= h($in['sales_rep']) ?>"></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2)">
    <div class="fw" style="width:100%"><label for="remark">비고</label>
      <textarea id="remark" name="remark" rows="3"
                placeholder="특이사항 · 고객 요청 · 통관 메모 등"><?= h($in['remark']) ?></textarea></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:11.5px;color:var(--ink3)">
    청구중량은 실중량과 부피중량 중 큰 값이 자동으로 들어갑니다.
    <b>EMS · 단가 직접입력</b>에 원가격을 넣으면 가격표를 찾지 않고 그 금액에 업체 할인율과 유류할증을 적용합니다
    (가격표가 등록된 운송사는 비워 두세요 — 가격표에서 저절로 가져옵니다).
  </div>
</div>

<!-- 단가 도우미 — 운송사 가격표의 원가격을 불러와 할인 · 유류할증까지 계산해 보여 줍니다 -->
<div class="card" id="ratecard">
  <div class="ch">단가 자동계산
    <span style="font-weight:400;color:var(--ink3)">운송사 가격표의 원가격에 업체 할인율 · 유류할증을 적용합니다</span>
    <button type="button" class="btn sm" id="rate-go" style="margin-left:auto">단가 불러오기</button>
  </div>
  <div class="cb" id="rate-box" style="font-size:12.5px;color:var(--ink3)">
    거래처 · 운송사 · 도착지 · 중량을 넣으면 여기에 원가격이 나옵니다.
  </div>
</div>

<div class="card">
  <div class="ch">비용항목
    <span style="font-weight:400;color:var(--ink3)">세금구분은 줄마다 따로 정합니다 — 한 전표에 영세와 과세가 섞일 수 있습니다</span>
  </div>
  <table>
    <thead><tr>
      <th style="width:40px" class="c">#</th>
      <th style="width:150px">종류</th>
      <th>항목명</th>
      <th style="width:150px" class="r">공급가액</th>
      <th style="width:120px">세금구분</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td class="c tnum"><?= $i+1 ?></td>
        <td><select name="line[<?= $i ?>][charge_type]" class="ctype">
          <?php foreach ($CHARGE as $k=>$v): ?>
            <option value="<?= h($k) ?>" data-tax="<?= h(charge_default_tax($k)) ?>"<?= $l['charge_type']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><input type="text" name="line[<?= $i ?>][item_name]" value="<?= h($l['item_name']) ?>"
                   placeholder="<?= $i===0 ? 'EXPRESS WORLDWIDE' : '' ?>"></td>
        <td><input type="text" class="tnum" style="text-align:right"
                   name="line[<?= $i ?>][supply_amount]" value="<?= h($l['supply_amount']) ?>"></td>
        <td><select name="line[<?= $i ?>][tax_type]">
          <option value="ZERO"<?= $l['tax_type']==='ZERO'?' selected':'' ?>>영세율 0%</option>
          <option value="TAXABLE"<?= $l['tax_type']==='TAXABLE'?' selected':'' ?>>과세 10%</option>
          <option value="EXEMPT"<?= $l['tax_type']==='EXEMPT'?' selected':'' ?>>면세</option>
        </select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <script>
  // 단가 자동계산 — 가격표의 원가격 → 할인 → 유류할증 → 적용운임
  (function () {
    var box = document.getElementById('rate-box');
    if (!box) { return; }
    function val(n) { var e = document.querySelector('[name="' + n + '"]'); return e ? e.value : ''; }
    function money(n) { return Math.round(n).toLocaleString('ko-KR'); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function fill(amount, name) {
      // 첫 줄(운송) 금액 칸에 넣습니다 — 이미 적힌 값이 있으면 물어보고 덮어씁니다
      var amt = document.querySelector('input[name="line[0][supply_amount]"]');
      var item = document.querySelector('input[name="line[0][item_name]"]');
      if (!amt) { return; }
      if (amt.value.trim() !== '' && !confirm('첫 줄에 이미 ' + amt.value + ' 이 적혀 있습니다. 덮어쓸까요?')) { return; }
      amt.value = money(amount);
      if (item && item.value.trim() === '') { item.value = name; }
      amt.focus();
    }

    function show(d) {
      if (!d) { box.innerHTML = '<span style="color:#B3261E">단가를 불러오지 못했습니다.</span>'; return; }
      if (!d.ok) {
        box.innerHTML = '<b style="color:#B3261E">계산할 수 없습니다.</b><br>'
          + (d.why && d.why.length ? d.why.map(esc).join('<br>') : '조건을 확인하세요.')
          + '<br><span style="font-size:11.5px">가격표는 <a href="?p=rate_table" target="_blank">특송 기본가격표</a>, '
          + '할인율은 <a href="?p=company_terms" target="_blank">업체별 할인율</a>에서 넣습니다.</span>';
        return;
      }
      var t = d.table || {};
      box.innerHTML =
        '<table style="width:auto;min-width:420px"><tbody>'
        + '<tr><td>원가격 (' + esc(d.base_src || '가격표') + ')</td><td class="r tnum"><b>' + money(d.base) + '</b> 원</td>'
        + '<td style="color:var(--ink3);font-size:11.5px">'
        + (d.base_src === '직접 입력'
            ? '직접 넣은 금액에 할인 · 유류할증만 적용합니다'
            : 'Zone ' + d.zone + ' · ' + esc(t.weight_from) + '~' + esc(t.weight_to) + 'kg · ' + esc(t.name || ''))
        + '</td></tr>'
        + '<tr><td>할인 ' + d.disc + '%</td><td class="r tnum">- ' + money(d.disc_amt) + ' 원</td>'
        + '<td style="color:var(--ink3);font-size:11.5px">' + esc(d.disc_src) + '</td></tr>'
        + '<tr><td>할인 후 운임</td><td class="r tnum">' + money(d.after) + ' 원</td><td></td></tr>'
        + '<tr><td>유류할증 ' + d.fuel + '%</td><td class="r tnum">+ ' + money(d.fuel_amt) + ' 원</td>'
        + '<td style="color:var(--ink3);font-size:11.5px">' + esc(d.fuel_src)
        + (d.fuel_applied ? '' : ' · 이 거래처는 유류할증 제외') + '</td></tr>'
        + '<tr style="background:#F7FAFB"><td><b>적용 운임</b></td>'
        + '<td class="r tnum"><b style="font-size:15px">' + money(d.supply) + '</b> 원</td>'
        + '<td><button type="button" class="btn sm pri" id="rate-fill">운임 줄에 넣기</button></td></tr>'
        + '</tbody></table>'
        + '<div style="font-size:11.5px;margin-top:6px">청구중량 ' + d.cw + 'kg 기준입니다. '
        + '금액은 넣은 뒤 손으로 고칠 수 있습니다.</div>';
      var b = document.getElementById('rate-fill');
      if (b) { b.addEventListener('click', function () { fill(d.supply, val('dest_city') ? 'EXPRESS ' + val('dest_city') : '운임'); }); }
    }

    function load() {
      var q = new URLSearchParams({
        p: 'rate_quote',
        carrier_id: val('carrier_id'), company_id: val('company_id'),
        trade_type: val('trade_type'), on_date: val('voucher_date'),
        dest_city: val('dest_city'),
        actual_weight: val('actual_weight'), volume_weight: val('volume_weight'),
        base: val('snap_base_price')
      });
      if (!val('carrier_id')) { box.textContent = '운송사를 먼저 고르세요.'; return; }
      if (!val('actual_weight') && !val('volume_weight') && !val('snap_base_price')) {
        box.textContent = '중량을 넣거나, EMS 처럼 가격표가 없으면 원가격을 직접 넣으세요.';
        return;
      }
      box.textContent = '불러오는 중…';
      fetch('?' + q.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(show)
        .catch(function () { box.innerHTML = '<span style="color:#B3261E">불러오지 못했습니다.</span>'; });
    }
    document.getElementById('rate-go').addEventListener('click', load);
    // 운송사 · 도착지 · 중량을 바꾸면 저절로 다시 계산합니다
    ['carrier_id', 'dest_city', 'actual_weight', 'volume_weight', 'company_id', 'trade_type', 'snap_base_price'].forEach(function (n) {
      var e = document.querySelector('[name="' + n + '"]');
      if (!e) { return; }
      e.addEventListener('change', function () {
        if (val('carrier_id') && (val('actual_weight') || val('volume_weight') || val('snap_base_price'))) { load(); }
      });
    });
    if (val('carrier_id') && (val('actual_weight') || val('volume_weight') || val('snap_base_price'))) { load(); }
  })();

  // 종류를 고르면 회사 기준 세금구분으로 (운송 = 영세율, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관 = 과세)
  document.querySelectorAll('select.ctype').forEach(function (s) {
    s.addEventListener('change', function () {
      var tax = s.options[s.selectedIndex].getAttribute('data-tax');
      var t = s.closest('tr').querySelector('select[name$="[tax_type]"]');
      if (tax && t) { t.value = tax; }
    });
  });
  </script>
  <div class="pager"><span>비어 있는 줄은 저장하지 않습니다. VAT 는 과세 항목에만 10% 로 계산됩니다.
    종류를 고르면 세금구분이 회사 기준으로 바뀝니다 (운송 = 영세율 · 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관 = 과세).
    <?= $id > 0 ? '수정 시 기존 항목을 지우고 다시 넣습니다 — 지워진 내용은 이력에 남습니다.' : '' ?></span></div>
</div>

<div class="card" id="cost-card">
  <div class="ch">매입원가 <span style="font-weight:400;color:var(--ink3)">운송사 매입가 + 추가 매입가 = 매입원가 → 손익에 바로 반영</span></div>
  <div class="cb f" style="align-items:flex-end">
    <?php $bLock = $pur['base'] && $pur['base']['locked']; $xLock = $pur['extra'] && $pur['extra']['locked']; ?>
    <div class="fw w2"><label for="cost_base">운송사 매입가 <?= $id > 0 && $pur['base'] ? '(불러온 값)' : '' ?></label>
      <input type="text" id="cost_base" name="cost_base" class="tnum cost" inputmode="numeric" style="text-align:right"
             value="<?= h($in['cost_base']) ?>" placeholder="0"<?= $bLock ? ' readonly' : '' ?>>
      <?php if ($bLock): ?><small style="color:var(--warn-fg)">지급된 매입 — 입출금에서만 정리</small><?php endif; ?></div>
    <div class="fw w2"><label for="cost_extra">추가 매입가</label>
      <input type="text" id="cost_extra" name="cost_extra" class="tnum cost" inputmode="numeric" style="text-align:right"
             value="<?= h($in['cost_extra']) ?>" placeholder="0"<?= $xLock ? ' readonly' : '' ?>>
      <?php if ($xLock): ?><small style="color:var(--warn-fg)">지급된 매입 — 입출금에서만 정리</small><?php endif; ?></div>
    <div class="fw gr" style="min-width:200px"><label for="cost_extra_memo">추가 매입 내용</label>
      <input type="text" id="cost_extra_memo" name="cost_extra_memo" maxlength="200"
             value="<?= h($in['cost_extra_memo']) ?>" placeholder="예) 픽업비 · 통관수수료 · 포장비"></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2)">
    <div class="kpis" style="gap:10px">
      <div class="kpi"><div class="lab">매출 공급가</div><div class="val tnum" id="k-rev">0</div></div>
      <div class="kpi"><div class="lab">매입원가</div><div class="val tnum" id="k-cost">0</div>
        <div class="sub" id="k-cost-sub"><?= $pur['other'] > 0 ? '매입관리에서 넣은 ' . money($pur['other']) . '원 포함' : '운송사 + 추가' ?></div></div>
      <div class="kpi"><div class="lab">이익</div><div class="val tnum" id="k-profit">0</div>
        <div class="sub" id="k-rate">이익률 -</div></div>
    </div>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      매입은 부가세 빼고 공급가로 적습니다. 비우거나 0 으로 두면 그 매입 줄을 내립니다.
      세금계산서 · 지급은 매입관리 · 출금 화면에서 합니다.</div>
  </div>
</div>
<script>
(function () {
  var OTHER = <?= json_encode((float)$pur['other']) ?>;
  function n(v) { var t = String(v || '').replace(/[,\s]/g, ''); return t === '' || isNaN(Number(t)) ? 0 : Number(t); }
  function fmt(v) { return Math.round(v).toLocaleString('ko-KR'); }
  function calc() {
    var rev = 0;
    document.querySelectorAll('input[name$="[supply_amount]"]').forEach(function (i) { rev += n(i.value); });
    var cost = n(document.getElementById('cost_base').value) + n(document.getElementById('cost_extra').value) + OTHER;
    var profit = rev - cost;
    document.getElementById('k-rev').textContent = fmt(rev);
    document.getElementById('k-cost').textContent = fmt(cost);
    var p = document.getElementById('k-profit');
    p.textContent = fmt(profit);
    p.style.color = profit < 0 ? 'var(--err-fg)' : '';
    document.getElementById('k-rate').textContent = rev > 0 ? '이익률 ' + (profit / rev * 100).toFixed(1) + '%' : '이익률 -';
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches('.cost, input[name$="[supply_amount]"]')) { calc(); }
  });
  calc();
})();
</script>

<?php if ($id > 0): ?>
<div class="card">
  <div class="ch">수정 사유 <span style="font-weight:400;color:var(--err-fg)">필수</span></div>
  <div class="cb">
    <input type="text" name="reason" value="<?= h($reason) ?>" required
           placeholder="예) 운임 단가 오입력 정정 — 거래처 확인 완료">
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      금액이 걸린 화면이라 사유를 남깁니다. 변경 전후 내용과 함께 작업로그에 기록됩니다.
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($id === 0 && $canDoc): ?>
<div class="card">
  <div class="ch">관련서류 <span style="font-weight:400;color:var(--ink3)">인보이스 · 패킹리스트 · 신고필증 등 — 여러 개 한 번에</span></div>
  <div class="cb f" style="align-items:flex-end">
    <div class="fw w2"><label for="dtype">서류 종류</label>
      <select id="dtype" name="doc_type_id">
        <?php foreach ($docTypes as $t): ?>
          <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === $docDefault ? ' selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw gr" style="min-width:240px"><label for="docs">파일 찾아보기</label>
      <input type="file" id="docs" name="docs[]" multiple
             accept=".<?= h(implode(',.', DOC_EXT)) ?>"></div>
  </div>
  <div class="cb" style="padding-top:0;font-size:11.5px;color:var(--ink3)">
    전표를 저장할 때 같이 올라갑니다 (파일 하나 <?= DOC_MAX_LABEL ?> 까지). 저장이 안 되면(입력 오류) 파일을 다시 골라 주세요.
    올린 서류는 매일 밤 회사 NAS 로 백업됩니다.</div>
</div>
<?php endif; ?>

<div style="display:flex;gap:8px">
  <button class="btn pri"><?= $id > 0 ? '수정 저장' : '저장' ?></button>
  <a class="btn" href="?p=shipments">취소</a>
</div>
</form>

<?php if ($id > 0 && $seeDoc):
  $st = db()->prepare(
      'SELECT d.id, d.title, d.original_name, d.size_bytes, d.created_at, d.backup_status,
              t.name AS type_name, a.name AS uploader
         FROM documents d
         LEFT JOIN document_types t ON t.id = d.document_type_id
         LEFT JOIN admins a ON a.id = d.uploaded_by
        WHERE d.shipment_id = ? AND d.business_entity_id = ? AND d.deleted_at IS NULL
        ORDER BY d.id DESC');
  $st->execute([$id, $eid]);
  $docs = $st->fetchAll(); ?>
<div class="card" id="docs-card">
  <div class="ch">관련서류 <span class="badge b-info"><?= count($docs) ?>개</span>
    <a class="btn sm" style="margin-left:auto" href="?p=documents&amp;shipment_id=<?= $id ?>">문서보관함에서 보기</a></div>
  <?php if ($canDoc): ?>
  <div class="cb" style="border-bottom:1px solid var(--line)">
    <form method="post" enctype="multipart/form-data" class="f" style="align-items:flex-end"
          action="?p=documents&amp;shipment_id=<?= $id ?>&amp;back=sf">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <div class="fw w2"><label for="dtype2">서류 종류</label>
        <select id="dtype2" name="document_type_id">
          <?php foreach ($docTypes as $t): ?>
            <option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === $docDefault ? ' selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw gr" style="min-width:240px"><label for="files">파일 찾아보기 (여러 개)</label>
        <input type="file" id="files" name="files[]" multiple required
               accept=".<?= h(implode(',.', DOC_EXT)) ?>"></div>
      <button class="btn pri">올리기</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if (!$docs): ?>
    <div class="empty">아직 올린 서류가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">종류</th><th>파일</th><th class="r" style="width:80px">크기</th>
      <th style="width:140px">올린 때</th><th style="width:80px">올린 사람</th>
      <th class="c" style="width:80px">NAS 백업</th><th class="c" style="width:120px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($docs as $d):
      $kb = (int)$d['size_bytes'];
      $size = $kb >= 1048576 ? number_format($kb / 1048576, 1) . 'MB' : number_format(max(1, $kb / 1024)) . 'KB'; ?>
      <tr>
        <td><?= h($d['type_name'] ?? '-') ?></td>
        <td style="white-space:normal"><a href="?p=file_download&amp;id=<?= (int)$d['id'] ?>"><?= h($d['original_name'] ?: $d['title']) ?></a>
          <?php if ($d['title'] && $d['title'] !== $d['original_name']): ?>
            <div style="font-size:11.5px;color:var(--ink3)"><?= h($d['title']) ?></div><?php endif; ?></td>
        <td class="r tnum"><?= h($size) ?></td>
        <td class="tnum" style="font-size:12px"><?= h($d['created_at']) ?></td>
        <td><?= h($d['uploader'] ?? '-') ?></td>
        <td class="c"><?= $d['backup_status'] === 'SYNCED' ? '<span class="badge b-ok">완료</span>'
              : ($d['backup_status'] === 'FAILED' ? '<span class="badge b-err">실패</span>'
              : '<span class="badge b-warn">대기</span>') ?></td>
        <td class="c">
          <a class="btn sm" href="?p=file_download&amp;id=<?= (int)$d['id'] ?>">받기</a>
          <?php if ($canDoc): ?>
          <form method="post" style="display:inline" action="?p=documents&amp;shipment_id=<?= $id ?>&amp;back=sf"
                onsubmit="return confirm('이 서류를 목록에서 내릴까요? 파일은 서버에 남습니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="remove">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn sm">내리기</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ---------------------------------------------------------------- 청구 — 이 전표에서 바로 청구서 만들기
if ($id > 0 && ($cur['status'] ?? '') !== 'CANCELLED' && route_can_view('billing')):
    $st = db()->prepare('SELECT i.id, i.invoice_no, i.invoice_date, i.status, i.due_date, i.balance, i.grand_total
                           FROM invoice_shipments xs JOIN invoices i ON i.id = xs.invoice_id
                          WHERE xs.shipment_id = ? LIMIT 1');
    $st->execute([$id]);
    $inv = $st->fetch();
    $others = [];
    if (!$inv) {
        // 같은 거래처의 다른 미청구 전표 — 같이 묶어 청구할 수 있게
        $st = db()->prepare(
            "SELECT s.id, s.awb_no, s.voucher_date, COALESCE(t.grand_total, 0) AS grand_total
               FROM shipments s
               LEFT JOIN v_shipment_totals t  ON t.shipment_id = s.id
               LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
              WHERE s.business_entity_id = ? AND s.company_id = ? AND s.id <> ?
                AND s.deleted_at IS NULL AND s.status <> 'CANCELLED' AND xs.id IS NULL
                AND s.voucher_date >= ? - INTERVAL 62 DAY
              ORDER BY s.voucher_date DESC, s.id DESC LIMIT 30");
        $st->execute([$eid, (int)$cur['company_id'], $id, $cur['voucher_date']]);
        $others = $st->fetchAll();
        $myTotal = db()->prepare('SELECT COALESCE(grand_total, 0) FROM v_shipment_totals WHERE shipment_id = ?');
        $myTotal->execute([$id]);
        $myTotal = (float)$myTotal->fetchColumn();
    }
?>
<div class="card" id="bill-card">
  <div class="ch">청구
    <?php if ($inv): [$il, $ic] = invoice_state($inv); ?>
      <span class="badge <?= $ic ?>"><?= h($il) ?></span>
    <?php else: ?>
      <span class="badge b-warn">미청구</span>
    <?php endif; ?></div>
  <?php if ($inv): ?>
  <div class="cb" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span>청구서 <b class="tnum"><?= h($inv['invoice_no']) ?></b> · <span class="tnum"><?= h($inv['invoice_date']) ?></span>
      · 합계 <b class="tnum"><?= money($inv['grand_total']) ?></b> · 미수 <span class="tnum"><?= money($inv['balance']) ?></span></span>
    <a class="btn sm pri" style="margin-left:auto" href="?p=invoice_view&amp;id=<?= (int)$inv['id'] ?>">청구서 열기</a>
  </div>
  <?php elseif (!route_can_edit('billing')): ?>
    <div class="empty">아직 청구하지 않은 전표입니다. 청구서는 청구 권한이 있는 사람이 만듭니다.</div>
  <?php else: ?>
  <form method="post" action="?p=billing" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="create_quick">
    <input type="hidden" name="back" value="form">
    <input type="hidden" name="ship[]" value="<?= $id ?>">
    <?php if ($others): ?>
    <div style="font-size:12.5px;font-weight:600;margin-bottom:6px">같은 거래처의 다른 미청구 전표도 같이 청구하려면 고르세요
      <label style="font-weight:400;margin-left:8px"><input type="checkbox" onchange="document.querySelectorAll('.bill-other').forEach(function(c){c.checked=this.checked;c.dispatchEvent(new Event('change'))},this)"> 모두</label></div>
    <div style="max-height:220px;overflow:auto;border:1px solid var(--line);border-radius:8px;margin-bottom:10px">
      <?php foreach ($others as $o): ?>
      <label style="display:flex;gap:10px;align-items:center;padding:6px 10px;border-bottom:1px solid var(--line2);font-size:12.5px">
        <input type="checkbox" class="bill-other" name="ship[]" value="<?= (int)$o['id'] ?>" data-amt="<?= (float)$o['grand_total'] ?>">
        <span class="tnum" style="width:90px"><?= h($o['voucher_date']) ?></span>
        <span class="tnum" style="flex:1;font-weight:600"><?= h($o['awb_no']) ?></span>
        <span class="tnum"><?= money($o['grand_total']) ?></span>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label>청구일</label><input type="date" name="invoice_date" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label>지급기한</label><input type="date" name="due_date" value="<?= h(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
      <div style="margin-left:auto;text-align:right">
        <div style="font-size:11.5px;color:var(--ink2)">청구 합계 (<span id="bill-cnt">1</span>건)</div>
        <div class="tnum" style="font-size:19px;font-weight:700" id="bill-sum" data-base="<?= $myTotal ?>"><?= money($myTotal) ?></div>
      </div>
      <button class="btn pri">청구서 만들기</button>
    </div>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      작성중(DRAFT) 청구서가 만들어지고 청구서 화면으로 넘어갑니다. 내용을 확인한 뒤 거기서 발행합니다.
      <?php if ($id > 0): ?>전표 내용을 고쳤다면 먼저 위에서 <b>수정 저장</b> 하세요.<?php endif; ?></div>
  </form>
  <script>
  (function () {
    var sum = document.getElementById('bill-sum'), cnt = document.getElementById('bill-cnt');
    function upd() {
      var t = parseFloat(sum.getAttribute('data-base')) || 0, n = 1;
      document.querySelectorAll('.bill-other:checked').forEach(function (c) { t += parseFloat(c.getAttribute('data-amt')) || 0; n++; });
      sum.textContent = Math.round(t).toLocaleString('ko-KR'); cnt.textContent = n;
    }
    document.querySelectorAll('.bill-other').forEach(function (c) { c.addEventListener('change', upd); });
  })();
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($id > 0 && route_can_edit('shipments')): ?>
<!-- 전표 삭제 — 목록의 휴지통과 같은 처리(shipments.php act=delete): 청구 · 입금 · 지급된 매입이 있으면 막고,
     삭제 권한이 없으면 관리자 승인 요청으로 넘어갑니다 -->
<div class="card" id="del-card" style="border-color:#E4B9B9;background:#FFF8F8">
  <div class="ch" style="border-color:#E4B9B9">전표 삭제</div>
  <div class="cb">
    <form method="post" action="?p=shipments" class="f" style="align-items:flex-end"
          onsubmit="return confirm('매출전표 <?= h(addslashes((string)$cur['awb_no'])) ?> 을 삭제합니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="delete">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="fw gr" style="min-width:320px">
        <label>삭제 사유 *</label>
        <input type="text" name="reason" required minlength="2" placeholder="예) 중복 입력 · 테스트 전표">
      </div>
      <button class="btn" style="border-color:#D99;color:#A32020">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        전표 삭제</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      목록 · 통계 · 미수에서 빠지고, 작업로그에는 사유와 함께 남습니다. 연결된 매입원가도 같이 빠집니다.
      <b>청구서에 들어 있거나 입금 · 지급이 붙은 전표는 삭제되지 않습니다</b> — 그럴 땐 아래 '전표 취소' 를 쓰거나 청구 · 입출금을 먼저 취소하세요.
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($id > 0 && ($cur['status'] ?? '') !== 'CANCELLED'): ?>
<div class="card" style="border-color:#F0D9AE;background:#FFFCF6">
  <div class="ch" style="border-color:#F0D9AE">전표 취소</div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end"
          onsubmit="return confirm('이 전표를 취소 상태로 바꿉니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <div class="fw gr" style="min-width:320px">
        <label>취소 사유 *</label>
        <input type="text" name="cancel_reason" required placeholder="예) 고객 요청으로 발송 취소">
      </div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">전표 취소</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      <b>지우지 않습니다.</b> 상태만 <code>CANCELLED</code> 로 바뀌고 내용은 그대로 남습니다.
      되돌리려면 이 화면에서 다시 저장하면 됩니다.
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ---------------------------------------------------------------- 변경 이력
if ($id > 0) {
    $st = db()->prepare(
        'SELECT action, admin_name, reason, before_value, after_value, created_at
           FROM activity_logs
          WHERE ref_table = \'shipments\' AND ref_id = ?
          ORDER BY id DESC LIMIT 10');
    $st->execute([$id]);
    $logs = $st->fetchAll();
    if ($logs):
?>
<div class="card">
  <div class="ch">이 전표의 변경 이력</div>
  <table>
    <thead><tr>
      <th style="width:150px">일시</th><th style="width:90px">작업</th>
      <th style="width:90px">담당</th><th>사유 · 변경 내용</th>
    </tr></thead>
    <tbody>
    <?php foreach ($logs as $g): ?>
      <tr>
        <td class="tnum"><?= h($g['created_at']) ?></td>
        <td><span class="badge <?= $g['action']==='CANCEL'?'b-err':($g['action']==='CREATE'?'b-ok':'b-info') ?>"><?= h($g['action']) ?></span></td>
        <td><?= h($g['admin_name']) ?></td>
        <td style="font-size:11.5px">
          <?php if ($g['reason']): ?><b><?= h($g['reason']) ?></b><br><?php endif; ?>
          <?php if ($g['before_value']): ?>
            <span style="color:var(--ink3)">전 · <?= h($g['before_value']) ?></span><br>
          <?php endif; ?>
          <?php if ($g['after_value']): ?>
            <span style="color:var(--ink3)">후 · <?= h($g['after_value']) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
    endif;
}
layout_foot();
