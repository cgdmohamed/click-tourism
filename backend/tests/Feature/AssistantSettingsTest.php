<?php

namespace Tests\Feature;

use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\OutputSanitizer;
use App\Modules\Assistant\Support\Redactor;
use App\Modules\Assistant\Support\SchemaValidator;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Assistant settings through the Settings API, role permissions, flags and the small helpers. */
class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function putSetting(string $key, string $value)
    {
        return $this->putJson('/api/v1/settings', ['settings' => [['group' => 'assistant', 'key' => $key, 'value' => $value]]]);
    }

    public static function invalid(): array
    {
        return [
            'enabled' => ['enabled', 'yes'],
            'redact' => ['redact_pii', '1'],
            'provider' => ['provider', 'anthropic'],
            'model spaces' => ['model', 'gpt 4'],
            'model empty' => ['model', ''],
            'tool calls zero' => ['max_tool_calls_per_turn', '0'],
            'tool calls many' => ['max_tool_calls_per_turn', '11'],
            'tokens low' => ['max_output_tokens', '10'],
            'budget text' => ['daily_token_budget', '10k'],
            'budget low' => ['daily_token_budget', '10'],
            'rate high' => ['rate_limit_per_minute', '1000'],
            'privacy http' => ['privacy_url', 'http://example.com/p'],
            'privacy script' => ['privacy_url', 'https://x.test/"onmouseover=1'],
            'acceptance text' => ['data_processing_accepted_at', 'yes I accept'],
            'acceptance date only' => ['data_processing_accepted_at', '2026-11-13'],
            'acceptance too long' => ['data_processing_accepted_at', '2026-11-13T09:00:00+00:00 extra text here'],
            'api key' => ['api_key', 'sk-secret'],
            'openai key' => ['openai_api_key', 'sk-secret'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_values_and_unknown_keys_are_refused(string $key, string $value): void
    {
        $this->adminUser();
        $this->putSetting($key, $value)->assertStatus(422);
    }

    public function test_valid_values_are_stored_and_the_key_is_not_a_setting(): void
    {
        $this->adminUser();
        // Enabling needs the acceptance first, so it is stored before the switch (ADR-0018).
        foreach ([
            ['data_processing_accepted_at', '2026-11-13T09:00:00+00:00'],
            ['enabled', 'true'], ['provider', 'openai'], ['model', 'gpt-4o-mini'], ['max_tool_calls_per_turn', '6'],
            ['max_output_tokens', '1024'], ['daily_token_budget', '50000'], ['rate_limit_per_minute', '20'],
            ['redact_pii', 'true'], ['privacy_url', 'https://example.com/privacy'], ['privacy_url', ''],
            ['data_processing_accepted_at', '2026-11-13T09:00:00.123456Z'],
            ['data_processing_accepted_at', ''],
        ] as [$key, $value]) {
            $this->putSetting($key, $value)->assertOk();
        }
    }

    public function test_enabling_the_assistant_requires_the_data_processing_acceptance(): void
    {
        $user = $this->adminUser();

        $rejected = $this->putSetting('enabled', 'true')->assertStatus(422);
        $this->assertSame(
            'Accept the data-processing notice before enabling the assistant.',
            $rejected->json('errors')['settings.0.value'][0],
        );
        $this->assertFalse(app(ProviderManager::class)->enabled());
        $this->assertNull(Setting::query()->where('group', 'assistant')->where('key', 'enabled')->first());

        // Stored acceptance first, then the switch.
        $this->putSetting('data_processing_accepted_at', '2026-11-13T09:00:00+00:00')->assertOk();
        $this->putSetting('enabled', 'true')->assertOk();
        $this->assertTrue(app(ProviderManager::class)->enabled());

        // Accepted earlier in the same payload is enough too.
        $this->putSetting('enabled', 'false')->assertOk();
        $this->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'assistant', 'key' => 'enabled', 'value' => 'true'],
            ['group' => 'assistant', 'key' => 'data_processing_accepted_at', 'value' => '2026-11-13T10:00:00+00:00'],
        ]])->assertOk();
        $this->assertTrue(app(ProviderManager::class)->enabled());

        // Switching it off never needs an acceptance.
        $this->putSetting('data_processing_accepted_at', '')->assertOk();
        $this->putSetting('enabled', 'false')->assertOk();

        // Audit rows carry only the new state (no PII, no provider details).
        $events = AuditEvent::query()->where('action', 'assistant.enabled_changed')->orderBy('id')->get();
        $this->assertSame([true, false, true, false], $events->map(fn (AuditEvent $e) => $e->after['enabled'])->all());
        $this->assertSame([$user->id], $events->pluck('actor_id')->unique()->values()->all());
    }

    public function test_only_roles_with_settings_update_can_change_them(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($viewer, ['*']);

        $this->putSetting('enabled', 'true')->assertStatus(403);
    }

    public function test_assistant_use_is_granted_to_admin_and_finance_manager_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'assistant.use'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['admin', 'finance_manager'], $holders);
    }

    public function test_flags_expose_only_non_sensitive_assistant_state(): void
    {
        $this->adminUser();
        config()->set('services.openai.key', 'sk-flag-test-key');
        $this->getJson('/api/v1/feature-flags')->assertOk()
            ->assertJsonPath('data.assistant_enabled', false)
            ->assertJsonPath('data.assistant_provider', null)
            ->assertJsonPath('data.assistant_key_configured', false) // provider is still 'none'
            ->assertJsonPath('data.assistant_accepted', false)
            ->assertJsonPath('data.assistant_model', 'gpt-4o-mini');

        $this->putSetting('enabled', 'true')->assertStatus(422); // acceptance first (ADR-0018)
        $this->putSetting('data_processing_accepted_at', '2026-11-13T09:00:00+00:00')->assertOk();
        $this->putSetting('enabled', 'true')->assertOk();
        $this->putSetting('provider', 'openai')->assertOk();
        $this->putSetting('model', 'gpt-4.1-mini')->assertOk();
        $this->putSetting('privacy_url', 'https://example.com/privacy')->assertOk();

        $flags = $this->getJson('/api/v1/feature-flags')->assertOk()
            ->assertJsonPath('data.assistant_enabled', true)
            ->assertJsonPath('data.assistant_provider', 'openai')
            ->assertJsonPath('data.assistant_key_configured', true)
            ->assertJsonPath('data.assistant_accepted', true)
            ->assertJsonPath('data.assistant_model', 'gpt-4.1-mini')
            ->assertJsonPath('data.assistant_redact_pii', true)
            ->assertJsonPath('data.assistant_privacy_url', 'https://example.com/privacy');
        $this->assertStringNotContainsString('sk-flag-test-key', $flags->getContent());

        config()->set('services.openai.key', '');
        $this->getJson('/api/v1/feature-flags')
            ->assertJsonPath('data.assistant_enabled', false)
            ->assertJsonPath('data.assistant_key_configured', false)
            ->assertJsonPath('data.assistant_accepted', true);
    }

    // ---- helpers ------------------------------------------------------------------------------

    public function test_redactor_masks_phones_emails_and_ids_but_not_amounts_dates_or_public_ids(): void
    {
        $redactor = new Redactor;
        $text = 'Call +20 100 123 4567, 01001234567, ٠١٠٠١٢٣٤٥٦٧, a.b@x.co, tax 123-456-789. Total 1500000 on 2026-11-13, id 01M79ATZ3CTGRCVYNGXY5PHWFS, inv_abcdefgh.';
        $clean = $redactor->redact($text);

        foreach (['4567', '01001234567', '٠١٠٠١٢٣٤٥٦٧', 'a.b@x.co', '123-456-789'] as $leak) {
            $this->assertStringNotContainsString($leak, $clean);
        }
        foreach (['1500000', '2026-11-13', '01M79ATZ3CTGRCVYNGXY5PHWFS', 'inv_abcdefgh'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
        $this->assertSame($text, $redactor->restore($clean));
        $this->assertSame('x', (new Redactor(false))->redact('x'));
        $this->assertSame('01001234567', (new Redactor(false))->redact('01001234567'));
    }

    public function test_output_sanitizer_keeps_plain_text_only(): void
    {
        $sanitizer = new OutputSanitizer;
        $clean = $sanitizer->clean("Hello <b>x</b><script>alert(1)</script>\u{200B} ![i](https://e.test/a.png) [t](javascript:alert(1)) ftp://e.test data:text/html,hi //e.test/x end", 1000);

        $this->assertStringStartsWith('Hello x', $clean);
        $this->assertStringEndsWith('end', $clean);
        foreach (['<', 'e.test', 'javascript', 'script', "\u{200B}", ']('] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }
        $this->assertStringContainsString('i t', $clean);
        $this->assertSame(4, mb_strlen($sanitizer->clean('abcdefgh', 3)));
    }

    public function test_schema_validator(): void
    {
        $validator = new SchemaValidator;
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => [
            'id' => ['type' => 'string', 'minLength' => 8, 'pattern' => '^[A-Za-z0-9_-]+$'],
            'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'd' => ['type' => 'string', 'format' => 'date'],
            'e' => ['enum' => ['a', 'b']],
            'b' => ['type' => 'boolean'],
        ]];

        $this->assertSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'n' => 3, 'd' => '2026-02-28', 'e' => 'a', 'b' => true]));
        $this->assertNotSame([], $validator->errors($schema, []));
        $this->assertNotSame([], $validator->errors($schema, ['id' => '../x/../y']));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'n' => '3']));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'n' => 6]));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'd' => '2026-02-30']));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'e' => 'A']));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'x' => 1]));
        $this->assertNotSame([], $validator->errors($schema, ['id' => 'abcdefgh', 'b' => 'true']));
        $this->assertNotSame([], $validator->errors($schema, ['a', 'b']));
    }
}
