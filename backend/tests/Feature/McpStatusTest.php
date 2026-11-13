<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * GET /mcp/status (ADR-0018): MCP usage for the Settings screen - enabled flag, active/expired
 * token counts, last connection time and the most recently used tokens with their owner.
 * Never a token value, hash or ability list.
 */
class McpStatusTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->other = User::factory()->create();
        $this->other->branches()->attach($this->branch->id);

        foreach (['settings.view', 'mcp.use'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('settings.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->user, ['*']);
    }

    /** @return array{0: string, 1: PersonalAccessToken} plaintext token and row */
    private function mcpToken(string $name, ?User $owner = null, ?Carbon $expiresAt = null, ?Carbon $lastUsed = null): array
    {
        $created = ($owner ?? $this->user)->createToken('mcp:'.$name, ['mcp:read'], $expiresAt ?? now()->addHours(8));
        $token = $created->accessToken;
        if ($lastUsed !== null) {
            $token->forceFill(['last_used_at' => $lastUsed])->save();
        }

        return [$created->plainTextToken, $token];
    }

    public function test_it_needs_the_settings_view_permission(): void
    {
        $plain = User::factory()->create();
        $plain->branches()->attach($this->branch->id);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($plain, ['*']);

        $this->getJson('/api/v1/mcp/status')->assertStatus(403);
    }

    public function test_an_mcp_token_cannot_call_the_status_endpoint(): void
    {
        app(SettingService::class)->set('mcp', 'enabled', 'true');
        [$token] = $this->mcpToken('office');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mcp/status', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'mcp_scope_denied');
    }

    public function test_an_empty_installation_reports_the_flag_and_empty_counts(): void
    {
        $this->getJson('/api/v1/mcp/status')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.tokens.active', 0)
            ->assertJsonPath('data.tokens.expired', 0)
            ->assertJsonPath('data.tokens.total', 0)
            ->assertJsonPath('data.last_connection_at', null)
            ->assertJsonPath('data.recent', []);

        app(SettingService::class)->set('mcp', 'enabled', 'true');
        $this->getJson('/api/v1/mcp/status')->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_it_counts_active_and_expired_tokens_across_users(): void
    {
        $this->mcpToken('office');
        $this->mcpToken('laptop', $this->other);
        $this->mcpToken('old', null, now()->subMinute());

        $this->getJson('/api/v1/mcp/status')->assertOk()
            ->assertJsonPath('data.tokens.active', 2)
            ->assertJsonPath('data.tokens.expired', 1)
            ->assertJsonPath('data.tokens.total', 3);
    }

    public function test_it_lists_recent_connections_newest_first_with_nulls_last(): void
    {
        $this->mcpToken('never-used');
        [, $older] = $this->mcpToken('older', null, null, now()->subHours(2));
        [, $newer] = $this->mcpToken('newer', $this->other, null, now()->subHour());

        $during = $this->getJson('/api/v1/mcp/status')->assertOk();

        $this->assertSame(
            ['newer', 'older', 'never-used'],
            array_column($during->json('data.recent'), 'name'),
        );
        $this->assertSame($newer->last_used_at->toIso8601String(), $during->json('data.last_connection_at'));

        // A tie on last_used_at falls back to the newest token id first.
        $tie = now()->subMinutes(5);
        $this->mcpToken('tie-a', null, null, $tie);
        [, $tieB] = $this->mcpToken('tie-b', null, null, $tie);
        $recent = $this->getJson('/api/v1/mcp/status')->assertOk()->json('data.recent');
        $this->assertSame('tie-b', $recent[0]['name']);
        $this->assertSame((int) $tieB->getKey(), $recent[0]['id']);
    }

    public function test_recent_rows_carry_the_owner_and_timestamps_but_no_secret_or_abilities(): void
    {
        [$token, $row] = $this->mcpToken('office', $this->other, null, now()->subMinutes(3));

        $response = $this->getJson('/api/v1/mcp/status')->assertOk();
        $entry = $response->json('data.recent.0');

        $this->assertSame((int) $row->getKey(), $entry['id']);
        $this->assertSame('office', $entry['name']);
        $this->assertSame('active', $entry['state']);
        $this->assertSame($this->other->public_id, $entry['user']['id']);
        $this->assertSame($this->other->name, $entry['user']['name']);
        $this->assertSame($this->other->email, $entry['user']['email']);
        $this->assertSame($row->created_at->toIso8601String(), $entry['created_at']);
        $this->assertSame($row->last_used_at->toIso8601String(), $entry['last_used_at']);
        $this->assertSame($row->expires_at->toIso8601String(), $entry['expires_at']);

        $this->assertArrayNotHasKey('abilities', $entry);
        $this->assertArrayNotHasKey('token', $entry);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('mcp:read', $response->getContent());
    }

    public function test_an_expired_recent_row_says_expired_and_only_ten_are_returned(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->mcpToken('token-'.$i, null, null, now()->subMinutes($i + 5));
        }
        $this->mcpToken('expired', null, now()->subMinute(), now()->subMinute());

        $recent = $this->getJson('/api/v1/mcp/status')->assertOk()->json('data.recent');

        $this->assertCount(10, $recent);
        $this->assertSame('expired', $recent[0]['name']);
        $this->assertSame('expired', $recent[0]['state']);
        $this->assertSame(1, $this->getJson('/api/v1/mcp/status')->json('data.tokens.expired'));
    }
}
