<?php

namespace Tests\Feature;

use App\Modules\Assistant\Support\ModelCatalogue;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /assistant/models (ADR-0018): model discovery for the Settings picker, the recommended
 * model rule, chat-family filtering, the 503/502 codes and secret hygiene.
 */
class AssistantModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-models-SECRET-key-0123456789';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->admin, ['*']);

        config()->set('services.openai.key', self::KEY);
        config()->set('services.openai.base_url', 'https://api.openai.test/v1');
        app(SettingService::class)->set('assistant', 'provider', 'openai');
    }

    /** @param  list<array{id: string, created?: int}>  $models */
    private function fakeModels(array $models): void
    {
        Http::fake([
            'api.openai.test/v1/models' => Http::response([
                'object' => 'list',
                'data' => array_map(fn (array $row): array => ['object' => 'model'] + $row, $models),
            ]),
        ]);
    }

    public function test_settings_view_is_required_and_saving_still_needs_settings_update(): void
    {
        $this->fakeModels([['id' => 'gpt-4o-mini', 'created' => 100]]);

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer, ['*']);

        $this->getJson('/api/v1/assistant/models')->assertStatus(403);

        // Reading the catalogue needs settings.view only; writing a chosen model still needs settings.update.
        $viewer->givePermissionTo('settings.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer, ['*']);

        $this->getJson('/api/v1/assistant/models')->assertOk();
        $this->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'assistant', 'key' => 'model', 'value' => 'gpt-4o-mini'],
        ]])->assertStatus(403);
    }

    public function test_without_a_key_the_endpoint_answers_503(): void
    {
        Http::fake();

        config()->set('services.openai.key', '');
        $missing = $this->getJson('/api/v1/assistant/models')
            ->assertStatus(503)
            ->assertJsonPath('code', 'assistant_not_configured');
        $this->assertStringContainsString('Settings', $missing->json('message'));

        app(SettingService::class)->set('assistant', 'provider', 'none');
        $this->getJson('/api/v1/assistant/models')
            ->assertStatus(503)
            ->assertJsonPath('code', 'assistant_not_configured');

        Http::assertNothingSent();
    }

    /**
     * The key in the environment is enough: a fresh install keeps provider "none" until a model is
     * saved, so discovery must not require the provider setting first (owner requirement, ADR-0018).
     */
    public function test_a_it_lists_models_while_the_provider_is_still_none(): void
    {
        $this->fakeModels([
            ['id' => 'gpt-4o', 'created' => 400],
            ['id' => 'gpt-4o-mini', 'created' => 300],
        ]);
        app(SettingService::class)->set('assistant', 'provider', 'none');

        $this->getJson('/api/v1/assistant/models')->assertOk()
            ->assertJsonPath('data.key_configured', true)
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.recommended', 'gpt-4o-mini');

        // Discovery never writes the setting: only saving the model in Settings does.
        $this->assertSame('none', app(SettingService::class)->get('assistant', 'provider'));
    }

    public function test_it_returns_the_chat_models_with_the_preferred_model_recommended(): void
    {
        $this->fakeModels([
            ['id' => 'gpt-4o', 'created' => 400],
            ['id' => 'gpt-4.1-mini', 'created' => 300],
            ['id' => 'o3-mini', 'created' => 200],
        ]);

        $response = $this->getJson('/api/v1/assistant/models')->assertOk()
            ->assertJsonPath('data.key_configured', true)
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.recommended', 'gpt-4.1-mini')
            ->assertJsonCount(3, 'data.models');

        $recommended = collect($response->json('data.models'))->where('recommended', true)->pluck('id')->all();
        $this->assertSame(['gpt-4.1-mini'], $recommended);

        [$request] = Http::recorded()->first();
        $this->assertSame('Bearer '.self::KEY, $request->header('Authorization')[0]);
        $this->assertSame('https://api.openai.test/v1/models', $request->url());
        $this->assertSame('GET', $request->method());
    }

    public function test_non_chat_models_are_filtered_out(): void
    {
        $this->fakeModels([
            ['id' => 'gpt-4o-mini', 'created' => 400],
            ['id' => 'text-embedding-3-large', 'created' => 300],
            ['id' => 'whisper-1', 'created' => 300],
            ['id' => 'tts-1', 'created' => 300],
            ['id' => 'dall-e-3', 'created' => 300],
            ['id' => 'gpt-image-1', 'created' => 300],
            ['id' => 'gpt-4o-realtime-preview', 'created' => 300],
            ['id' => 'gpt-4o-transcribe', 'created' => 300],
            ['id' => 'omni-moderation-latest', 'created' => 300],
            ['id' => 'gpt-4o-search-preview', 'created' => 300],
            ['id' => 'davinci-002', 'created' => 300],
            ['id' => 'babbage-002', 'created' => 300],
        ]);

        $ids = array_column($this->getJson('/api/v1/assistant/models')->assertOk()->json('data.models'), 'id');

        $this->assertSame(['gpt-4o-mini'], $ids);
    }

    public function test_the_newest_mini_model_is_the_fallback(): void
    {
        $this->fakeModels([
            ['id' => 'gpt-5.1', 'created' => 900],
            ['id' => 'gpt-4.9-mini', 'created' => 800],
            ['id' => 'gpt-4.8-mini', 'created' => 500],
        ]);

        $this->getJson('/api/v1/assistant/models')->assertOk()->assertJsonPath('data.recommended', 'gpt-4.9-mini');
    }

    public function test_without_a_mini_model_the_newest_chat_model_is_recommended(): void
    {
        $this->fakeModels([
            ['id' => 'gpt-5.1', 'created' => 300],
            ['id' => 'o5-preview', 'created' => 500],
        ]);

        $this->getJson('/api/v1/assistant/models')->assertOk()->assertJsonPath('data.recommended', 'o5-preview');
    }

    public function test_an_empty_chat_catalogue_recommends_nothing(): void
    {
        $this->fakeModels([
            ['id' => 'text-embedding-3-small', 'created' => 900],
        ]);

        $this->getJson('/api/v1/assistant/models')->assertOk()
            ->assertJsonPath('data.recommended', null)
            ->assertJsonPath('data.models', []);
    }

    public function test_a_provider_failure_answers_502_without_copying_the_body_or_the_key(): void
    {
        Http::fake([
            'api.openai.test/v1/models' => Http::response(
                ['error' => ['message' => 'Incorrect API key provided: '.self::KEY]],
                401,
            ),
        ]);

        $response = $this->getJson('/api/v1/assistant/models')
            ->assertStatus(502)
            ->assertJsonPath('code', 'assistant_provider_error');

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString('Incorrect API key', $response->getContent());
    }

    public function test_an_unreachable_provider_answers_502(): void
    {
        Http::fake([
            'api.openai.test/v1/models' => Http::response('upstream unavailable', 500),
        ]);

        $response = $this->getJson('/api/v1/assistant/models')
            ->assertStatus(502)
            ->assertJsonPath('code', 'assistant_provider_error');

        $this->assertStringNotContainsString('upstream unavailable', $response->getContent());
    }

    // ---- the pure rules -------------------------------------------------------------------------

    public function test_the_catalogue_rules_are_pure_and_deterministic(): void
    {
        $this->assertTrue(ModelCatalogue::isChat('gpt-4.1-mini'));
        $this->assertTrue(ModelCatalogue::isChat('o4-mini'));
        $this->assertFalse(ModelCatalogue::isChat('gpt-4o-realtime-preview'));
        $this->assertFalse(ModelCatalogue::isChat('text-embedding-3-large'));

        $this->assertSame('gpt-4o-mini', ModelCatalogue::recommend([
            ['id' => 'gpt-4o-mini', 'created' => 1],
            ['id' => 'gpt-4.1', 'created' => 2],
        ]));
        // Ties on `created` are broken by id so the result never depends on provider order.
        $this->assertSame('gpt-a-mini', ModelCatalogue::recommend([
            ['id' => 'gpt-b-mini', 'created' => 5],
            ['id' => 'gpt-a-mini', 'created' => 5],
        ]));
        $this->assertNull(ModelCatalogue::recommend([]));
    }
}
