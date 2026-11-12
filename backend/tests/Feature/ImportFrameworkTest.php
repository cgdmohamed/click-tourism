<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\OpeningBalanceBatch;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Jobs\ProcessImportJob;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\ImportRunner;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class ImportFrameworkTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'customers.create', 'customers.update', 'suppliers.create', 'suppliers.update',
        'employees.manage', 'bookings.create', 'bookings.update', 'accounts.update',
        'opening_balances.manage',
    ];

    private const CUSTOMER_HEADER = "name_ar,name_en,phone,email,tax_id,credit_limit,currency\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Storage::fake(config('imports.disk'));

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);
        Sanctum::actingAs($this->user);
    }

    private function h(?Branch $branch = null): array
    {
        return ['Accept' => 'application/json', 'X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    /** @param  array<string, mixed>  $extra */
    private function upload(string $entity, string $content, array $extra = [], string $name = 'data.csv', ?Branch $branch = null): TestResponse
    {
        return $this->post('/api/v1/imports', $extra + [
            'entity' => $entity,
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ], $this->h($branch));
    }

    private function confirm(string $id, ?Branch $branch = null): TestResponse
    {
        return $this->postJson("/api/v1/imports/{$id}/confirm", [], $this->h($branch));
    }

    private function importCustomers(string $rows, array $extra = []): TestResponse
    {
        $response = $this->upload('customers', self::CUSTOMER_HEADER.$rows, $extra + ['dry_run' => '0']);
        $response->assertCreated();

        return $response;
    }

    // ---- templates -------------------------------------------------------

    public function test_templates_are_listed_with_versions_and_downloadable_in_both_languages(): void
    {
        $list = $this->getJson('/api/v1/imports/templates', $this->h())->assertOk();
        $entities = collect($list->json('data.templates'))->pluck('entity')->all();
        $this->assertEqualsCanonicalizing(['customers', 'suppliers', 'employees', 'tour-packages', 'accounts', 'opening-balance-lines'], $entities);
        $this->assertSame(1, collect($list->json('data.templates'))->firstWhere('entity', 'customers')['version']);
        $this->assertGreaterThan(0, $list->json('data.limits.max_rows'));

        $en = $this->get('/api/v1/imports/templates/customers?locale=en', $this->h())->assertOk();
        $this->assertStringContainsString('customers-template-v1-en.csv', $en->headers->get('content-disposition'));
        $content = $en->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Name (English)', $content);

        $ar = $this->get('/api/v1/imports/templates/customers?locale=ar', $this->h())->assertOk();
        $this->assertStringContainsString('الاسم (إنجليزي)', $ar->streamedContent());

        $xlsx = $this->get('/api/v1/imports/templates/suppliers?format=xlsx&locale=ar', $this->h())->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
    }

    public function test_downloaded_template_round_trips_through_an_upload(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $template = $this->get("/api/v1/imports/templates/customers?locale={$locale}", $this->h());
            $header = $template->streamedContent();
            $this->upload('customers', $header.",Ali,Ali {$locale},,,,,,\n")->assertCreated()
                ->assertJsonPath('data.status', 'previewed')
                ->assertJsonPath('data.created_count', 1);
        }
    }

    public function test_unknown_entity_template_is_404_and_users_only_see_templates_they_may_run(): void
    {
        $this->get('/api/v1/imports/templates/nope', $this->h())->assertNotFound()->assertJsonPath('code', 'unknown_entity');

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo('customers.create');
        Sanctum::actingAs($viewer);

        $entities = collect($this->getJson('/api/v1/imports/templates', $this->h())->json('data.templates'))->pluck('entity')->all();
        $this->assertSame(['customers'], $entities);
        $this->get('/api/v1/imports/templates/accounts', $this->h())->assertForbidden();
    }

    // ---- dry run / preview / confirm ------------------------------------

    public function test_dry_run_previews_without_writing_then_confirm_imports_and_audits(): void
    {
        $response = $this->upload('customers', self::CUSTOMER_HEADER."شركة النيل,Nile Co,+201001234567,nile@example.com,T-1,1500.50,EGP\nSecond,Second Co,,,,,\n");
        $response->assertCreated()
            ->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.mode', 'dry_run')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.error_count', 0);
        $this->assertSame(0, Customer::query()->count());
        $id = $response->json('data.id');

        $this->confirm($id)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_count', 2)->assertJsonPath('data.mode', 'commit');

        $customer = Customer::query()->where('email', 'nile@example.com')->firstOrFail();
        $this->assertSame(150050, $customer->credit_limit_minor);
        $this->assertSame($this->branch->id, $customer->branch_id);

        $actions = AuditEvent::query()->pluck('action')->all();
        foreach (['import.uploaded', 'import.previewed', 'import.confirmed', 'import.completed', 'customer.created'] as $action) {
            $this->assertContains($action, $actions);
        }
        // The raw upload is not kept once the job is final.
        $this->assertNull(ImportJob::query()->firstOrFail()->file_path);
        $this->assertSame([], Storage::disk(config('imports.disk'))->allFiles('imports/'.$id));
    }

    public function test_confirming_twice_or_a_non_previewed_job_conflicts(): void
    {
        $id = $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n")->json('data.id');
        $this->confirm($id)->assertOk();
        $this->confirm($id)->assertStatus(409)->assertJsonPath('code', 'invalid_state');
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_preview_can_be_discarded(): void
    {
        $id = $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n")->json('data.id');
        $this->deleteJson("/api/v1/imports/{$id}", [], $this->h())->assertNoContent();
        $this->assertSame(0, ImportJob::query()->count());
        $this->assertSame([], Storage::disk(config('imports.disk'))->allFiles());
    }

    public function test_job_status_and_list_endpoints(): void
    {
        $id = $this->importCustomers("A,A Co,,,,,\n")->json('data.id');
        $this->getJson("/api/v1/imports/{$id}", $this->h())->assertOk()->assertJsonPath('data.status', 'completed');
        $list = $this->getJson('/api/v1/imports?entity=customers', $this->h())->assertOk();
        $this->assertSame($id, $list->json('data.0.id'));
        $this->assertArrayNotHasKey('preview', $list->json('data.0'));
    }

    // ---- row validation, partial failure, report -------------------------

    public function test_row_errors_give_a_partial_import_and_a_downloadable_report(): void
    {
        $response = $this->importCustomers(
            "Good,Good Co,,good@example.com,,100,EGP\n"
            ."Bad,Bad Co,,not-an-email,,1.234,EGP\n"
            ."Worse,,,,,abc,XXX\n"
        );
        $response->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 2)
            ->assertJsonPath('data.has_error_report', true);
        $codes = collect($response->json('data.errors'))->pluck('code')->all();
        foreach (['invalid_email', 'too_many_decimals', 'required', 'invalid_amount', 'invalid_currency'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame(1, Customer::query()->count());

        $report = $this->get('/api/v1/imports/'.$response->json('data.id').'/errors', $this->h())->assertOk();
        $csv = $report->streamedContent();
        $this->assertStringContainsString('row,field,code,message', $csv);
        $this->assertStringContainsString('invalid_email', $csv);
        $this->assertStringContainsString('Bad Co', $csv);
    }

    public function test_all_rows_invalid_marks_the_job_failed(): void
    {
        $response = $this->importCustomers("Bad,Bad Co,,nope,,,\n");
        $response->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'validation_failed');
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_on_error_abort_writes_nothing_when_any_row_fails(): void
    {
        $response = $this->importCustomers("Good,Good Co,,,,,\nBad,Bad Co,,nope,,,\n", ['on_error' => 'abort']);
        $response->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'aborted_on_errors')
            ->assertJsonPath('data.created_count', 0);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_error_report_without_errors_is_404(): void
    {
        $id = $this->importCustomers("A,A Co,,,,,\n")->json('data.id');
        $this->get("/api/v1/imports/{$id}/errors", $this->h())->assertNotFound()->assertJsonPath('code', 'no_error_report');
    }

    public function test_missing_required_column_and_unknown_columns(): void
    {
        $this->upload('customers', "name_en,phone\nA,1234567\n")
            ->assertStatus(422)->assertJsonPath('code', 'missing_columns');
        $this->upload('customers', "name_ar,name_en,favourite colour\nA,B,red\n")
            ->assertCreated()->assertJsonPath('data.warnings.0.code', 'unknown_columns');
        $this->upload('customers', "name_ar,name_en,name_en\nA,B,C\n")
            ->assertStatus(422)->assertJsonPath('code', 'duplicate_column');
    }

    public function test_header_only_empty_and_wrong_template_version_files_are_rejected(): void
    {
        $this->upload('customers', self::CUSTOMER_HEADER)->assertStatus(422)->assertJsonPath('code', 'no_data_rows');
        $this->upload('customers', '')->assertStatus(422)->assertJsonPath('code', 'empty_file');
        $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n", ['template_version' => 99])
            ->assertStatus(422)->assertJsonPath('code', 'unsupported_template_version');
        $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n", ['template_version' => 1])->assertCreated();
        $this->assertSame(1, ImportJob::query()->count());
    }

    // ---- duplicate policy -----------------------------------------------

    private function existingCustomer(): Customer
    {
        return Customer::query()->create([
            'type' => 'individual', 'name_ar' => 'قديم', 'name_en' => 'Old Name', 'phone' => '0100',
            'email' => 'dup@example.com', 'branch_id' => $this->branch->id, 'credit_limit_minor' => 0,
            'currency_code' => 'EGP', 'is_active' => true,
        ]);
    }

    public function test_duplicate_policy_skip_leaves_the_existing_record_alone(): void
    {
        $existing = $this->existingCustomer();
        $response = $this->importCustomers("جديد,New Name,,DUP@example.com,,,\nFresh,Fresh,,fresh@example.com,,,\n", ['duplicate_policy' => 'skip']);
        $response->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.skipped_count', 1)->assertJsonPath('data.created_count', 1);
        $this->assertSame('Old Name', $existing->refresh()->name_en);
    }

    public function test_duplicate_policy_update_changes_only_provided_columns(): void
    {
        $existing = $this->existingCustomer();
        $response = $this->importCustomers("جديد,New Name,,dup@example.com,,,\n", ['duplicate_policy' => 'update']);
        $response->assertJsonPath('data.updated_count', 1);
        $existing->refresh();
        $this->assertSame('New Name', $existing->name_en);
        $this->assertSame('0100', $existing->phone, 'blank cells never erase data');
        $this->assertContains('customer.updated', AuditEvent::query()->pluck('action')->all());
    }

    public function test_duplicate_policy_fail_reports_the_row_and_requires_no_update_permission(): void
    {
        $this->existingCustomer();
        $response = $this->importCustomers("X,Dup,,dup@example.com,,,\n", ['duplicate_policy' => 'fail']);
        $response->assertJsonPath('data.status', 'failed')->assertJsonPath('data.errors.0.code', 'duplicate_exists');
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_duplicates_inside_the_file_are_reported_once_per_extra_row(): void
    {
        $response = $this->importCustomers("A,Same,,same@example.com,,,\nB,Other,,SAME@example.com,,,\n");
        $response->assertJsonPath('data.status', 'partial')->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.errors.0.code', 'duplicate_in_file')->assertJsonPath('data.errors.0.row', 3);
    }

    public function test_update_policy_cannot_change_the_currency_of_an_existing_customer(): void
    {
        $this->existingCustomer();
        $response = $this->importCustomers("A,A,,dup@example.com,,,USD\n", ['duplicate_policy' => 'update']);
        $response->assertJsonPath('data.errors.0.code', 'apply_failed');
    }

    // ---- spreadsheet formula injection -----------------------------------

    public function test_cells_that_look_like_formulas_are_rejected_per_row(): void
    {
        $csv = ''
            .'"=HYPERLINK(""http://evil"",""x"")",Evil One,,,,,'."\n"
            .'Evil2,"@SUM(1+1)",,,,,'."\n"
            .'Evil3,"-2+3+cmd|\' /C calc\'!A0",,,,,'."\n"
            ."\"\t=1+1\",Evil4,,,,,\n"
            ."Fine,Fine Co,+20 100 123 4567,,,,\n";
        $response = $this->importCustomers($csv);

        $response->assertJsonPath('data.status', 'partial')->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.error_count', 4);
        $this->assertSame(['formula_detected'], array_values(array_unique(collect($response->json('data.errors'))->pluck('code')->all())));
        $this->assertSame(['Fine Co'], Customer::query()->pluck('name_en')->all());

        // The report echoes user input, so it must itself be injection-safe.
        $report = $this->get('/api/v1/imports/'.$response->json('data.id').'/errors', $this->h())->streamedContent();
        $this->assertStringNotContainsString(',=HYPERLINK', $report);
        $this->assertStringContainsString("'=HYPERLINK", $report);
        $this->assertStringContainsString("'@SUM", $report);
    }

    public function test_legitimate_signed_numbers_and_phones_are_not_mistaken_for_formulas(): void
    {
        $response = $this->importCustomers("Phone,Phone Co,+201001234567,,,,\nDash,Dash Co,010-0123-4567,,,,\n");
        $response->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_count', 2);
    }

    // ---- encoding, size and row limits -----------------------------------

    public function test_non_utf8_files_are_rejected_without_creating_a_job(): void
    {
        $latin1 = self::CUSTOMER_HEADER.mb_convert_encoding("José,José Co,,,,,\n", 'ISO-8859-1', 'UTF-8');
        $this->upload('customers', $latin1)->assertStatus(422)->assertJsonPath('code', 'invalid_encoding');
        $this->upload('customers', self::CUSTOMER_HEADER."A\0B,Bin,,,,,\n")->assertStatus(422)->assertJsonPath('code', 'invalid_encoding');
        $this->assertSame(0, ImportJob::query()->count());
    }

    public function test_utf16_with_bom_and_semicolon_delimiters_are_accepted(): void
    {
        $utf16 = "\xFF\xFE".mb_convert_encoding("name_ar;name_en\nعلي;Ali Co\n", 'UTF-16LE', 'UTF-8');
        $this->upload('customers', $utf16, ['dry_run' => '0'])->assertCreated()->assertJsonPath('data.created_count', 1);
        $this->assertSame('علي', Customer::query()->firstOrFail()->name_ar);
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['imports.max_file_bytes' => 100]);
        $this->upload('customers', self::CUSTOMER_HEADER.str_repeat("A,Some Name,,,,,\n", 20))
            ->assertStatus(413)->assertJsonPath('code', 'file_too_large');
        $this->assertSame(0, ImportJob::query()->count());
    }

    public function test_row_and_cell_limits_are_enforced(): void
    {
        config(['imports.max_rows' => 3]);
        $this->upload('customers', self::CUSTOMER_HEADER.str_repeat("A,B,,,,,\n", 4))
            ->assertStatus(422)->assertJsonPath('code', 'too_many_rows');
        $this->upload('customers', self::CUSTOMER_HEADER.str_repeat("A,B,,,,,\n", 3))->assertCreated();

        config(['imports.max_cell_length' => 10]);
        $this->upload('customers', self::CUSTOMER_HEADER.'A,'.str_repeat('x', 11).",,,,,\n")
            ->assertStatus(422)->assertJsonPath('code', 'cell_too_long');
    }

    public function test_unsupported_file_types_are_rejected(): void
    {
        $this->upload('customers', 'MZ binary', [], 'run.exe')->assertStatus(422)->assertJsonPath('code', 'unsupported_file_type');
        // An archive that is not named .xlsx is not trusted either.
        $this->upload('customers', "PK\x03\x04junk", [], 'data.csv')->assertStatus(422)->assertJsonPath('code', 'unsupported_file_type');
        $this->upload('customers', "PK\x03\x04junk", [], 'data.xlsx')->assertStatus(422)->assertJsonPath('code', 'unreadable_file');
    }

    // ---- safe type casting ----------------------------------------------

    public function test_money_is_parsed_exactly_using_the_currency_exponent(): void
    {
        $this->importCustomers(
            "A,A,,a@example.com,,\"1,234.5\",EGP\n"
            ."B,B,,b@example.com,,\"١٢٣٫٤٥\",EGP\n"
            ."C,C,,c@example.com,,10.125,KWD\n"
            ."D,D,,d@example.com,,0.07,USD\n"
        )->assertJsonPath('data.status', 'completed');
        $limits = Customer::query()->orderBy('id')->pluck('credit_limit_minor', 'email')->all();
        $this->assertSame(['a@example.com' => 123450, 'b@example.com' => 12345, 'c@example.com' => 10125, 'd@example.com' => 7], $limits);
    }

    public function test_bad_types_and_values_become_row_errors_not_exceptions(): void
    {
        $r = $this->importCustomers("A,A,,,,-5,\nB,B,12ab,,,,\nC,C,,,,1e3,\nD,D,,,,,\n");
        $r->assertJsonPath('data.created_count', 1);
        $codes = collect($r->json('data.errors'))->pluck('code', 'row')->all();
        $this->assertSame('invalid_amount', $codes[2]);
        $this->assertSame('invalid_phone', $codes[3]);
        $this->assertSame('invalid_amount', $codes[4]);
    }

    // ---- other entities --------------------------------------------------

    public function test_suppliers_import(): void
    {
        $this->upload('suppliers', "category,name_ar,name_en,settlement_currency\nhotel,فندق,Hotel One,USD\n,مورد,Plain,\n", ['dry_run' => '0'])
            ->assertJsonPath('data.created_count', 2);
        $this->assertSame('USD', Supplier::query()->where('name_en', 'Hotel One')->value('settlement_currency'));
        $this->assertSame('other', Supplier::query()->where('name_en', 'Plain')->value('category'));
    }

    public function test_employees_import_creates_and_updates_by_code(): void
    {
        $csv = "internal_code,name_ar,name_en,category\nemp-1,أحمد,Ahmed,driver\nEMP-2,منى,Mona,\n";
        $this->upload('employees', $csv, ['dry_run' => '0'])->assertJsonPath('data.created_count', 2);
        $this->assertSame('driver', Employee::query()->where('internal_code', 'EMP-1')->value('category'));

        $this->upload('employees', "internal_code,name_ar,name_en\nEMP-1,أحمد,Ahmed Updated\n", ['dry_run' => '0', 'duplicate_policy' => 'update'])
            ->assertJsonPath('data.updated_count', 1);
        $this->assertSame('Ahmed Updated', Employee::query()->where('internal_code', 'EMP-1')->value('name_en'));
        $this->assertSame('driver', Employee::query()->where('internal_code', 'EMP-1')->value('category'));
    }

    public function test_tour_packages_import(): void
    {
        $csv = "code,name,category,execution_type,destination,currency,base_price,estimated_cost\n"
            ."UMR1,Umrah,umrah,fixed,Makkah,SAR,12500.00,9800\n"
            .",Day trip,day_trip,private,Giza,EGP,500,300\n"
            .",Bad,cruise,private,Nile,EGP,1,1\n";
        $response = $this->upload('tour-packages', $csv, ['dry_run' => '0']);
        $response->assertJsonPath('data.status', 'partial')->assertJsonPath('data.created_count', 2);
        $umrah = TourPackage::query()->where('code', 'UMR1')->firstOrFail();
        $this->assertSame(1250000, $umrah->base_price_minor);
        $this->assertSame(980000, $umrah->estimated_cost_minor);
        $this->assertStringStartsWith('TOUR-', TourPackage::query()->where('name', 'Day trip')->value('code'));

        // Re-import with update changes the price and the generated-code package is matched by name + destination.
        $this->upload('tour-packages', "name,category,execution_type,destination,currency,base_price,estimated_cost\nDay trip,day_trip,private,giza,EGP,650,300\n", ['dry_run' => '0', 'duplicate_policy' => 'update'])
            ->assertJsonPath('data.updated_count', 1);
        $this->assertSame(65000, TourPackage::query()->where('name', 'Day trip')->value('base_price_minor'));
    }

    public function test_xlsx_files_are_supported(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['الاسم (عربي)', 'الاسم (إنجليزي)', 'حد الائتمان', 'الهاتف']));
        $writer->addRow(Row::fromValues(['شركة', 'Excel Co', 2500.5, 201001234567]));
        $writer->addRow(Row::fromValues(['=cmd', 'Formula Co', 1, '']));
        $writer->close();

        $response = $this->post('/api/v1/imports', [
            'entity' => 'customers', 'dry_run' => '0',
            'file' => new UploadedFile($path, 'customers.xlsx', null, null, true),
        ], $this->h());
        $response->assertCreated()->assertJsonPath('data.file_format', 'xlsx')
            ->assertJsonPath('data.status', 'partial')->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.errors.0.code', 'formula_detected');
        $customer = Customer::query()->where('name_en', 'Excel Co')->firstOrFail();
        $this->assertSame(250050, $customer->credit_limit_minor);
        $this->assertSame('201001234567', $customer->phone);
    }

    // ---- accounts and opening balances through the same job UI -----------

    public function test_accounts_import_through_the_job_flow_matches_the_legacy_rules(): void
    {
        $csv = "code,name_ar,name_en,type,parent_code\n7000,حساب جديد,New Account,expense,\n7010,فرعي,Child,expense,7000\n";
        $id = $this->upload('accounts', $csv)->assertJsonPath('data.status', 'previewed')->assertJsonPath('data.created_count', 2)->json('data.id');
        $this->assertNull(Account::query()->where('code', '7000')->first());
        $this->confirm($id)->assertJsonPath('data.status', 'completed');
        $this->assertSame(Account::query()->where('code', '7000')->value('id'), Account::query()->where('code', '7010')->value('parent_id'));

        $bad = $this->upload('accounts', "code,name_ar,name_en,type\n7100,x,y,wizard\n7101,a,b,asset\n", ['dry_run' => '0']);
        $bad->assertJsonPath('data.status', 'failed')->assertJsonPath('data.errors.0.code', 'invalid_type');
        $this->assertNull(Account::query()->where('code', '7101')->first(), 'account imports are all-or-nothing');
    }

    public function test_accounts_duplicate_policy_skip_and_fail(): void
    {
        $csv = "code,name_ar,name_en,type\n1110,x,Renamed,asset\n7200,a,b,asset\n";
        $this->upload('accounts', $csv, ['dry_run' => '0', 'duplicate_policy' => 'skip'])
            ->assertJsonPath('data.skipped_count', 1)->assertJsonPath('data.created_count', 1);
        $this->assertNotSame('Renamed', $this->account('1110')->name_en);
        $this->upload('accounts', $csv, ['dry_run' => '0', 'duplicate_policy' => 'fail'])
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.errors.0.code', 'duplicate_exists');
    }

    public function test_opening_balance_lines_dry_run_then_commit_creates_a_draft_batch(): void
    {
        $csv = "account_code,debit,credit,currency\n1110,1000.00,,EGP\n3200,,1000.00,EGP\n";
        $options = ['options' => ['as_of_date' => now()->toDateString(), 'memo' => 'Cutover']];

        $id = $this->upload('opening-balance-lines', $csv, $options)->assertJsonPath('data.status', 'previewed')->assertJsonPath('data.created_count', 2)->json('data.id');
        $this->assertSame(0, OpeningBalanceBatch::query()->count(), 'a dry run leaves no draft behind');

        $this->confirm($id)->assertJsonPath('data.status', 'completed');
        $batch = OpeningBalanceBatch::query()->firstOrFail();
        $this->assertSame('draft', $batch->status);
        $this->assertSame(2, $batch->lines()->count());
        $this->assertSame(100000, (int) $batch->lines()->where('debit_minor', '>', 0)->value('debit_minor'));
        $this->assertSame($batch->public_id, ImportJob::query()->firstOrFail()->options['result']['opening_balance_batch_id']);
    }

    public function test_opening_balance_lines_validation_failures(): void
    {
        $options = ['options' => ['as_of_date' => now()->toDateString()]];
        $this->upload('opening-balance-lines', "account_code,debit,credit\n1110,10,\n3200,,5\n", $options + ['dry_run' => '0'])
            ->assertJsonPath('data.status', 'failed');
        $this->assertSame(0, OpeningBalanceBatch::query()->count());

        $this->upload('opening-balance-lines', "account_code,debit,credit\n1110,10,10\n3200,,10\n", $options)
            ->assertJsonPath('data.errors.0.code', 'debit_or_credit');
        $this->upload('opening-balance-lines', "account_code,debit,credit\n9999,10,\n3200,,10\n", $options)
            ->assertJsonPath('data.status', 'previewed')->assertJsonPath('data.error_count', 1);

        $this->upload('opening-balance-lines', "account_code,debit,credit\n1110,10,\n3200,,10\n")
            ->assertStatus(422)->assertJsonValidationErrors('as_of_date');
    }

    // ---- authorisation and branch scoping --------------------------------

    public function test_importing_requires_the_entity_permission(): void
    {
        $limited = User::factory()->create();
        $limited->branches()->attach($this->branch->id);
        $limited->givePermissionTo('customers.create');
        Sanctum::actingAs($limited);

        $this->upload('suppliers', "name_ar,name_en\nA,B\n")->assertForbidden();
        $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n", ['duplicate_policy' => 'update'])->assertForbidden();
        $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n")->assertCreated();
        $this->assertSame(1, ImportJob::query()->count());
    }

    public function test_import_into_a_branch_the_user_is_not_assigned_to_is_forbidden(): void
    {
        $other = Branch::query()->create(['code' => 'ALX', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'accounting_mode' => 'dimension', 'is_active' => true]);
        $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n", [], 'data.csv', $other)->assertForbidden();
        $this->assertSame(0, ImportJob::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_jobs_are_invisible_and_untouchable_from_another_branch(): void
    {
        $id = $this->upload('customers', self::CUSTOMER_HEADER."A,B,,,,,\n")->json('data.id');

        $other = Branch::query()->create(['code' => 'ALX', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'accounting_mode' => 'dimension', 'is_active' => true]);
        $outsider = User::factory()->create();
        $outsider->branches()->attach($other->id);
        $outsider->givePermissionTo(self::PERMISSIONS);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/imports/{$id}", $this->h($other))->assertForbidden();
        $this->postJson("/api/v1/imports/{$id}/confirm", [], $this->h($other))->assertForbidden();
        $this->get("/api/v1/imports/{$id}/errors", $this->h($other))->assertForbidden();
        $this->deleteJson("/api/v1/imports/{$id}", [], $this->h($other))->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/imports', $this->h($other))->json('data'));
        $this->assertSame('previewed', ImportJob::query()->firstOrFail()->status);
    }

    public function test_rows_never_match_or_modify_records_of_another_branch(): void
    {
        $other = Branch::query()->create(['code' => 'ALX', 'name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'accounting_mode' => 'dimension', 'is_active' => true]);
        $foreign = Customer::query()->create([
            'type' => 'individual', 'name_ar' => 'x', 'name_en' => 'Foreign', 'email' => 'dup@example.com',
            'branch_id' => $other->id, 'credit_limit_minor' => 0, 'currency_code' => 'EGP', 'is_active' => true,
        ]);

        // Same email in our branch is a brand-new customer, never an update of the foreign one.
        $this->importCustomers("Mine,Mine,,dup@example.com,,,\n", ['duplicate_policy' => 'update'])
            ->assertJsonPath('data.created_count', 1)->assertJsonPath('data.updated_count', 0);
        $this->assertSame('Foreign', $foreign->refresh()->name_en);
        $this->assertSame(2, Customer::query()->count());

        // Employee codes are global: a code owned by another branch is reported, not touched.
        Employee::query()->create(['internal_code' => 'EMP-X', 'name_ar' => 'x', 'name_en' => 'Foreign Emp', 'category' => 'staff', 'branch_id' => $other->id, 'is_active' => true]);
        $response = $this->upload('employees', "internal_code,name_ar,name_en\nEMP-X,x,Hijack\n", ['dry_run' => '0', 'duplicate_policy' => 'update']);
        $response->assertJsonPath('data.status', 'failed')->assertJsonPath('data.errors.0.code', 'other_branch');
        $this->assertSame('Foreign Emp', Employee::query()->where('internal_code', 'EMP-X')->value('name_en'));
    }

    // ---- background processing -------------------------------------------

    public function test_large_files_are_queued_and_run_by_the_worker(): void
    {
        Queue::fake();
        config(['imports.sync_max_bytes' => 10]);

        $response = $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n", ['dry_run' => '0']);
        $response->assertCreated()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(ProcessImportJob::class);
        $this->assertSame(0, Customer::query()->count());

        (new ProcessImportJob(ImportJob::query()->firstOrFail()->id))->handle(app(ImportRunner::class));

        $this->getJson('/api/v1/imports/'.$response->json('data.id'), $this->h())
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_count', 1);
    }

    public function test_a_redelivered_queue_message_does_not_run_the_import_twice(): void
    {
        Queue::fake();
        config(['imports.sync_max_bytes' => 10]);
        $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n", ['dry_run' => '0'])->assertCreated();
        $id = ImportJob::query()->firstOrFail()->id;

        app(ImportRunner::class)->run($id);
        app(ImportRunner::class)->run($id);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_worker_crash_marks_the_job_failed(): void
    {
        Queue::fake();
        config(['imports.sync_max_bytes' => 10]);
        $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n", ['dry_run' => '0'])->assertCreated();
        $job = ImportJob::query()->firstOrFail();

        (new ProcessImportJob($job->id))->failed(new \RuntimeException('killed'));

        $this->assertSame('failed', $job->refresh()->status);
        $this->assertSame('worker_failed', $job->failure_code);
    }

    public function test_a_user_who_lost_the_permission_before_the_worker_ran_cannot_import(): void
    {
        Queue::fake();
        config(['imports.sync_max_bytes' => 10]);
        $this->upload('customers', self::CUSTOMER_HEADER."A,A Co,,,,,\n", ['dry_run' => '0'])->assertCreated();
        $this->user->revokePermissionTo('customers.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new ProcessImportJob(ImportJob::query()->firstOrFail()->id))->handle(app(ImportRunner::class));

        $this->assertSame('failed', ImportJob::query()->firstOrFail()->status);
        $this->assertSame('forbidden', ImportJob::query()->firstOrFail()->failure_code);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/imports/templates')->assertUnauthorized();
    }
}
