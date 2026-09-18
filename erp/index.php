<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/auth.php';

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
    'quotations'   => 'quotations.php',
    'quotation_form' => 'quotation_form.php',
    'quotation_print'=> 'quotation_print.php',
    'statements'   => 'statements.php',
    'statement_print'=> 'statement_print.php',
    'awb_list'     => 'awb_list.php',
    'awb_label'    => 'awb_label.php',
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
];

if ($page === 'logout') {
    logout();
    redirect('?p=login');
}

if (!isset($routes[$page])) {
    http_response_code(404);
    $page = 'dashboard';
}

if ($page !== 'login') {
    $ADMIN = require_login();
}

require APP_DIR . '/pages/' . $routes[$page];
