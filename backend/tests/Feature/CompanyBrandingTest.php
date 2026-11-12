<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Reports\Services\ReportDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CompanyBrandingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(RolePermissionSeeder::class);
        File::deleteDirectory(storage_path('app/branding'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/branding'));
        parent::tearDown();
    }

    private function actAs(?string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->branches()->attach($this->branch->id);
        if ($role !== null) {
            $user->assignRole($role);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function pngBytes(int $w = 64, int $h = 32): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function png(string $name = 'logo.png', int $w = 64, int $h = 32): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBytes($w, $h));
    }

    private function jpeg(string $name = 'logo.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(64, 32);
        ob_start();
        imagejpeg($im);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/api/v1/settings/logo', ['logo' => $file], self::JSON);
    }

    private function stored(): string
    {
        return (string) app(SettingService::class)->get('general', 'company_logo');
    }

    public function test_company_name_save_is_returned_by_settings_and_public_branding(): void
    {
        $this->actAs();

        $this->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'general', 'key' => 'company_name_ar', 'value' => 'النيل للسياحة'],
            ['group' => 'general', 'key' => 'company_name_en', 'value' => 'Nile Travel'],
        ]])->assertOk();

        $stored = collect($this->getJson('/api/v1/settings')->json('data'))->keyBy(fn ($row) => $row['group'].'.'.$row['key']);
        $this->assertSame('Nile Travel', $stored['general.company_name_en']['value']);
        $this->assertSame('النيل للسياحة', $stored['general.company_name_ar']['value']);

        $response = $this->getJson('/api/v1/branding')->assertOk();
        $response->assertJsonPath('data.company_name_en', 'Nile Travel')
            ->assertJsonPath('data.company_name_ar', 'النيل للسياحة')
            ->assertJsonPath('data.has_logo', false);
        $keys = array_keys($response->json('data'));
        sort($keys);
        $this->assertSame(['company_name_ar', 'company_name_en', 'has_logo', 'logo_version'], $keys);
    }

    public function test_blank_company_names_are_rejected_instead_of_silently_resetting(): void
    {
        $this->actAs();

        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'general', 'key' => 'company_name_en', 'value' => '   ']]])
            ->assertStatus(422);
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'general', 'key' => 'company_name_ar', 'value' => str_repeat('م', 121)]]])
            ->assertStatus(422);
    }

    public function test_branding_is_public_without_a_token(): void
    {
        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.company_name_en', 'Tourism ERP');
        $this->get('/api/v1/branding/logo')->assertNotFound();
    }

    public function test_valid_png_and_jpeg_are_accepted_and_served(): void
    {
        $this->actAs();

        $this->upload($this->png())->assertOk()->assertJsonPath('data.has_logo', true);
        $first = $this->stored();
        $this->assertMatchesRegularExpression('#^branding/logo-[0-9a-f]{32}\.png$#', $first);
        $this->assertFileExists(storage_path('app/'.$first));

        $served = $this->get('/api/v1/branding/logo')->assertOk();
        $served->assertHeader('Content-Type', 'image/png');
        $served->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->upload($this->jpeg())->assertOk();
        $this->assertMatchesRegularExpression('#^branding/logo-[0-9a-f]{32}\.jpg$#', $this->stored());
        $this->get('/api/v1/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_replacing_the_logo_deletes_the_old_file(): void
    {
        $this->actAs();
        $this->upload($this->png())->assertOk();
        $old = $this->stored();

        $this->upload($this->png())->assertOk();

        $this->assertNotSame($old, $this->stored());
        $this->assertFileDoesNotExist(storage_path('app/'.$old));
        $this->assertFileExists(storage_path('app/'.$this->stored()));
    }

    public function test_spoofed_content_is_rejected_and_client_names_are_ignored(): void
    {
        $this->actAs();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload($svg)->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->upload(UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('logo.jpg', "just text\n"))->assertStatus(422);

        $im = imagecreatetruecolor(32, 32);
        ob_start();
        imagegif($im);
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean()))->assertStatus(422);

        $this->assertSame('', $this->stored());

        $this->upload($this->png('../../evil.png'))->assertOk();
        $this->assertMatchesRegularExpression('#^branding/logo-[0-9a-f]{32}\.png$#', $this->stored());
        $this->assertFileDoesNotExist(storage_path('evil.png'));
    }

    public function test_oversized_and_out_of_range_images_are_rejected(): void
    {
        $this->actAs();

        $this->upload(UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes().str_repeat('0', 1048577)))
            ->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->upload($this->png('tiny.png', 4, 4))->assertStatus(422);
        $this->upload($this->png('huge.png', 4100, 20))->assertStatus(422);
        $this->post('/api/v1/settings/logo', [], self::JSON)->assertStatus(422);
    }

    public function test_remove_deletes_the_file_and_clears_the_setting(): void
    {
        $this->actAs();
        $this->upload($this->png())->assertOk();
        $path = storage_path('app/'.$this->stored());

        $this->deleteJson('/api/v1/settings/logo')->assertOk()->assertJsonPath('data.has_logo', false);

        $this->assertFileDoesNotExist($path);
        $this->assertSame('', $this->stored());
        $this->get('/api/v1/branding/logo')->assertNotFound();
    }

    public function test_a_legacy_path_value_keeps_working_and_is_not_deleted(): void
    {
        $this->actAs();
        File::ensureDirectoryExists(storage_path('app/branding'));
        file_put_contents(storage_path('app/branding/logo.png'), $this->pngBytes());
        app(SettingService::class)->set('general', 'company_logo', 'branding/logo.png');

        $this->get('/api/v1/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->upload($this->png())->assertOk();
        $this->assertFileExists(storage_path('app/branding/logo.png'), 'Files not created by the upload endpoint are never deleted.');
    }

    public function test_upload_and_remove_need_settings_update_and_are_audited(): void
    {
        $this->actAs(null);
        $this->upload($this->png())->assertForbidden();
        $this->deleteJson('/api/v1/settings/logo')->assertForbidden();
        $this->assertSame('', $this->stored());

        $this->actAs();
        $this->upload($this->png())->assertOk();
        $this->deleteJson('/api/v1/settings/logo')->assertOk();

        $this->assertSame(1, AuditEvent::query()->where('action', 'settings.logo_updated')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'settings.logo_removed')->count());
    }

    public function test_report_letterhead_renders_the_uploaded_logo(): void
    {
        $this->actAs();
        $this->upload($this->png())->assertOk();

        $settings = app(SettingService::class);
        $rows = [['Account', 'Debit'], ['Cash', '1.00']];
        $pdf = (new ReportDocument($settings))->pdf($rows, 'Test / اختبار', 'en');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);

        $this->deleteJson('/api/v1/settings/logo')->assertOk();
        $this->assertStringNotContainsString('/Subtype /Image', (new ReportDocument($settings))->pdf($rows, 'Test / اختبار', 'en'));
    }
}
