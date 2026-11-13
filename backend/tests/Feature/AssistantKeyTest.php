<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The provider key is entered in Settings: encrypted at rest, write-only, audited, wins over env. */
class AssistantKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-saved-SECRET-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($admin, ['*']);
        config()->set('services.openai.key', '');
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        app(SettingService::class)->set('assistant', 'provider', 'openai');
    }

    public function test_saved_key_is_encrypted_never_returned_and_makes_the_provider_configured(): void
    {
        $this->getJson('/api/v1/feature-flags')->assertJsonPath('data.assistant_key_source', 'none');

        $this->putJson('/api/v1/assistant/key', ['api_key' => self::KEY])
            ->assertOk()->assertJsonPath('data.key_source', 'settings')
            ->assertDontSee(self::KEY);

        $raw = (string) Setting::query()->where('key', 'openai_api_key')->value('value');
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertTrue((bool) Setting::query()->where('key', 'openai_api_key')->value('is_secret'));

        $this->assertStringNotContainsString(self::KEY, $this->getJson('/api/v1/settings')->getContent());
        $this->getJson('/api/v1/feature-flags')
            ->assertJsonPath('data.assistant_key_configured', true)
            ->assertJsonPath('data.assistant_key_source', 'settings');
        $this->assertDatabaseHas('audit_events', ['action' => 'assistant.key_saved']);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode(\DB::table('audit_events')->get()));
    }

    public function test_saved_key_is_sent_to_the_provider_and_beats_the_environment(): void
    {
        config()->set('services.openai.key', 'sk-env-key-0123456789012345');
        $this->putJson('/api/v1/assistant/key', ['api_key' => self::KEY])->assertOk();
        Http::fake(['api.openai.test/v1/models' => Http::response(['data' => [['id' => 'gpt-4o-mini', 'created' => 1]]])]);

        $this->getJson('/api/v1/assistant/models')->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_removing_the_key_falls_back_to_the_environment(): void
    {
        $this->putJson('/api/v1/assistant/key', ['api_key' => self::KEY])->assertOk();
        config()->set('services.openai.key', 'sk-env-key-0123456789012345');

        $this->deleteJson('/api/v1/assistant/key')->assertOk()->assertJsonPath('data.key_source', 'env');
        $this->assertDatabaseMissing('settings', ['key' => 'openai_api_key']);
    }

    public function test_invalid_keys_and_missing_permission_are_refused(): void
    {
        $this->putJson('/api/v1/assistant/key', ['api_key' => 'short'])->assertStatus(422);
        $this->putJson('/api/v1/assistant/key', ['api_key' => 'has space in the key 0123456789'])->assertStatus(422);

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer, ['*']);
        $this->putJson('/api/v1/assistant/key', ['api_key' => self::KEY])->assertForbidden();
        $this->deleteJson('/api/v1/assistant/key')->assertForbidden();
    }

    public function test_an_unusable_app_key_gives_a_clear_error_and_stores_nothing(): void
    {
        config()->set('app.key', 'not-a-valid-key');
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->putJson('/api/v1/assistant/key', ['api_key' => self::KEY])
            ->assertStatus(503)->assertJsonPath('code', 'encryption_key_invalid');
        $this->assertDatabaseMissing('settings', ['key' => 'openai_api_key']);
        // The rest of the app keeps answering.
        $this->getJson('/api/v1/feature-flags')->assertOk();
    }

    public function test_the_generic_settings_endpoint_still_refuses_a_key(): void
    {
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'assistant', 'key' => 'openai_api_key', 'value' => self::KEY]]])
            ->assertStatus(422);
    }
}
