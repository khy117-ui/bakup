<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/hawb.php';
require_once APP_DIR . '/xlsx.php';

/**
 * 수입 서류 만들기 — 받은 엑셀 양식 그대로 내려받습니다.
 *
 *   ① 영문 통관목록  (NEW_Exp_air_freight_template — 英文)
 *   ② 중문 적하목록  (NEW_Exp_air_freight_template — 中文)
 *   ③ COMMERCIAL INVOICE (송장 한 건에 한 장)
 *   ④ HAWB 비엘 — 인쇄 화면(app/hawb.php)
 *
 * 칸 순서와 머리글은 받은 파일과 같게 맞췄습니다. 세관에 그대로 올릴 수 있습니다.
 */

/** 항공편(적하목록) 한 건 + 그 안의 송장 · 품목을 한꺼번에 읽어 옵니다 */
function hawb_batch_load(int $batchId, int $entId): ?array
{
    $st = db()->prepare('SELECT * FROM hawb_batches WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$batchId, $entId]);
    $b = $st->fetch();
    if (!$b) { return null; }

    $st = db()->prepare('SELECT * FROM hawbs WHERE batch_id = ? AND deleted_at IS NULL ORDER BY house_no, id');
    $st->execute([$batchId]);
    $rows = $st->fetchAll();

    $items = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $st  = db()->prepare("SELECT * FROM hawb_items WHERE hawb_id IN ($ph) ORDER BY hawb_id, line_no, id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $it) { $items[(int)$it['hawb_id']][] = $it; }
    }
    foreach ($rows as &$r) { $r['items'] = $items[(int)$r['id']] ?? []; }
    unset($r);

    $b['hawbs'] = $rows;
    return $b;
}

/** 송장 한 건 + 품목 */
function hawb_one_load(int $id, int $entId): ?array
{
    $st = db()->prepare('SELECT * FROM hawbs WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $entId]);
    $h = $st->fetch();
    if (!$h) { return null; }
    $st = db()->prepare('SELECT * FROM hawb_items WHERE hawb_id = ? ORDER BY line_no, id');
    $st->execute([$id]);
    $h['items'] = $st->fetchAll();
    return $h;
}

/** 숫자로 넣을 값 — 빈 칸은 '' (엑셀에서 빈 칸으로) */
function hawb_x_num($v)
{
    if ($v === null || $v === '') { return ''; }
    $f = (float)$v;
    return $f == (int)$f ? (int)$f : $f;
}

/** ① 영문 통관목록 (한국 세관) */
function hawb_xlsx_en(array $b): string
{
    $rows = [];
    $rows[] = ['DATE/Time', (string)$b['flight_date']];
    $rows[] = ['FLT NO.', (string)$b['flight_no']];
    $rows[] = ['MAWB NO', (string)$b['mawb_no'], '', '', '',
               '세부품목', '상세물품 일련번호', '상세물품 품명', '상세물품 규격', '수량', '수량단위', '상세물품 금액 ( USD )'];
    $rows[] = [
        'HAWB NO', 'HSN', 'PCS', '실제수량', "W'T", 'VALUE', 'DESCRITPION', 'WAREHOUSE',
        'SHIPPER', 'S/ADDRESS', 'CONSIGNEE', 'C/ADDRESS', 'Consignee Phone',
        "NOTIFY\n（填写：same as above)", 'N/ADDRESS (미기재)',
        "거래코드\n(进口方式分类）\nA. 전자상거래 D.개인일반물품 E.상용견품 F.상업서류",
        "발송국가코드\n(2코드)", "용도구분\n(1:개인,2:회사)", '화물운송주선업자부호', '특별통관거래대상지정번호',
        '홈페이지주소', '통관허용품목', '수하인우편번호', '개인통관고유부호',
        '수하인한글상호 (미기재)', '수하인한글주소(미기재)', '청구지(미기재)', '송하인전화번호',
        '수하인사업자번호', "전자상거래 유형\n(A:직접구매 B:구매대행 C:배송대행 Z:파악불가)",
        '해외판매자 부호', '해외판매자 상호', '구매대행업자 부호', '구매대행업자 상호',
        '판매중계자 부호', '판매중계자 상호', '주문번호',
    ];

    foreach ($b['hawbs'] as $h) {
        $desc = (string)$h['description'];
        if ($desc === '' && $h['items']) {
            $desc = implode(', ', array_filter(array_column($h['items'], 'name_en')));
        }
        $rows[] = [
            (string)$h['house_no'], (string)$h['hsn'], hawb_x_num($h['pieces']), hawb_x_num($h['actual_qty']),
            hawb_x_num($h['weight']), hawb_x_num($h['declared_value']), $desc, (string)$h['warehouse'],
            (string)$h['shipper_name'], (string)$h['shipper_addr'],
            (string)$h['consignee_name'], (string)$h['consignee_addr'], (string)$h['consignee_phone'],
            (string)$h['notify'], '',
            (string)$h['trade_code'], (string)$h['sender_country'], hawb_x_num($h['use_type']),
            (string)$h['agent_code'], (string)$h['special_no'], (string)$h['homepage'], (string)$h['allow_code'],
            (string)$h['consignee_zip'], (string)$h['pcc_no'],
            (string)$h['consignee_name_ko'], (string)$h['consignee_addr_ko'], '',
            (string)$h['shipper_phone'], (string)$h['consignee_biz_no'], (string)$h['ecom_type'],
            '', '', '', '', '', '', (string)$h['order_no'],
        ];
    }

    $widths = [14, 8, 6, 8, 8, 9, 28, 10, 18, 40, 16, 40, 15, 14, 12, 14, 10, 10, 14, 14,
               14, 10, 12, 18, 14, 28, 10, 16, 14, 12, 10, 14, 10, 14, 10, 14, 14];
    return xlsx_build('英文', $rows, $widths, [2, 3]);
}

/** ② 중문 적하목록 (중국 세관) — 송장의 품목마다 한 줄 */
function hawb_xlsx_cn(array $b): string
{
    $rows = [];
    $rows[] = ['总运单号', (string)$b['mawb_no']];
    $rows[] = ['进出日期(进港日期)', (string)$b['flight_date']];
    $rows[] = ['起运港/抵运地', trim((string)$b['origin_port'] . ' / ' . (string)$b['dest_port'], ' /')];
    $rows[] = ['运输工具航次', (string)$b['flight_no']];
    $rows[] = ['舱单进/出口标志', (string)$b['io_flag']];
    $rows[] = ['运输方式', (string)$b['transport_mode']];
    $rows[] = ['进出口岸代码', (string)$b['port_code']];
    $rows[] = ['송장번호', '상품번호', '중문물품이름', '영문 물품이름', '규격', '건수', '중량', '수량',
               '계량단위', '총가격', '', '받는회사이름', '받는회사도시', '받는회사 주소', '받는회사 전화번호',
               '보내는회사이름', '보내는회사주소', '보내는화사전화번호', '', '보내는사람국가', '보내는사람 도시',
               '', '무역 방식', '원산지'];
    $rows[] = ['分运单号', '商品编号附加编号', '中文货物名称', '英文货物名称', '规格/型号', '件数', '重量',
               '数量', '计量单位', '申报总价', '币制', '收件公司名', '收件公司城市', '收件公司地址',
               '收件公司电话', '发件公司名', '发件公司地址', '发件公司电话', '发件公司社会信用代码',
               '发件人国别', '发件人城市', '报关类别', '贸易方式', '原产/消费国', '经营单位代码', '经营单位名称'];

    foreach ($b['hawbs'] as $h) {
        $items = $h['items'];
        if (!$items) {
            // 품목을 따로 적지 않았으면 송장 자체를 한 줄로 내보냅니다
            $items = [[
                'item_code' => '', 'name_cn' => '', 'name_en' => (string)$h['description'], 'spec' => '',
                'pieces' => $h['pieces'], 'weight' => $h['weight'], 'qty' => $h['actual_qty'],
                'unit' => (string)$h['cn_unit'], 'amount' => $h['declared_value'],
                'currency' => (string)$h['cn_currency'], 'origin_country' => (string)$h['cn_origin'],
            ]];
        }
        foreach ($items as $i => $it) {
            $first = $i === 0;
            $rows[] = [
                $first ? (string)$h['house_no'] : '',
                (string)($it['item_code'] ?? ''), (string)($it['name_cn'] ?? ''), (string)($it['name_en'] ?? ''),
                (string)($it['spec'] ?? ''), hawb_x_num($it['pieces'] ?? ''), hawb_x_num($it['weight'] ?? ''),
                hawb_x_num($it['qty'] ?? ''), (string)($it['unit'] ?? $h['cn_unit']),
                hawb_x_num($it['amount'] ?? ''), (string)($it['currency'] ?? $h['cn_currency']),
                $first ? (string)$h['consignee_name'] : '',
                (string)$h['consignee_city'],
                $first ? (string)$h['consignee_addr'] : '',
                $first ? (string)$h['consignee_phone'] : '',
                (string)$h['shipper_name'], (string)$h['shipper_addr_cn'], (string)$h['shipper_phone'],
                (string)$h['shipper_credit_no'], (string)$h['shipper_country'], (string)$h['shipper_city'],
                (string)$h['decl_type'], (string)$h['trade_mode'],
                (string)($it['origin_country'] ?? $h['cn_origin']),
                (string)$b['operator_code'], (string)$b['operator_name'],
            ];
        }
    }

    $widths = [16, 16, 16, 20, 12, 8, 8, 8, 10, 10, 8, 18, 14, 40, 16, 18, 34, 16, 20, 12, 12, 10, 10, 12, 14, 26];
    return xlsx_build('中文', $rows, $widths, [7, 8]);
}

/** ③ COMMERCIAL INVOICE — 송장 한 건 */
function hawb_xlsx_invoice(array $h, array $b, array $be): string
{
    $company = trim((string)($be['name_en'] ?? '')) !== '' ? (string)$be['name_en'] : 'GOOD POST CO.,LTD.';
    $rows = [];
    $rows[] = ['COMMERCIAL INVOICE'];
    $rows[] = [];
    $rows[] = [' Shipper / Exporter', '', '', ' No. & Date of Invoice'];
    $rows[] = [(string)$h['shipper_name'], '', '', (string)$h['house_no'], (string)($b['flight_date'] ?? '')];
    $rows[] = [(string)$h['shipper_addr'], '', '', ' No. & Date of L/C'];
    $rows[] = [];
    $rows[] = [' Tel.', (string)$h['shipper_phone'], '', ' L/C Issuing Bank'];
    $rows[] = [' Consignee / Importer'];
    $rows[] = [(string)$h['consignee_name']];
    $rows[] = [(string)$h['consignee_addr'], '', '', ' Remarks'];
    $rows[] = [];
    $rows[] = [' Tel.', (string)$h['consignee_phone'], '', (string)$h['remark']];
    $rows[] = [' Notify Party', (string)$h['notify']];
    $rows[] = [];
    $rows[] = [' Port of Loading', '', ' Final Destination'];
    $rows[] = [(string)($b['origin_port'] ?? $h['origin']), '', (string)($b['dest_port'] ?? $h['destination'])];
    $rows[] = [' Carrier', '', ' Sailing on or About'];
    $rows[] = [$company, '', (string)($b['flight_date'] ?? $h['on_board_date'])];
    $rows[] = [' Marks & Numbers of PKGS', '', ' Description of Goods', ' Quantity/Unit', '', ' Unit Price', ' Amount'];
    $rows[] = [];

    $qty = 0.0; $amt = 0.0;
    $items = $h['items'] ?: [[
        'name_en' => (string)$h['description'], 'qty' => $h['actual_qty'] ?: $h['pieces'],
        'unit' => 'PCS', 'amount' => $h['declared_value'],
    ]];
    foreach ($items as $it) {
        $q = (float)($it['qty'] ?? 0);
        $a = (float)($it['amount'] ?? 0);
        $qty += $q; $amt += $a;
        $rows[] = ['', '', (string)($it['name_en'] ?? ''), hawb_x_num($q), 'PCS',
                   $q > 0 ? hawb_x_num(round($a / $q, 2)) : '', hawb_x_num($a)];
    }
    $rows[] = [];
    $rows[] = ['', '', 'TOTAL', hawb_x_num($qty), 'PCS', '', hawb_x_num($amt)];
    $rows[] = [];
    $rows[] = ['', '', '', 'Signed by', $company];

    return xlsx_build('INVOICE', $rows, [26, 14, 30, 12, 8, 12, 14], [0]);
}

/** 파일로 내려보냅니다 (엑셀) */
function hawb_send_xlsx(string $bin, string $fileName): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"; '
        . "filename*=UTF-8''" . rawurlencode($fileName));
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bin;
}
