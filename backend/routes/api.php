<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\FiscalPeriodController;
use App\Http\Controllers\Api\FxRateController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierSettlementController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::get('health', [HealthController::class, 'show']);

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

        Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounts.view');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.create');
        Route::get('cash-accounts', [PaymentController::class, 'cashAccounts'])->middleware('permission:accounts.view');

        Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->middleware('permission:periods.view');
        Route::post('fiscal-periods', [FiscalPeriodController::class, 'store'])->middleware('permission:periods.manage');
        Route::patch('fiscal-periods/{period}/status', [FiscalPeriodController::class, 'updateStatus'])->middleware('permission:periods.manage');

        Route::get('journal-entries', [JournalEntryController::class, 'index'])->middleware('permission:journal.view');
        Route::post('journal-entries', [JournalEntryController::class, 'store'])->middleware('permission:journal.create');
        Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->middleware('permission:journal.view');
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('permission:journal.create');
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:journal.reverse');

        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.create');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.update');

        Route::get('supplier-obligations', [SupplierSettlementController::class, 'obligations'])->middleware('permission:supplier_obligations.view');
        Route::post('supplier-obligations', [SupplierSettlementController::class, 'storeObligation'])->middleware('permission:supplier_obligations.create');
        Route::get('supplier-obligations/{supplierObligation}', [SupplierSettlementController::class, 'showObligation'])->middleware('permission:supplier_obligations.view');

        Route::get('supplier-payments', [SupplierSettlementController::class, 'payments'])->middleware('permission:supplier_payments.view');
        Route::post('supplier-payments', [SupplierSettlementController::class, 'storePayment'])->middleware(['permission:supplier_payments.create', 'idempotent']);
        Route::get('supplier-payments/{supplierPayment}', [SupplierSettlementController::class, 'showPayment'])->middleware('permission:supplier_payments.view');
        Route::post('supplier-payments/{supplierPayment}/apply', [SupplierSettlementController::class, 'apply'])->middleware(['permission:supplier_payments.create', 'idempotent']);

        Route::get('bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
        Route::post('bookings', [BookingController::class, 'store'])->middleware('permission:bookings.create');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::post('bookings/{booking}/transition', [BookingController::class, 'transition'])->middleware('permission:bookings.transition');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->middleware('permission:bookings.cancel');

        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::post('payments', [PaymentController::class, 'store'])->middleware(['permission:payments.create', 'idempotent']);
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');

        Route::get('refunds', [RefundController::class, 'index'])->middleware('permission:payments.view');
        Route::post('refunds', [RefundController::class, 'store'])->middleware(['permission:refunds.create', 'idempotent']);
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->middleware('permission:refunds.approve');

        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:reports.view');
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('permission:reports.view');
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->middleware('permission:reports.view');
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:reports.view');
        Route::get('reports/aging', [ReportController::class, 'aging'])->middleware('permission:reports.view');
        Route::get('dashboard/summary', [ReportController::class, 'dashboard'])->middleware('permission:reports.view');

        Route::get('search', [SearchController::class, 'index'])->middleware('throttle:60,1');

        Route::get('leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
        Route::post('leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.update');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->middleware('permission:leads.convert');
    });
});
