<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountingDocumentController;
use App\Http\Controllers\Api\AccountingScheduleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CostCenterController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DemoStatusController;
use App\Http\Controllers\Api\DepreciationRunController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FiscalPeriodController;
use App\Http\Controllers\Api\FixedAssetCategoryController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\FixedAssetReportController;
use App\Http\Controllers\Api\FxRateController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvoiceApplicationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierSettlementController;
use App\Http\Controllers\Api\TaxProfileController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('demo/status', [DemoStatusController::class, 'show'])->middleware('throttle:120,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('health', [HealthController::class, 'show']);
    Route::get('ready', [HealthController::class, 'ready']);

    // Webhooks are public but signature-verified inside the controller.
    Route::get('webhooks/meta', [WebhookController::class, 'verifyMeta']);
    Route::post('webhooks/meta', [WebhookController::class, 'receiveMeta'])->middleware('throttle:120,1');
    Route::get('webhooks/whatsapp', [WebhookController::class, 'verifyWhatsApp']);
    Route::post('webhooks/whatsapp', [WebhookController::class, 'receiveWhatsApp'])->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create');

        Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
        Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.update');
        Route::get('decisions', [SettingController::class, 'decisions'])->middleware('permission:settings.view');

        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::get('fx-rates', [FxRateController::class, 'index'])->middleware('permission:settings.view');
        Route::post('fx-rates', [FxRateController::class, 'store'])->middleware('permission:settings.update');

        Route::get('cash-accounts', [CashAccountController::class, 'index'])->middleware('permission:accounts.view');
        Route::post('cash-accounts', [CashAccountController::class, 'store'])->middleware('permission:cash_accounts.manage');
        Route::patch('cash-accounts/{cashAccount}', [CashAccountController::class, 'update'])->middleware('permission:cash_accounts.manage');

        Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->middleware('permission:opening_balances.view');
        Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->middleware('permission:opening_balances.manage');
        Route::get('opening-balances/{batch}', [OpeningBalanceController::class, 'show'])->middleware('permission:opening_balances.view');
        Route::delete('opening-balances/{batch}', [OpeningBalanceController::class, 'destroy'])->middleware('permission:opening_balances.manage');
        Route::get('opening-balances/{batch}/reconciliation', [OpeningBalanceController::class, 'reconciliation'])->middleware('permission:opening_balances.view');
        Route::post('opening-balances/{batch}/post', [OpeningBalanceController::class, 'post'])->middleware(['permission:opening_balances.post', 'idempotent']);
        Route::post('opening-balances/{batch}/reverse', [OpeningBalanceController::class, 'reverse'])->middleware('permission:opening_balances.reverse');

        Route::get('expense-categories', [ExpenseController::class, 'categories'])->middleware('permission:expenses.view');
        Route::post('expense-categories', [ExpenseController::class, 'storeCategory'])->middleware('permission:expense_categories.manage');
        Route::patch('expense-categories/{category}', [ExpenseController::class, 'updateCategory'])->middleware('permission:expense_categories.manage');

        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware(['permission:expenses.create', 'idempotent']);
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view');
        Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.create');
        Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware('permission:expenses.create');
        Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->middleware('permission:expenses.create');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware(['permission:expenses.approve', 'idempotent']);
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.approve');
        Route::post('expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->middleware('permission:expenses.approve');

        Route::get('transfers', [TransferController::class, 'index'])->middleware('permission:transfers.view');
        Route::post('transfers', [TransferController::class, 'store'])->middleware(['permission:transfers.create', 'idempotent']);
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->middleware('permission:transfers.view');
        Route::post('transfers/{transfer}/reverse', [TransferController::class, 'reverse'])->middleware('permission:transfers.reverse');

        Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.manage');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employees.view');
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate'])->middleware('permission:employees.manage');
        Route::get('employees/{employee}/statement', [EmployeeController::class, 'statement'])->middleware('permission:employees.view');

        Route::post('employees/{employee}/advances', [EmployeeController::class, 'requestAdvance'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/advances/{advance}/approve', [EmployeeController::class, 'approveAdvance'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/advances/{advance}/disburse', [EmployeeController::class, 'disburseAdvance'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/advances/{advance}/settle', [EmployeeController::class, 'settleAdvance'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/advances/{advance}/cancel', [EmployeeController::class, 'cancelAdvance'])->middleware('permission:employees.manage');

        Route::post('employees/{employee}/custody', [EmployeeController::class, 'issueCustody'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/custody/{custody}/clear', [EmployeeController::class, 'clearCustody'])->middleware('permission:employees.manage');
        Route::post('employees/{employee}/custody/{custody}/return', [EmployeeController::class, 'returnCustody'])->middleware('permission:employees.manage');

        Route::get('cost-centers', [CostCenterController::class, 'index'])->middleware('permission:cost_centers.view');
        Route::post('cost-centers', [CostCenterController::class, 'store'])->middleware('permission:cost_centers.manage');
        Route::get('cost-centers/{center}', [CostCenterController::class, 'show'])->middleware('permission:cost_centers.view');
        Route::patch('cost-centers/{center}', [CostCenterController::class, 'update'])->middleware('permission:cost_centers.manage');
        Route::post('cost-centers/{center}/archive', [CostCenterController::class, 'archive'])->middleware('permission:cost_centers.manage');

        Route::get('journal-lines/{line}/cost-allocations', [CostCenterController::class, 'allocations'])->middleware('permission:cost_centers.view');
        Route::post('journal-lines/{line}/cost-allocations', [CostCenterController::class, 'allocate'])->middleware('permission:cost_centers.manage');

        Route::get('reports/cost-center-pl', [CostCenterController::class, 'profitAndLoss'])->middleware('permission:cost_centers.view');
        Route::get('reports/cost-center-unallocated', [CostCenterController::class, 'unallocated'])->middleware('permission:cost_centers.view');

        Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->middleware('permission:periods.view');
        Route::get('fiscal-periods/{period}/checklist', [FiscalPeriodController::class, 'checklist'])->middleware('permission:periods.view');
        Route::post('fiscal-periods', [FiscalPeriodController::class, 'store'])->middleware('permission:periods.manage');
        Route::get('fiscal-years/{fiscalYear}/close-checks', [FiscalPeriodController::class, 'closeChecks'])->middleware('permission:periods.view');
        Route::post('fiscal-years/{fiscalYear}/close', [FiscalPeriodController::class, 'close'])->middleware('permission:periods.close');
        Route::post('fiscal-years/{fiscalYear}/reopen', [FiscalPeriodController::class, 'reopen'])->middleware('permission:periods.reopen');
        Route::patch('fiscal-periods/{period}/status', [FiscalPeriodController::class, 'updateStatus'])->middleware('permission:periods.manage');

        Route::get('journal-entries', [JournalEntryController::class, 'index'])->middleware('permission:journal.view');
        Route::post('journal-entries', [JournalEntryController::class, 'store'])->middleware('permission:journal.create');
        Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->middleware('permission:journal.view');
        Route::delete('journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy'])->middleware('permission:journal.create|journal.delete_drafts');
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('permission:journal.create');
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:journal.reverse');

        Route::get('accounting-documents', [AccountingDocumentController::class, 'index'])->middleware('permission:documents.view');
        Route::post('accounting-documents', [AccountingDocumentController::class, 'store'])->middleware(['permission:documents.create', 'idempotent']);
        Route::get('accounting-documents/{document}', [AccountingDocumentController::class, 'show'])->middleware('permission:documents.view');
        Route::patch('accounting-documents/{document}', [AccountingDocumentController::class, 'update'])->middleware('permission:documents.create');
        Route::post('accounting-documents/{document}/submit', [AccountingDocumentController::class, 'submit'])->middleware('permission:documents.create');
        Route::post('accounting-documents/{document}/approve', [AccountingDocumentController::class, 'approve'])->middleware(['permission:documents.approve', 'idempotent']);
        Route::post('accounting-documents/{document}/post', [AccountingDocumentController::class, 'post'])->middleware(['permission:documents.approve', 'idempotent']);
        Route::post('accounting-documents/{document}/cancel', [AccountingDocumentController::class, 'cancel'])->middleware('permission:documents.create');
        Route::post('accounting-documents/{document}/reject', [AccountingDocumentController::class, 'reject'])->middleware('permission:documents.approve');
        Route::post('accounting-documents/{document}/correct', [AccountingDocumentController::class, 'correct'])->middleware(['permission:documents.approve', 'idempotent']);

        Route::get('bank-statements', [BankReconciliationController::class, 'statements'])->middleware('permission:reconciliation.view');
        Route::post('bank-statements/import', [BankReconciliationController::class, 'import'])->middleware('permission:reconciliation.manage');
        Route::get('bank-statements/{statement}', [BankReconciliationController::class, 'showStatement'])->middleware('permission:reconciliation.view');

        Route::get('reconciliations', [BankReconciliationController::class, 'index'])->middleware('permission:reconciliation.view');
        Route::post('reconciliations', [BankReconciliationController::class, 'store'])->middleware('permission:reconciliation.manage');
        Route::get('reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])->middleware('permission:reconciliation.view');
        Route::post('reconciliations/{reconciliation}/auto-match', [BankReconciliationController::class, 'autoMatch'])->middleware('permission:reconciliation.manage');
        Route::post('reconciliations/{reconciliation}/matches', [BankReconciliationController::class, 'match'])->middleware('permission:reconciliation.manage');
        Route::delete('reconciliations/{reconciliation}/matches/{group}', [BankReconciliationController::class, 'unmatch'])->middleware('permission:reconciliation.manage');
        Route::post('reconciliations/{reconciliation}/adjustment-voucher', [BankReconciliationController::class, 'adjustmentVoucher'])->middleware('permission:reconciliation.manage');
        Route::post('reconciliations/{reconciliation}/approve', [BankReconciliationController::class, 'approve'])->middleware(['permission:reconciliation.approve', 'idempotent']);

        Route::get('accounting-schedules', [AccountingScheduleController::class, 'index'])->middleware('permission:schedules.view');
        Route::get('accounting-schedules/upcoming', [AccountingScheduleController::class, 'upcoming'])->middleware('permission:schedules.view');
        Route::get('accounting-schedules/exceptions', [AccountingScheduleController::class, 'exceptions'])->middleware('permission:schedules.view');
        Route::post('accounting-schedules', [AccountingScheduleController::class, 'store'])->middleware('permission:schedules.manage');
        Route::get('accounting-schedules/{schedule}', [AccountingScheduleController::class, 'show'])->middleware('permission:schedules.view');
        Route::patch('accounting-schedules/{schedule}', [AccountingScheduleController::class, 'update'])->middleware('permission:schedules.manage');
        Route::post('accounting-schedules/{schedule}/activate', [AccountingScheduleController::class, 'activate'])->middleware('permission:schedules.approve');
        Route::post('accounting-schedules/{schedule}/cancel', [AccountingScheduleController::class, 'cancel'])->middleware('permission:schedules.approve');
        Route::post('accounting-schedules/{schedule}/events/{event}/post', [AccountingScheduleController::class, 'postEvent'])->middleware('permission:schedules.manage');

        Route::get('fixed-asset-categories', [FixedAssetCategoryController::class, 'index'])->middleware('permission:assets.view');
        Route::post('fixed-asset-categories', [FixedAssetCategoryController::class, 'store'])->middleware('permission:assets.manage');
        Route::get('fixed-asset-categories/{category}', [FixedAssetCategoryController::class, 'show'])->middleware('permission:assets.view');
        Route::patch('fixed-asset-categories/{category}', [FixedAssetCategoryController::class, 'update'])->middleware('permission:assets.manage');

        Route::get('fixed-assets', [FixedAssetController::class, 'index'])->middleware('permission:assets.view');
        Route::post('fixed-assets', [FixedAssetController::class, 'store'])->middleware('permission:assets.manage');
        Route::get('fixed-assets/{asset}', [FixedAssetController::class, 'show'])->middleware('permission:assets.view');
        Route::patch('fixed-assets/{asset}', [FixedAssetController::class, 'update'])->middleware('permission:assets.manage');
        Route::post('fixed-assets/{asset}/capitalize', [FixedAssetController::class, 'capitalize'])->middleware('permission:assets.approve');
        Route::post('fixed-assets/{asset}/transfer', [FixedAssetController::class, 'transfer'])->middleware('permission:assets.manage');
        Route::post('fixed-assets/{asset}/dispose', [FixedAssetController::class, 'dispose'])->middleware('permission:assets.approve');
        Route::post('fixed-assets/{asset}/impairments', [FixedAssetController::class, 'impairment'])->middleware('permission:assets.manage');
        Route::post('fixed-assets/{asset}/impairments/{document}/apply', [FixedAssetController::class, 'applyImpairment'])->middleware('permission:assets.approve');
        Route::get('fixed-assets/{asset}/schedule', [FixedAssetController::class, 'schedule'])->middleware('permission:assets.view');
        Route::get('fixed-assets/{asset}/audit-trail', [FixedAssetController::class, 'auditTrail'])->middleware('permission:assets.view');

        Route::get('depreciation-runs', [DepreciationRunController::class, 'index'])->middleware('permission:assets.view');
        Route::get('depreciation-runs/preview', [DepreciationRunController::class, 'preview'])->middleware('permission:assets.view');
        Route::post('depreciation-runs', [DepreciationRunController::class, 'store'])->middleware('permission:assets.manage');
        Route::get('depreciation-runs/{run}', [DepreciationRunController::class, 'show'])->middleware('permission:assets.view');
        Route::post('depreciation-runs/{run}/post', [DepreciationRunController::class, 'post'])->middleware('permission:assets.approve');
        Route::delete('depreciation-runs/{run}', [DepreciationRunController::class, 'destroy'])->middleware('permission:assets.manage');

        Route::get('reports/fixed-assets/roll-forward', [FixedAssetReportController::class, 'rollForward'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/carrying-values', [FixedAssetReportController::class, 'carryingValues'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/movements', [FixedAssetReportController::class, 'movements'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/reconciliation', [FixedAssetReportController::class, 'reconciliation'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/schedule', [FixedAssetReportController::class, 'schedule'])->middleware('permission:assets.view');

        Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounts.view');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.create');
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounts.update');
        Route::get('accounts/export', [AccountController::class, 'export'])->middleware('permission:accounts.view');
        Route::post('accounts/import', [AccountController::class, 'import'])->middleware('permission:accounts.update');

        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.create');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:suppliers.view');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.update');

        Route::get('supplier-obligations', [SupplierSettlementController::class, 'obligations'])->middleware('permission:supplier_obligations.view');
        Route::post('supplier-obligations', [SupplierSettlementController::class, 'storeObligation'])->middleware('permission:supplier_obligations.create');
        Route::post('supplier-obligations/{supplierObligation}/recognize', [SupplierSettlementController::class, 'recognizeObligation'])->middleware('permission:supplier_obligations.create');
        Route::get('supplier-obligations/{supplierObligation}', [SupplierSettlementController::class, 'showObligation'])->middleware('permission:supplier_obligations.view');

        Route::get('supplier-payments', [SupplierSettlementController::class, 'payments'])->middleware('permission:supplier_payments.view');
        Route::post('supplier-payments', [SupplierSettlementController::class, 'storePayment'])->middleware(['permission:supplier_payments.create', 'idempotent']);
        Route::get('supplier-payments/{supplierPayment}', [SupplierSettlementController::class, 'showPayment'])->middleware('permission:supplier_payments.view');
        Route::post('supplier-payments/{supplierPayment}/apply', [SupplierSettlementController::class, 'apply'])->middleware(['permission:supplier_payments.create', 'idempotent']);
        Route::post('supplier-payments/{supplierPayment}/invoice-applications', [SupplierSettlementController::class, 'applyToInvoice'])->middleware(['permission:supplier_payments.create', 'idempotent']);
        Route::post('supplier-payments/{supplierPayment}/cancel', [SupplierSettlementController::class, 'cancel'])->middleware(['permission:supplier_payments.reverse']);
        Route::post('supplier-payments/{supplierPayment}/unapply', [SupplierSettlementController::class, 'unapply'])->middleware(['permission:supplier_payments.create']);

        Route::get('tax-profiles', [TaxProfileController::class, 'index'])->middleware('permission:bookings.view');
        Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
        Route::post('bookings', [BookingController::class, 'store'])->middleware('permission:bookings.create');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('bookings/{booking}/transition', [BookingController::class, 'transition'])->middleware('permission:bookings.transition');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->middleware('permission:bookings.cancel');
        Route::post('bookings/{booking}/margin-approval', [BookingController::class, 'requestMarginApproval'])->middleware('permission:bookings.transition');
        Route::post('bookings/{booking}/margin-approval/approve', [BookingController::class, 'approveMargin'])->middleware('permission:bookings.approve_margin');

        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::post('payments', [PaymentController::class, 'store'])->middleware(['permission:payments.create', 'idempotent']);
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate'])->middleware(['permission:payments.create']);
        Route::delete('invoice-applications/{application}', [InvoiceApplicationController::class, 'destroy'])->middleware('permission:payments.create');
        Route::post('payments/{payment}/invoice-applications', [PaymentController::class, 'applyToInvoice'])->middleware(['permission:payments.create', 'idempotent']);
        Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->middleware(['permission:payments.reverse']);
        Route::post('payments/{payment}/unallocate', [PaymentController::class, 'unallocate'])->middleware(['permission:payments.create']);

        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('permission:payments.view');
        Route::post('payment-methods', [PaymentMethodController::class, 'store'])->middleware(['permission:payments.create']);
        Route::patch('payment-methods/{method}', [PaymentMethodController::class, 'update'])->middleware(['permission:payments.create']);

        Route::get('refunds', [RefundController::class, 'index'])->middleware('permission:payments.view');
        Route::post('refunds', [RefundController::class, 'store'])->middleware(['permission:refunds.create', 'idempotent']);
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('permission:refunds.approve');

        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
        Route::post('invoices', [InvoiceController::class, 'store'])->middleware(['permission:invoices.create', 'idempotent']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
        Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->middleware(['permission:invoices.approve', 'idempotent']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware(['permission:invoices.approve', 'idempotent']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->middleware(['permission:invoices.approve', 'idempotent']);

        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:reports.view');
        Route::get('reports/trial-balance/export', [ReportController::class, 'exportTrialBalance'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('permission:reports.view');
        Route::get('reports/general-ledger/export', [ReportController::class, 'exportGeneralLedger'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->middleware('permission:reports.view');
        Route::get('reports/income-statement/export', [ReportController::class, 'exportIncomeStatement'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:reports.view');
        Route::get('reports/balance-sheet/export', [ReportController::class, 'exportBalanceSheet'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/aging', [ReportController::class, 'aging'])->middleware('permission:reports.view');
        Route::get('reports/aging/export', [ReportController::class, 'exportAging'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/aging-reconciliation', [ReportController::class, 'agingReconciliation'])->middleware('permission:reports.view');
        Route::get('reports/aging-reconciliation/export', [ReportController::class, 'exportAgingReconciliation'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('reports/party-statement', [ReportController::class, 'partyStatement'])->middleware('permission:reports.view');
        Route::get('reports/party-statement/export', [ReportController::class, 'exportPartyStatement'])->middleware(['permission:reports.export', 'throttle:20,1']);
        Route::get('dashboard/summary', [ReportController::class, 'dashboard'])->middleware('permission:reports.view');

        Route::get('search', [SearchController::class, 'index'])->middleware('throttle:60,1');

        Route::get('leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
        Route::post('leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.update');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:leads.convert');

        Route::get('assignment-rules', [AssignmentRuleController::class, 'index'])->middleware('permission:leads.view');
        Route::post('assignment-rules', [AssignmentRuleController::class, 'store'])->middleware('permission:leads.update');
        Route::patch('assignment-rules/{rule}', [AssignmentRuleController::class, 'update'])->middleware('permission:leads.update');
        Route::delete('assignment-rules/{rule}', [AssignmentRuleController::class, 'destroy'])->middleware('permission:leads.update');
    });
});
