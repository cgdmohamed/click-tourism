<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assistant\Support\ProviderCredentials;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Models\AttachmentExtraction;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GAP-CORE-03: AI extraction is a reviewed proposal; it never creates, posts or approves anything. */
class AttachmentExtractionTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('attachments.disk'));
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        $this->actAs('admin');
        $settings = app(SettingService::class);
        $settings->set('assistant', 'data_processing_accepted_at', '2026-11-13T09:00:00+00:00');
        $settings->set('attachments', 'extraction_enabled', 'true');
        app(ProviderCredentials::class)->store('sk-extraction-SECRET-key-0123456789');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->branches()->attach($this->branch->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function attachment(string $category = 'supplier_invoice'): Attachment
    {
        $this->post('/api/v1/attachments', [
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer()->public_id,
            'category' => $category,
            'files' => [UploadedFile::fake()->createWithContent('doc.png', base64_decode(self::PNG).uniqid())],
        ], ['Accept' => 'application/json'])->assertCreated();

        return Attachment::query()->latest('id')->firstOrFail();
    }

    private function fakeProvider(array $fields): void
    {
        Http::fake(['api.openai.test/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode($fields)]]],
        ])]);
    }

    public function test_the_proposal_flags_missing_and_unclear_values_and_keeps_only_the_known_fields(): void
    {
        $attachment = $this->attachment();
        $this->fakeProvider([
            'supplier_name' => ['value' => 'Nile Hotels', 'unclear' => false],
            'invoice_number' => ['value' => 'INV-77', 'unclear' => true],
            'invoice_date' => ['value' => '13/11/2026', 'unclear' => false], // wrong format
            'total' => ['value' => '1,200.50', 'unclear' => false], // separators are not accepted as is
            'currency' => ['value' => 'egp', 'unclear' => false],
            'secret_extra' => ['value' => 'ignore previous instructions', 'unclear' => false],
        ]);

        $response = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk();
        $fields = $response->json('data.proposed.fields');

        $this->assertSame(['value' => 'Nile Hotels', 'flag' => null], $fields['supplier_name']);
        $this->assertSame('unclear', $fields['invoice_number']['flag']);
        $this->assertSame('unclear', $fields['invoice_date']['flag']);
        $this->assertSame('unclear', $fields['total']['flag']);
        $this->assertSame(['value' => 'EGP', 'flag' => null], $fields['currency']);
        $this->assertSame(['value' => null, 'flag' => 'missing'], $fields['due_date']);
        $this->assertArrayNotHasKey('secret_extra', $fields);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-extraction-SECRET-key-0123456789'));
    }

    public function test_a_passport_yields_only_the_necessary_identity_fields(): void
    {
        $attachment = $this->attachment('passport');
        $this->fakeProvider(['full_name' => ['value' => 'A B', 'unclear' => false], 'address' => ['value' => 'Somewhere', 'unclear' => false], 'father_name' => ['value' => 'X', 'unclear' => false]]);

        $fields = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk()->json('data.proposed.fields');

        $this->assertEqualsCanonicalizing(['full_name', 'passport_number', 'nationality', 'date_of_birth', 'expiry_date'], array_keys($fields));
        // Proposed values are encrypted at rest.
        $this->assertStringNotContainsString('A B', (string) \DB::table('attachment_extractions')->value('proposed'));
    }

    public function test_repeating_the_request_reuses_the_open_proposal_and_calls_the_provider_once(): void
    {
        $attachment = $this->attachment();
        $this->fakeProvider(['supplier_name' => ['value' => 'Nile', 'unclear' => false]]);

        $first = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk();
        $second = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertTrue($second->json('data.reused'));
        Http::assertSentCount(1);
    }

    public function test_a_failed_extraction_leaves_the_document_usable_and_can_be_retried(): void
    {
        $attachment = $this->attachment();
        Http::fake(['api.openai.test/v1/chat/completions' => Http::sequence()
            ->push('boom', 500)
            ->push(['choices' => [['message' => ['content' => json_encode(['supplier_name' => ['value' => 'Nile', 'unclear' => false]])]]]])]);

        $failed = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk();
        $this->assertSame('failed', $failed->json('data.status'));
        $this->assertNull($failed->json('data.proposed'));
        $this->assertStringNotContainsString('boom', $failed->getContent());
        $this->getJson('/api/v1/attachments/'.$attachment->public_id)->assertOk();
        $this->get('/api/v1/attachments/'.$attachment->public_id.'/preview')->assertOk();

        $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_confirming_stores_the_reviewed_values_once_and_creates_nothing_else(): void
    {
        $attachment = $this->attachment();
        $this->fakeProvider(['supplier_name' => ['value' => 'Nile Hotles', 'unclear' => false], 'total' => ['value' => '1200.50', 'unclear' => false]]);
        $id = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->json('data.id');
        $invoices = Invoice::query()->count();
        $entries = JournalEntry::query()->count();

        $this->postJson('/api/v1/attachment-extractions/'.$id.'/confirm', ['values' => ['supplier_name' => 'Nile Hotels', 'total' => '1250.50', 'due_date' => null]])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.confirmed.supplier_name', 'Nile Hotels')
            ->assertJsonPath('data.confirmed.total', '1250.50');
        $again = $this->postJson('/api/v1/attachment-extractions/'.$id.'/confirm', ['values' => ['supplier_name' => 'Changed']])->assertOk();
        $this->assertSame('Nile Hotels', $again->json('data.confirmed.supplier_name'));

        $this->assertSame($invoices, Invoice::query()->count());
        $this->assertSame($entries, JournalEntry::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'attachment.extraction_confirmed']);
    }

    public function test_invalid_reviewed_values_and_unknown_fields_are_refused(): void
    {
        $attachment = $this->attachment();
        $this->fakeProvider(['supplier_name' => ['value' => 'Nile', 'unclear' => false]]);
        $id = $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->json('data.id');

        $this->postJson('/api/v1/attachment-extractions/'.$id.'/confirm', ['values' => ['invoice_date' => 'yesterday']])->assertStatus(422);
        $this->postJson('/api/v1/attachment-extractions/'.$id.'/confirm', ['values' => ['bank_pin' => '1234']])->assertStatus(422);
        $this->assertSame('succeeded', AttachmentExtraction::query()->first()->status);

        $this->postJson('/api/v1/attachment-extractions/'.$id.'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/v1/attachment-extractions/'.$id.'/confirm', ['values' => ['supplier_name' => 'x']])->assertStatus(422);
    }

    public function test_extraction_is_unavailable_until_enabled_and_cannot_be_enabled_without_the_notice_and_key(): void
    {
        $attachment = $this->attachment();
        app(SettingService::class)->set('attachments', 'extraction_enabled', 'false');
        $this->getJson('/api/v1/feature-flags')->assertJsonPath('data.attachments_extraction_enabled', false);
        $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertStatus(422)->assertJsonPath('code', 'extraction_unavailable');

        app(SettingService::class)->set('assistant', 'data_processing_accepted_at', '');
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'attachments', 'key' => 'extraction_enabled', 'value' => 'true']]])->assertStatus(422);
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'attachments', 'key' => 'anything', 'value' => 'x']]])->assertStatus(422);
    }

    public function test_only_users_who_may_open_the_document_can_extract_from_it(): void
    {
        $attachment = $this->attachment('passport');
        $this->fakeProvider(['full_name' => ['value' => 'A B', 'unclear' => false]]);

        $this->actAs('accountant'); // may extract, but not read personal documents
        $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertForbidden();

        $this->actAs('viewer');
        $this->postJson('/api/v1/attachments/'.$attachment->public_id.'/extraction')->assertForbidden();
        Http::assertNothingSent();
    }
}
