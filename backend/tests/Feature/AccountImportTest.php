<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountImportTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        foreach (['accounts.view', 'accounts.update'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['accounts.view', 'accounts.update']);
        Sanctum::actingAs($this->user);
    }

    private function import(string $content, bool $preview = false)
    {
        return $this->post('/api/v1/accounts/import', [
            'file' => UploadedFile::fake()->createWithContent('accounts.csv', $content),
            'preview_only' => $preview ? '1' : '0',
        ], ['Accept' => 'application/json', 'X-Branch-Id' => $this->branch->public_id]);
    }

    private function postHistory(string $code): Account
    {
        $account = Account::query()->where('code', $code)->firstOrFail();
        app(PostingService::class)->post(PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: [
                PostingLine::debit($account, 1000),
                PostingLine::credit($this->account('3200'), 1000),
            ],
        ));

        return $account->refresh();
    }

    public function test_type_of_account_with_journal_lines_cannot_be_changed(): void
    {
        $cash = $this->postHistory('1110');
        $csv = "code,name_ar,name_en,type\n1110,نقدية,Cash,expense\n";

        $this->import($csv, true)->assertStatus(422)->assertJsonPath('row_errors.0.row', 2)
            ->assertJsonPath('row_errors.0.code', 'account_has_history');
        $this->import($csv)->assertStatus(422);
        $this->assertSame('asset', $cash->refresh()->type);
    }

    public function test_cash_account_registration_locks_type_parent_and_currency(): void
    {
        $account = Account::query()->where('code', '1120')->firstOrFail();
        DB::table('cash_accounts')->insert([
            'public_id' => (string) Str::ulid(), 'account_id' => $account->id,
            'branch_id' => $this->branch->id, 'kind' => 'bank', 'name_ar' => 'بنك', 'name_en' => 'Bank',
            'currency_code' => 'EGP', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = "code,name_ar,name_en,type,parent_code,is_postable,is_active,currency_code\n"
            ."1120,بنك,Bank,expense,1110,false,true,USD\n";
        $response = $this->import($csv)->assertStatus(422);

        $fields = collect($response->json('row_errors'))->pluck('params.field')->all();
        $this->assertEqualsCanonicalizing(['type', 'parent_code', 'is_postable', 'currency_code'], $fields);
        $this->assertSame('asset', $account->refresh()->type);
    }

    public function test_history_account_can_still_be_renamed_by_import(): void
    {
        $this->postHistory('1110');

        $this->import("code,name_ar,name_en,type\n1110,نقدية جديدة,Cash renamed,asset\n")->assertOk();

        $this->assertSame('Cash renamed', Account::query()->where('code', '1110')->value('name_en'));
    }

    public function test_validation_errors_are_returned_with_row_numbers_and_messages(): void
    {
        $csv = "code,name_ar,name_en,type,parent_code\n"
            ."9001,أ,A,asset,\n"
            .",ب,B,asset,\n"
            ."9003,ج,C,nonsense,\n"
            ."9001,د,D,asset,\n"
            ."9005,هـ,E,asset,NOPE\n";

        $response = $this->import($csv, true)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $errors = collect($response->json('row_errors'));
        $this->assertSame([3, 4, 5, 6], $errors->pluck('row')->sort()->values()->all());
        $this->assertStringContainsString('Row 4', $errors->firstWhere('row', 4)['message']);
        $this->assertSame('invalid_type', $errors->firstWhere('row', 4)['code']);
        $this->assertSame('duplicate_code', $errors->firstWhere('row', 5)['code']);
        $this->assertSame('parent_not_found', $errors->firstWhere('row', 6)['code']);
        $this->assertDatabaseMissing('accounts', ['code' => '9001']);
    }

    public function test_export_can_be_imported_again_and_is_idempotent(): void
    {
        $export = $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->get('/api/v1/accounts/export')->assertOk();
        $content = $export->getContent();
        $count = Account::query()->count();
        $snapshot = fn () => Account::query()->orderBy('code')->get()
            ->map->only(['code', 'type', 'parent_id', 'is_postable', 'is_active', 'currency_code'])->all();
        $before = $snapshot();

        $this->assertStringContainsString('true', $content);
        $this->import($content, true)->assertOk()->assertJsonPath('data.total', $count);
        $this->import($content)->assertOk()->assertJsonPath('data.imported', $count);

        $this->assertSame($before, $snapshot());
        $this->assertSame($count, Account::query()->count());
    }

    public function test_legacy_human_headers_are_still_accepted(): void
    {
        $csv = "Code,Name (AR),Name (EN),Type,Parent Code,Postable,Active,Currency\n"
            ."9100,رئيسي,Group,asset,,No,Yes,\n"
            ."9101,فرعي,Child,asset,9100,Yes,No,usd\n";

        $this->import($csv)->assertOk()->assertJsonPath('data.imported', 2);

        $group = Account::query()->where('code', '9100')->firstOrFail();
        $child = Account::query()->where('code', '9101')->firstOrFail();
        $this->assertFalse($group->is_postable);
        $this->assertSame($group->id, $child->parent_id);
        $this->assertFalse($child->is_active);
        $this->assertSame('USD', $child->currency_code);
    }

    public function test_parent_may_appear_after_child_and_cycles_are_rejected(): void
    {
        $this->import("code,name_ar,name_en,type,parent_code\n9201,ب,Child,asset,9200\n9200,أ,Parent,asset,\n")->assertOk();
        $this->assertSame(Account::query()->where('code', '9200')->value('id'), Account::query()->where('code', '9201')->value('parent_id'));

        $cycle = "code,name_ar,name_en,type,parent_code\n9200,أ,Parent,asset,9201\n";
        $this->import($cycle, true)->assertStatus(422)->assertJsonPath('row_errors.0.code', 'circular_parent');
    }

    public function test_unknown_currency_and_bad_boolean_are_row_errors(): void
    {
        $csv = "code,name_ar,name_en,type,is_postable,currency_code\n9300,أ,A,asset,maybe,ZZZ\n";

        $codes = collect($this->import($csv, true)->assertStatus(422)->json('row_errors'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['invalid_boolean', 'invalid_currency'], $codes);
    }
}
