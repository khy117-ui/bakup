<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/access.php';

$page = query('p', 'dashboard');

// 허용된 화면만 라우팅합니다. 파일명을 URL 에서 받아 include 하지 않습니다
$routes = [
    'login'        => 'login.php',
    'dashboard'    => 'dashboard.php',
    'companies'    => 'companies.php',
    'company_form' => 'company_form.php',
    'shipments'    => 'shipments.php',
    'shipment_form'=> 'shipment_form.php',
    'sales_stats'  => 'sales_stats.php',
    'purchases'    => 'purchases.php',
    'profit'       => 'profit.php',
    'billing'      => 'billing.php',
    'invoice_view' => 'invoice_view.php',
    'invoice_print'=> 'invoice_print.php',
    'tax_invoices' => 'tax_invoices.php',
    'carriers'     => 'carriers.php',
    'company_contacts' => 'company_contacts.php',
    'rate_table'   => 'rate_table.php',
    'company_terms'=> 'company_terms.php',
    'rate_calculator' => 'rate_calculator.php',
    'rate_quote'   => 'rate_quote.php',
    'quotations'   => 'quotations.php',
    'quotation_form' => 'quotation_form.php',
    'quotation_print'=> 'quotation_print.php',
    'statements'   => 'statements.php',
    'statement_print'=> 'statement_print.php',
    'receipts'     => 'receipts.php',
    'receipt_print'=> 'receipt_print.php',
    'awb_list'     => 'awb_list.php',
    'awb_label'    => 'awb_label.php',
    'hawb_list'    => 'hawb_list.php',
    'hawb_form'    => 'hawb_form.php',
    'hawb_print'   => 'hawb_print.php',
    'hawb_batches' => 'hawb_batches.php',
    'hawb_export'  => 'hawb_export.php',
    'tracking'     => 'tracking.php',
    'documents'    => 'documents.php',
    'file_download'=> 'file_download.php',
    'business_entity' => 'business_entity.php',
    'permissions'  => 'permissions.php',
    'activity_log' => 'activity_log.php',
    'settings'     => 'settings.php',
    'cash_dashboard' => 'cash_dashboard.php',
    'cash_in'      => 'cash_in.php',
    'cash_out'     => 'cash_out.php',
    'cash_list'    => 'cash_list.php',
    'receivables'  => 'receivables.php',
    'ledger'       => 'ledger.php',
    'opening_balances' => 'opening_balances.php',
    'bank_import'  => 'bank_import.php',
    'accounts'     => 'accounts.php',
    'cash_stats'   => 'cash_stats.php',
    'boards'       => 'boards.php',
    'storage_settings' => 'storage_settings.php',
    'search'       => 'search.php',
    'backup'       => 'backup.php',
    'migration'    => 'migration.php',
    'migration_import' => 'migration_import.php',
    'delete_requests'  => 'delete_requests.php',
    'my_account'       => 'my_account.php',
    'destinations'     => 'destinations.php',
    'track_tick'       => 'track_tick.php',
    'web_pickups'      => 'web_pickups.php',
    'live_feed'        => 'live_feed.php',
];

if ($page === 'logout') {
    logout();
    redirect('?p=login');
}

if (!isset($routes[$page])) {
    http_response_code(404);
    $page = 'dashboard';
}

// 계정 · 삭제승인용 표가 없으면 붙이고, 뷰 collation 이 어긋나 있으면 바로잡습니다 (세션당 한 번 확인)
schema_upgrade_accounts();
schema_fix_view_collation();
schema_upgrade_docs();
schema_upgrade_dest();
schema_upgrade_epost();
schema_upgrade_filestore();
schema_upgrade_notify();

if ($page !== 'login') {
    $ADMIN = require_login();

    // 관리자가 만들어 준 임시 비밀번호는 첫 로그인 때 바꿔야 합니다
    if ((int)($ADMIN['must_change_pw'] ?? 0) === 1 && $page !== 'my_account') {
        flash('처음 받은 임시 비밀번호입니다. 새 비밀번호로 바꿔 주세요.');
        redirect('?p=my_account');
    }

    // 화면 권한 — 보기는 보기 권한, 저장 · 수정 · 삭제(POST)는 입력 권한
    if (!route_can_view($page)) {
        deny_page('이 화면을 볼');
    }
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($isPost && !route_can_edit($page)) {
        deny_page('이 화면에서 입력 · 수정할');
    }

    // 삭제 · 취소 — 바로 실행할 권한이 없으면 삭제 요청으로 남기고 관리자 승인을 기다립니다
    if ($isPost && ($spec = delete_action($page, (string)($_POST['act'] ?? ''))) !== null
        && !can('sys.delete.direct')) {
        csrf_check();
        delete_request_create($page, (string)$_POST['act'], $spec);
    }
}

require APP_DIR . '/pages/' . $routes[$page];
