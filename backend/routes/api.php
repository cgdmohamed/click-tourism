<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountingDocumentController;
use App\Http\Controllers\Api\AccountingScheduleController;
use App\Http\Controllers\Api\AssignmentRuleController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CommissionRuleController;
use App\Http\Controllers\Api\CostCenterController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DemoStatusController;
use App\Http\Controllers\Api\DepreciationRunController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FeatureFlagController;
use App\Http\Controllers\Api\FiscalPeriodController;
use App\Http\Controllers\Api\FixedAssetCategoryController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\FixedAssetReportController;
use App\Http\Controllers\Api\FxRateController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\InvoiceApplicationController;
use App\Http\Controllers\Api\InvoiceCollectionController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LeadConfigController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ManagementReportController;
use App\Http\Controllers\Api\McpTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PipelineReportController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierSettlementController;
use App\Http\Controllers\Api\TourPackageController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('demo/status', [DemoStatusController::class, 'show'])->middleware('throttle:120,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Public branding for the login page: company names and the logo image only.
    Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:branding');
    Route::get('branding/logo', [BrandingController::class, 'logo'])->middleware('throttle:branding');

    Route::get('health', [HealthController::class, 'show']);
    Route::get('ready', [HealthController::class, 'ready']);

    // Webhooks are public but signature-verified inside the controller.
    Route::get('webhooks/meta', [WebhookController::class, 'verifyMeta']);
    Route::post('webhooks/meta', [WebhookController::class, 'receiveMeta'])->middleware('throttle:120,1');
    Route::get('webhooks/whatsapp', [WebhookController::class, 'verifyWhatsApp']);
    Route::post('webhooks/whatsapp', [WebhookController::class, 'receiveWhatsApp'])->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', 'active', 'mcp.readonly', 'throttle:mcp-read'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/me', [AuthController::class, 'update']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('feature-flags', [FeatureFlagController::class, 'show']);

        // MCP gateway tokens (ADR-0016). Listing/revoking need no `mcp.use` so a user who lost it
        // can still revoke; `?all=1` and revoking another user's token need `mcp.admin`.
        Route::post('mcp/tokens', [McpTokenController::class, 'store'])->middleware('permission:mcp.use');
        Route::get('mcp/tokens', [McpTokenController::class, 'index']);
        Route::delete('mcp/tokens/{id}', [McpTokenController::class, 'destroy'])->whereNumber('id');
        // Gateway helper (MCP tokens allowed): the branches the token's user may use.
        Route::get('mcp/context', [McpTokenController::class, 'context']);
        // MCP usage for the Settings screen (ADR-0018): counts and recent connections, no secrets.
        Route::get('mcp/status', [McpTokenController::class, 'status'])->middleware('permission:settings.view');

        // In-product read-only assistant (ADR-0017). Stateless; permission first, then the per-user limiter.
        Route::post('assistant/chat', [AssistantController::class, 'chat'])->middleware(['permission:assistant.use', 'throttle:assistant-chat']);
        // Model discovery for the Settings model picker (ADR-0018). Read-only: saving needs settings.update.
        Route::put('assistant/key', [AssistantController::class, 'storeKey'])->middleware(['permission:settings.update', 'throttle:10,1']);
        Route::delete('assistant/key', [AssistantController::class, 'destroyKey'])->middleware('permission:settings.update');
        Route::get('assistant/models', [AssistantController::class, 'models'])->middleware('permission:settings.view');

        Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create');

        Route::get('settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
        Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.update');
        Route::post('settings/logo', [BrandingController::class, 'upload'])->middleware(['permission:settings.update', 'throttle:30,1']);
        Route::delete('settings/logo', [BrandingController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('decisions', [SettingController::class, 'decisions'])->middleware('permission:settings.view');
        Route::post('decisions/{id}/resolve', [SettingController::class, 'resolveDecision'])->middleware('permission:settings.update');

        Route::get('audit-events', [AuditEventController::class, 'index'])->middleware('permission:audit.view');
        Route::get('audit-events/export', [AuditEventController::class, 'export'])
            ->middleware(['permission:audit.export', 'throttle:report-exports']);

        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::get('currencies/{code}', [CurrencyController::class, 'show'])->middleware('permission:settings.view');
        Route::post('currencies', [CurrencyController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('currencies/{code}', [CurrencyController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('currencies/{code}', [CurrencyController::class, 'destroy'])->middleware('permission:settings.update');
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
        Route::post('expenses/{expense}/reopen', [ExpenseController::class, 'reopen'])->middleware('permission:expenses.create');
        Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->middleware('permission:expenses.create');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware(['permission:expenses.approve', 'idempotent']);
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.approve');
        Route::post('expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->middleware('permission:expenses.approve');

        Route::get('commission-rules', [CommissionRuleController::class, 'index'])->middleware('permission:commissions.view');
        Route::post('commission-rules', [CommissionRuleController::class, 'store'])->middleware('permission:commission_rules.manage');
        Route::get('commission-rules/{commissionRule}', [CommissionRuleController::class, 'show'])->middleware('permission:commissions.view');
        Route::patch('commission-rules/{commissionRule}', [CommissionRuleController::class, 'update'])->middleware('permission:commission_rules.manage');
        Route::delete('commission-rules/{commissionRule}', [CommissionRuleController::class, 'destroy'])->middleware('permission:commission_rules.manage');
        Route::post('commission-rules/{commissionRule}/new-version', [CommissionRuleController::class, 'newVersion'])->middleware('permission:commission_rules.manage');
        Route::post('commission-rules/{commissionRule}/submit', [CommissionRuleController::class, 'submit'])->middleware('permission:commission_rules.manage');
        Route::post('commission-rules/{commissionRule}/approve', [CommissionRuleController::class, 'approve'])->middleware('permission:commission_rules.approve');
        Route::post('commission-rules/{commissionRule}/reject', [CommissionRuleController::class, 'reject'])->middleware('permission:commission_rules.approve');
        Route::post('commission-rules/{commissionRule}/retire', [CommissionRuleController::class, 'retire'])->middleware('permission:commission_rules.manage');

        Route::get('commissions', [CommissionController::class, 'index'])->middleware('permission:commissions.view');
        Route::get('commissions/beneficiaries', [CommissionController::class, 'beneficiaries'])->middleware('permission:commissions.view');
        Route::get('commissions/statement', [CommissionController::class, 'statement'])->middleware('permission:commissions.view');
        Route::get('commissions/{commission}', [CommissionController::class, 'show'])->middleware('permission:commissions.view');
        Route::post('commissions/{commission}/override', [CommissionController::class, 'override'])->middleware('permission:commissions.override');
        Route::post('commissions/{commission}/approve', [CommissionController::class, 'approve'])->middleware(['permission:commissions.approve', 'idempotent']);
        Route::post('commissions/{commission}/reject', [CommissionController::class, 'reject'])->middleware('permission:commissions.approve');
        Route::post('commissions/{commission}/post', [CommissionController::class, 'post'])->middleware(['permission:commissions.post', 'idempotent']);
        Route::post('commissions/{commission}/pay', [CommissionController::class, 'pay'])->middleware(['permission:commissions.pay', 'idempotent']);
        Route::post('commissions/{commission}/reverse', [CommissionController::class, 'reverse'])->middleware('permission:commissions.reverse');

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
        Route::get('reports/cost-center-pl/export', [CostCenterController::class, 'exportProfitAndLoss'])->middleware(['permission:cost_centers.view', 'permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/cost-center-unallocated', [CostCenterController::class, 'unallocated'])->middleware('permission:cost_centers.view');
        Route::get('reports/cost-center-unallocated/export', [CostCenterController::class, 'exportUnallocated'])->middleware(['permission:cost_centers.view', 'permission:reports.export', 'throttle:report-exports']);

        Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->middleware('permission:periods.view');
        Route::get('fiscal-periods/{period}/checklist', [FiscalPeriodController::class, 'checklist'])->middleware('permission:periods.view');
        Route::post('fiscal-periods', [FiscalPeriodController::class, 'store'])->middleware('permission:periods.manage');
        Route::get('fiscal-years/suggested', [FiscalPeriodController::class, 'suggested'])->middleware('permission:periods.view');
        Route::get('fiscal-years/{fiscalYear}/close-checks', [FiscalPeriodController::class, 'closeChecks'])->middleware('permission:periods.view');
        Route::get('fiscal-years/{fiscalYear}/close-preview', [FiscalPeriodController::class, 'closePreview'])->middleware('permission:periods.view');
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
        Route::post('accounting-documents/{document}/reopen', [AccountingDocumentController::class, 'reopen'])->middleware('permission:documents.create');
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
        Route::post('reconciliations/{reconciliation}/reopen', [BankReconciliationController::class, 'reopen'])->middleware(['permission:reconciliation.approve', 'idempotent']);

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
        Route::post('fixed-asset-transfers/{transfer}/reverse', [FixedAssetController::class, 'reverseTransfer'])->middleware('permission:assets.approve');
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
        Route::post('depreciation-runs/{run}/reverse', [DepreciationRunController::class, 'reverse'])->middleware('permission:assets.approve');
        Route::delete('depreciation-runs/{run}', [DepreciationRunController::class, 'destroy'])->middleware('permission:assets.manage');

        Route::get('reports/fixed-assets/roll-forward', [FixedAssetReportController::class, 'rollForward'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/carrying-values', [FixedAssetReportController::class, 'carryingValues'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/movements', [FixedAssetReportController::class, 'movements'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/reconciliation', [FixedAssetReportController::class, 'reconciliation'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/schedule', [FixedAssetReportController::class, 'schedule'])->middleware('permission:assets.view');
        Route::get('reports/fixed-assets/{report}/export', [FixedAssetReportController::class, 'export'])
            ->whereIn('report', ['roll-forward', 'carrying-values', 'movements', 'reconciliation', 'schedule'])
            ->middleware(['permission:assets.view', 'permission:reports.export', 'throttle:report-exports']);

        Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounts.view');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.create');
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounts.update');
        Route::get('accounts/next-code', [AccountController::class, 'nextCode'])->middleware('permission:accounts.create');
        Route::get('accounts/export', [AccountController::class, 'export'])->middleware('permission:accounts.view');
        Route::post('accounts/import', [AccountController::class, 'import'])->middleware('permission:accounts.update');

        // Generic import framework (work order 13). Authorisation is per entity
        // inside the controller: the same permission that creates/updates the
        // record by hand, evaluated for the chosen duplicate policy.
        Route::get('imports/templates', [ImportController::class, 'templates']);
        Route::get('imports/templates/{entity}', [ImportController::class, 'template']);
        Route::get('imports', [ImportController::class, 'index']);
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:30,1');
        Route::get('imports/{job}', [ImportController::class, 'show']);
        Route::post('imports/{job}/confirm', [ImportController::class, 'confirm']);
        Route::get('imports/{job}/errors', [ImportController::class, 'errors']);
        Route::delete('imports/{job}', [ImportController::class, 'destroy']);

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

        Route::get('tour-packages', [TourPackageController::class, 'index'])->middleware('permission:bookings.view');
        Route::post('tour-packages', [TourPackageController::class, 'store'])->middleware('permission:bookings.create');
        Route::get('tour-packages/{package}', [TourPackageController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('tour-packages/{package}/departures', [TourPackageController::class, 'departure'])->middleware('permission:bookings.create');
        Route::post('tour-departures/{departure}/close', [TourPackageController::class, 'close'])->middleware('permission:bookings.update');
        Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
        Route::post('bookings', [BookingController::class, 'store'])->middleware('permission:bookings.create');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('bookings/{booking}/invoice', [BookingController::class, 'invoice'])->middleware(['permission:invoices.create', 'idempotent']);
        Route::patch('bookings/{booking}', [BookingController::class, 'update'])->middleware('permission:bookings.update');
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
        Route::post('invoices/{invoice}/collections', [InvoiceCollectionController::class, 'store'])->middleware(['permission:payments.create', 'idempotent']);
        Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->middleware(['permission:invoices.approve', 'idempotent']);
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware(['permission:invoices.approve', 'idempotent']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoices.create');
        Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->middleware(['permission:invoices.approve', 'idempotent']);
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware(['permission:invoices.view', 'throttle:report-exports']);

        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:reports.view');
        Route::get('reports/trial-balance/export', [ReportController::class, 'exportTrialBalance'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('permission:reports.view');
        Route::get('reports/general-ledger/export', [ReportController::class, 'exportGeneralLedger'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->middleware('permission:reports.view');
        Route::get('reports/income-statement/export', [ReportController::class, 'exportIncomeStatement'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:reports.view');
        Route::get('reports/balance-sheet/export', [ReportController::class, 'exportBalanceSheet'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/aging', [ReportController::class, 'aging'])->middleware('permission:reports.view');
        Route::get('reports/aging/export', [ReportController::class, 'exportAging'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/aging-reconciliation', [ReportController::class, 'agingReconciliation'])->middleware('permission:reports.view');
        Route::get('reports/aging-reconciliation/export', [ReportController::class, 'exportAgingReconciliation'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/party-statement', [ReportController::class, 'partyStatement'])->middleware('permission:reports.view');
        Route::get('reports/party-statement/export', [ReportController::class, 'exportPartyStatement'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/profitability', [ManagementReportController::class, 'profitability'])->middleware('permission:reports.view');
        Route::get('reports/profitability/export', [ManagementReportController::class, 'exportProfitability'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/sales', [ManagementReportController::class, 'sales'])->middleware('permission:reports.view');
        Route::get('reports/sales/export', [ManagementReportController::class, 'exportSales'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/commissions', [ManagementReportController::class, 'commissions'])->middleware('permission:reports.view');
        Route::get('reports/commissions/export', [ManagementReportController::class, 'exportCommissions'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('reports/tax', [ManagementReportController::class, 'tax'])->middleware('permission:reports.view');
        Route::get('reports/tax/export', [ManagementReportController::class, 'exportTax'])->middleware(['permission:reports.export', 'throttle:report-exports']);
        Route::get('dashboard/summary', [ReportController::class, 'dashboard'])->middleware('permission:reports.view');

        Route::get('search', [SearchController::class, 'index'])->middleware('throttle:60,1');

        Route::get('leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view');
        Route::post('leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.update');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:leads.convert');
        Route::get('reports/pipeline', PipelineReportController::class)->middleware('permission:leads.view');

        // Configurable lead sources and pipeline stages. Codes are the stable
        // key, so updates and deletes address the record by code.
        Route::get('lead-sources', [LeadConfigController::class, 'sources'])->middleware('permission:leads.view');
        Route::post('lead-sources', [LeadConfigController::class, 'storeSource'])->middleware('permission:leads.manage_rules');
        Route::patch('lead-sources/{source:code}', [LeadConfigController::class, 'updateSource'])->middleware('permission:leads.manage_rules');
        Route::delete('lead-sources/{source:code}', [LeadConfigController::class, 'destroySource'])->middleware('permission:leads.manage_rules');
        Route::get('lead-stages', [LeadConfigController::class, 'stages'])->middleware('permission:leads.view');
        Route::post('lead-stages', [LeadConfigController::class, 'storeStage'])->middleware('permission:leads.manage_rules');
        Route::patch('lead-stages/{stage:code}', [LeadConfigController::class, 'updateStage'])->middleware('permission:leads.manage_rules');
        Route::delete('lead-stages/{stage:code}', [LeadConfigController::class, 'destroyStage'])->middleware('permission:leads.manage_rules');

        // In-app notifications for the signed-in user (GAP-CORE-01).
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

        // Optional Meta / WhatsApp integrations (disabled by default; secrets are env-only).
        Route::get('integrations/status', [IntegrationController::class, 'status'])->middleware('permission:settings.view');
        Route::put('integrations/settings', [IntegrationController::class, 'updateSettings'])->middleware('permission:settings.update');
        Route::get('integrations/whatsapp/messages', [IntegrationController::class, 'messages'])->middleware('permission:leads.view');
        Route::post('integrations/whatsapp/messages', [IntegrationController::class, 'send'])->middleware('permission:leads.update');
        Route::post('integrations/whatsapp/consents', [IntegrationController::class, 'recordConsent'])->middleware('permission:leads.update');

        Route::get('assignment-rules', [AssignmentRuleController::class, 'index'])->middleware('permission:leads.view');
        Route::post('assignment-rules', [AssignmentRuleController::class, 'store'])->middleware('permission:leads.update');
        Route::patch('assignment-rules/{rule}', [AssignmentRuleController::class, 'update'])->middleware('permission:leads.update');
        Route::delete('assignment-rules/{rule}', [AssignmentRuleController::class, 'destroy'])->middleware('permission:leads.update');

        // IAM (work order 01): global user management, role directory and
        // custom roles. The lead-assignment picker lives at /users/assignable
        // so assignment rules keep their leads.update requirement.
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::get('users/assignable', [UserController::class, 'assignable'])->middleware('permission:leads.update');
        Route::post('users', [UserController::class, 'store'])->middleware(['permission:users.manage', 'idempotent']);
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware(['permission:users.manage', 'idempotent']);
        Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware(['permission:users.manage', 'idempotent']);

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware(['permission:roles.manage', 'idempotent']);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');
    });
});
