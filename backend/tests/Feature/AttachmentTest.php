<?php

namespace Tests\Feature;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Services\AttachmentTargets;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GAP-CORE-03 (ADR-0021): private attachments - upload, classify, preview, access and security checks. */
class AttachmentTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('attachments.disk'));
        $this->actAs('admin');
    }

    private function actAs(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create();
        $user->branches()->attach(($branch ?? $this->branch)->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function png(string $name = 'receipt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function upload(array $overrides = [], ?array $files = null)
    {
        return $this->post('/api/v1/attachments', array_merge([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer()->public_id,
            'category' => 'payment_receipt',
            'files' => $files ?? [$this->png()],
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_files_are_uploaded_classified_listed_previewed_and_downloaded(): void
    {
        $customer = $this->customer();
        $this->upload(['attachable_id' => $customer->public_id, 'notes' => 'first'], [$this->png('a.png')])
            ->assertCreated()->assertJsonPath('data.items.0.category', 'payment_receipt')->assertJsonPath('data.items.0.duplicate', false);

        $list = $this->getJson('/api/v1/attachments?attachable_type=customer&attachable_id='.$customer->public_id)->assertOk();
        $id = $list->json('data.items.0.id');
        $this->assertStringNotContainsString('stored_path', $list->getContent());

        $this->patchJson('/api/v1/attachments/'.$id, ['category' => 'bank_transfer', 'notes' => 'checked'])
            ->assertOk()->assertJsonPath('data.category', 'bank_transfer');

        $preview = $this->get('/api/v1/attachments/'.$id.'/preview')->assertOk();
        $this->assertSame('image/png', $preview->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));
        $this->get('/api/v1/attachments/'.$id.'/download')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'attachment.uploaded']);
        $this->assertDatabaseHas('audit_events', ['action' => 'attachment.downloaded']);
    }

    public function test_several_files_can_be_uploaded_at_once_and_a_repeat_is_not_duplicated(): void
    {
        $customer = $this->customer();
        $other = UploadedFile::fake()->createWithContent('b.png', base64_decode(self::PNG).'x'); // different bytes
        $first = $this->upload(['attachable_id' => $customer->public_id], [$this->png('a.png'), $other])->assertCreated();
        $this->assertCount(2, $first->json('data.items'));

        $again = $this->upload(['attachable_id' => $customer->public_id], [$this->png('copy.png')])->assertCreated();
        $this->assertTrue($again->json('data.items.0.duplicate'));
        $this->assertSame(2, Attachment::query()->count());
    }

    public function test_dangerous_or_unsupported_files_are_refused_without_blocking_the_good_ones(): void
    {
        $php = UploadedFile::fake()->createWithContent('shell.png', "<?php echo 'x';");
        $html = UploadedFile::fake()->createWithContent('page.pdf', '<html><script>alert(1)</script></html>');
        $activePdf = UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj");
        $response = $this->upload([], [$php, $html, $activePdf, $this->png('ok.png')])->assertCreated();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertCount(3, $response->json('data.rejected'));
        $this->assertSame(1, Attachment::query()->count());
        // The stored name is random and never the client's name.
        $this->assertStringNotContainsString('ok.png', (string) Attachment::query()->value('stored_path'));
    }

    public function test_a_plain_pdf_is_accepted_and_served_as_a_pdf(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        $this->upload(['category' => 'supplier_invoice'], [$pdf])->assertCreated()->assertJsonPath('data.items.0.mime', 'application/pdf');

        $id = Attachment::query()->value('public_id');
        $this->assertSame('application/pdf', $this->get('/api/v1/attachments/'.$id.'/preview')->headers->get('Content-Type'));
    }

    public function test_a_user_without_the_record_permission_or_branch_cannot_reach_its_documents(): void
    {
        $customer = $this->customer();
        $this->upload(['attachable_id' => $customer->public_id])->assertCreated();
        $id = Attachment::query()->value('public_id');

        $viewer = $this->actAs('viewer'); // may see customers, but has no attachments permission
        $this->getJson('/api/v1/attachments?attachable_type=customer&attachable_id='.$customer->public_id)->assertForbidden();
        $this->get('/api/v1/attachments/'.$id.'/download')->assertForbidden();

        $elsewhere = Branch::query()->create(['code' => 'ALX', 'name_ar' => 'x', 'name_en' => 'Alex', 'accounting_mode' => 'dimension', 'is_active' => true]);
        $this->actAs('sales', $elsewhere); // has the permissions, but not this branch
        $this->get('/api/v1/attachments/'.$id.'/download')->assertForbidden();
        $this->getJson('/api/v1/attachments?attachable_type=customer&attachable_id='.$customer->public_id)->assertForbidden();
        $this->assertNotNull($viewer);
    }

    public function test_personal_documents_need_the_sensitive_permission(): void
    {
        $customer = $this->customer();
        $this->upload(['attachable_id' => $customer->public_id, 'category' => 'passport'])->assertCreated();
        $this->upload(['attachable_id' => $customer->public_id], [UploadedFile::fake()->createWithContent('r.png', base64_decode(self::PNG).'y')])->assertCreated();
        $passportId = Attachment::query()->where('category', 'passport')->value('public_id');

        $this->actAs('accountant'); // can see attachments, not personal data
        $list = $this->getJson('/api/v1/attachments?attachable_type=customer&attachable_id='.$customer->public_id)->assertOk();
        $this->assertCount(1, $list->json('data.items'));
        $this->assertSame(1, $list->json('data.hidden_sensitive_count'));
        $this->get('/api/v1/attachments/'.$passportId.'/preview')->assertForbidden();
        $this->upload(['attachable_id' => $customer->public_id, 'category' => 'passport'], [UploadedFile::fake()->createWithContent('p.png', base64_decode(self::PNG).'z')])->assertForbidden();
    }

    public function test_delete_hides_the_document_and_is_audited(): void
    {
        $customer = $this->customer();
        $this->upload(['attachable_id' => $customer->public_id])->assertCreated();
        $id = Attachment::query()->value('public_id');

        $this->deleteJson('/api/v1/attachments/'.$id)->assertOk();
        $this->getJson('/api/v1/attachments/'.$id)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'attachment.deleted']);

        $this->actAs('sales'); // may upload but not delete
        $this->upload(['attachable_id' => $customer->public_id])->assertCreated();
        $this->deleteJson('/api/v1/attachments/'.Attachment::query()->latest('id')->value('public_id'))->assertForbidden();
    }

    public function test_a_failed_write_records_nothing_and_a_later_upload_repairs_it(): void
    {
        $customer = $this->customer();
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false); // the local disk does not throw when it is full
        $disk->shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('disk')->with(config('attachments.disk'))->andReturn($disk);

        $this->upload(['attachable_id' => $customer->public_id])->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');
        $this->assertSame(0, Attachment::query()->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'attachment.uploaded']);
    }

    public function test_uploading_again_rewrites_a_file_that_is_missing_from_the_disk(): void
    {
        $customer = $this->customer();
        $this->upload(['attachable_id' => $customer->public_id])->assertCreated();
        $attachment = Attachment::query()->firstOrFail();
        Storage::disk(config('attachments.disk'))->delete($attachment->stored_path);
        $this->get('/api/v1/attachments/'.$attachment->public_id.'/download')->assertNotFound();

        $this->upload(['attachable_id' => $customer->public_id])->assertCreated()->assertJsonPath('data.items.0.duplicate', true);

        Storage::disk(config('attachments.disk'))->assertExists($attachment->stored_path);
        $this->get('/api/v1/attachments/'.$attachment->public_id.'/download')->assertOk();
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_files_land_where_the_deployment_guide_says_to_back_them_up(): void
    {
        // docs/deployment/attachments.md: `storage/app/attachments`, the production_uploads volume.
        $this->assertSame('local', config('attachments.disk'));
        $this->assertSame(storage_path('app'), config('filesystems.disks.local.root'));
        $this->upload()->assertCreated();
        $this->assertStringStartsWith('attachments/', (string) Attachment::query()->value('stored_path'));
    }

    public function test_every_supported_record_type_accepts_documents(): void
    {
        $this->assertEqualsCanonicalizing(
            ['customer', 'supplier', 'booking', 'invoice', 'payment', 'expense'],
            AttachmentTargets::types(),
        );
    }
}
