<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportController;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\Reports\Models\ExportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GAP-RPT-02 / AC-RPT-007 (ADR-0025): large exports run in the background and the file exists only on success. */
class ExportJobTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Storage::fake(config('exports.disk'));
        foreach (['reports.view', 'reports.export', 'accounts.view'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['reports.view', 'reports.export', 'accounts.view']);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function queue(array $over = []): TestResponse
    {
        return $this->postJson('/api/v1/exports', $over + [
            'report' => 'trial-balance', 'format' => 'csv', 'params' => ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()],
        ]);
    }

    public function test_a_queued_export_succeeds_and_the_owner_downloads_the_complete_file(): void
    {
        $created = $this->queue()->assertCreated()->json('data');
        $this->assertContains($created['status'], ['queued', 'succeeded']); // the sync test queue runs it at once

        $job = $this->getJson('/api/v1/exports/'.$created['id'])->assertOk()->json('data');
        $this->assertSame('succeeded', $job['status'], json_encode($job));
        $this->assertNotEmpty($job['file_name']);
        $this->assertGreaterThan(0, $job['size_bytes']);
        $this->assertArrayNotHasKey('file_path', $job);

        $download = $this->get('/api/v1/exports/'.$created['id'].'/download')->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertNotSame('', $download->getContent());

        foreach (['export.queued', 'export.completed', 'export.downloaded', 'report.exported'] as $action) {
            $this->assertNotEmpty(AuditEvent::query()->where('action', $action)->get(), $action);
        }
    }

    public function test_a_failed_export_has_a_message_and_never_offers_a_file(): void
    {
        // The general ledger needs an account: the route's own validation fails inside the job.
        $created = $this->queue(['report' => 'general-ledger', 'params' => ['from' => now()->toDateString(), 'to' => now()->toDateString()]])->assertCreated()->json('data');

        $job = $this->getJson('/api/v1/exports/'.$created['id'])->assertOk()->json('data');
        $this->assertSame('failed', $job['status']);
        $this->assertNotEmpty($job['error']);
        $this->assertNull($job['file_name']);
        $this->assertSame([], Storage::disk(config('exports.disk'))->allFiles());
        $this->get('/api/v1/exports/'.$created['id'].'/download')->assertStatus(409)->assertJsonPath('code', 'export_not_ready');
    }

    public function test_only_the_owner_sees_or_downloads_an_export(): void
    {
        $created = $this->queue()->assertCreated()->json('data');

        $other = User::factory()->create();
        $other->givePermissionTo('reports.export');
        $other->branches()->attach($this->user->branches()->first()->id);
        Sanctum::actingAs($other, ['*']);

        $this->getJson('/api/v1/exports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/exports/'.$created['id'])->assertNotFound();
        $this->get('/api/v1/exports/'.$created['id'].'/download')->assertNotFound();
        $this->deleteJson('/api/v1/exports/'.$created['id'])->assertNotFound();
    }

    public function test_the_report_and_permission_are_checked(): void
    {
        $this->queue(['report' => 'users'])->assertUnprocessable();
        $this->queue(['format' => 'docx'])->assertUnprocessable();

        $noExport = User::factory()->create();
        $noExport->givePermissionTo('reports.view');
        Sanctum::actingAs($noExport, ['*']);
        $this->queue()->assertForbidden();
        $this->getJson('/api/v1/exports')->assertForbidden();
    }

    public function test_the_job_is_refused_by_the_report_when_the_user_lost_the_permission_meanwhile(): void
    {
        // Another report permission the user lacks: the replayed route enforces it, not this endpoint.
        $created = $this->queue(['report' => 'cost-center-pl', 'params' => ['from' => now()->toDateString(), 'to' => now()->toDateString()]])->assertCreated()->json('data');

        $job = $this->getJson('/api/v1/exports/'.$created['id'])->json('data');
        $this->assertSame('failed', $job['status']);
        $this->assertSame([], Storage::disk(config('exports.disk'))->allFiles());
    }

    public function test_expired_exports_are_refused_and_pruned_with_their_file(): void
    {
        $created = $this->queue()->assertCreated()->json('data');
        $path = ExportJob::query()->where('public_id', $created['id'])->value('file_path');
        Storage::disk(config('exports.disk'))->assertExists($path);

        ExportJob::query()->where('public_id', $created['id'])->update(['expires_at' => now()->subMinute()]);
        $this->get('/api/v1/exports/'.$created['id'].'/download')->assertStatus(410)->assertJsonPath('code', 'export_expired');

        $this->artisan('exports:prune')->assertSuccessful();
        Storage::disk(config('exports.disk'))->assertMissing($path);
        $this->assertSame(0, ExportJob::query()->count());
    }

    public function test_the_owner_can_remove_a_finished_export_and_its_file(): void
    {
        $created = $this->queue()->assertCreated()->json('data');
        $path = ExportJob::query()->where('public_id', $created['id'])->value('file_path');

        $this->deleteJson('/api/v1/exports/'.$created['id'])->assertOk();
        Storage::disk(config('exports.disk'))->assertMissing($path);
        $this->getJson('/api/v1/exports/'.$created['id'])->assertNotFound();
    }

    public function test_only_a_job_request_gets_the_higher_row_cap(): void
    {
        $controller = app(ReportController::class);
        $cap = new \ReflectionMethod($controller, 'maxRows');

        // A client can send any query or header; the cap changes only for the attribute the job dispatcher sets.
        $client = Request::create('/api/v1/reports/trial-balance/export?export_job=1', 'GET', ['export_job' => '1']);
        $client->headers->set('X-Export-Job', '1');
        app()->instance('request', $client);
        $this->assertSame(50000, $cap->invoke($controller));

        $job = Request::create('/api/v1/reports/trial-balance/export', 'GET');
        $job->attributes->set('export_job', true);
        app()->instance('request', $job);
        $this->assertSame(200000, $cap->invoke($controller));
    }
}
