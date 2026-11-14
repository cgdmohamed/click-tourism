<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GAP-CORE-04: a signed-in user lists and revokes their own devices, and nobody else's. */
class SessionsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_the_list_shows_only_my_login_tokens_and_marks_the_current_one(): void
    {
        $this->buildAccounting();
        $phone = $this->user->createToken('Phone')->plainTextToken;
        $this->user->createToken('mcp:Claude', ['mcp:read']);
        $other = User::factory()->create();
        $other->createToken('Stranger laptop');

        $rows = $this->withHeaders($this->bearer($phone))->getJson('/api/v1/auth/sessions')->assertOk()->json('data');

        $this->assertSame(['Phone'], array_column($rows, 'device'));
        $this->assertTrue($rows[0]['current']);
        $this->assertArrayNotHasKey('token', $rows[0]);
        $this->assertStringNotContainsString('Stranger', json_encode($rows));
    }

    public function test_revoking_a_device_signs_it_out_and_is_audited(): void
    {
        $this->buildAccounting();
        $phone = $this->user->createToken('Phone');
        $laptop = $this->user->createToken('Laptop')->plainTextToken;

        $this->withHeaders($this->bearer($laptop))->deleteJson('/api/v1/auth/sessions/'.$phone->accessToken->getKey())->assertOk()->assertJsonPath('data.current', false);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $phone->accessToken->getKey()]);
        $this->assertNotEmpty(AuditEvent::query()->where('action', 'auth.session_revoked')->get());
    }

    public function test_one_cannot_revoke_someone_elses_or_an_mcp_token(): void
    {
        $this->buildAccounting();
        $mine = $this->user->createToken('Laptop')->plainTextToken;
        $mcp = $this->user->createToken('mcp:Claude', ['mcp:read'])->accessToken;
        $stranger = User::factory()->create()->createToken('Stranger laptop')->accessToken;

        $this->withHeaders($this->bearer($mine))->deleteJson('/api/v1/auth/sessions/'.$stranger->getKey())->assertNotFound();
        $this->withHeaders($this->bearer($mine))->deleteJson('/api/v1/auth/sessions/'.$mcp->getKey())->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $stranger->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $mcp->getKey()]);
    }

    public function test_signing_out_the_other_devices_keeps_this_one_and_my_mcp_tokens(): void
    {
        $this->buildAccounting();
        $this->user->createToken('Phone');
        $this->user->createToken('Tablet');
        $mcp = $this->user->createToken('mcp:Claude', ['mcp:read'])->accessToken;
        $here = $this->user->createToken('Laptop');

        $this->withHeaders($this->bearer($here->plainTextToken))->deleteJson('/api/v1/auth/sessions')->assertOk()->assertJsonPath('data.revoked', 2);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $here->accessToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $mcp->getKey()]);
        $this->assertSame(1, $this->user->tokens()->where('name', '!=', 'mcp:Claude')->count());
    }

    public function test_the_routes_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/auth/sessions')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/sessions')->assertUnauthorized();
    }
}
