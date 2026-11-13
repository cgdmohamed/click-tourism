<?php

namespace Tests\Feature;

use App\Http\Middleware\McpReadOnly;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Role x tool matrix for the MCP read tools (AT-6, blueprint sections 4 and 11).
 *
 * One table drives everything: the allowlist of McpReadOnly, the permission the underlying
 * route enforces, the permission-matrix.md document, and a 403/200 check per tool. A tool added
 * to the allowlist without a row here (or a row whose permission differs from the route or the
 * document) fails the build.
 */
class McpToolMatrixTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    /** Roles in the column order of docs/product/permission-matrix.md. */
    private const ROLES = ['admin', 'finance_manager', 'senior_accountant', 'accountant', 'supervisor', 'sales', 'reservations', 'cashier', 'viewer'];

    /**
     * tool => [route uri, permission enforced by the route (null = per-group filtering), query, fixture placeholder]
     * Placeholders in the uri ({customer}, {booking}, {invoice}) are filled from fixtures.
     */
    public const TABLE = [
        'erp_search' => ['api/v1/search', null, 'q=Matrix', null],
        'erp_customers_list' => ['api/v1/customers', 'customers.view', '', null],
        'erp_customer_get' => ['api/v1/customers/{customer}', 'customers.view', '', 'customer'],
        'erp_suppliers_list' => ['api/v1/suppliers', 'suppliers.view', '', null],
        'erp_bookings_list' => ['api/v1/bookings', 'bookings.view', '', null],
        'erp_booking_get' => ['api/v1/bookings/{booking}', 'bookings.view', '', 'booking'],
        'erp_invoices_list' => ['api/v1/invoices', 'invoices.view', '', null],
        'erp_invoice_get' => ['api/v1/invoices/{invoice}', 'invoices.view', '', 'invoice'],
        'erp_payments_list' => ['api/v1/payments', 'payments.view', '', null],
        'erp_expenses_list' => ['api/v1/expenses', 'expenses.view', '', null],
        'erp_leads_list' => ['api/v1/leads', 'leads.view', '', null],
        'erp_party_statement' => ['api/v1/reports/party-statement', 'reports.view', 'party_type=customer&party_id={customer}&from={from}&to={to}', null],
        'erp_aging' => ['api/v1/reports/aging', 'reports.view', 'type=ar&as_of={to}', null],
        'erp_trial_balance' => ['api/v1/reports/trial-balance', 'reports.view', 'from={from}&to={to}', null],
        'erp_income_statement' => ['api/v1/reports/income-statement', 'reports.view', 'from={from}&to={to}', null],
    ];

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        app(SettingService::class)->set('mcp', 'enabled', 'true');

        $permissions = ['mcp.use', 'bookings.view', 'invoices.view', 'customers.view', 'leads.view', 'reports.view', 'payments.view', 'expenses.view', 'suppliers.view'];
        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customer = $this->customer();
        $customer->update(['name_en' => 'Matrix Customer']);
        $owner = User::factory()->create();
        $owner->branches()->attach($this->branch->id);

        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => 'EGP',
            'fx_rate' => '1',
            'items' => [['service_type' => 'hotel', 'description' => 'Hotel', 'quantity' => 1, 'unit_cost_minor' => 0, 'unit_sell_minor' => 100000]],
        ], $owner->id);

        $invoice = app(InvoiceService::class)->create([
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('4100')->public_id, 'description' => 'Tour package sale', 'credit_minor' => 100000]],
        ], $this->branch, $owner->id);

        $this->fixtures = [
            '{customer}' => $customer->public_id,
            '{booking}' => $booking->public_id,
            '{invoice}' => Invoice::query()->whereKey($invoice->id)->value('public_id'),
            '{from}' => now()->startOfMonth()->toDateString(),
            '{to}' => now()->endOfMonth()->toDateString(),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function tools(): array
    {
        return array_combine(array_keys(self::TABLE), array_map(fn ($tool) => [$tool], array_keys(self::TABLE)));
    }

    private function url(string $tool): string
    {
        [$uri, , $query] = self::TABLE[$tool];

        return strtr('/'.$uri.($query !== '' ? '?'.$query : ''), $this->fixtures);
    }

    /** A user in the branch holding exactly the given permissions, and an MCP token for them. */
    private function tokenFor(array $permissions): string
    {
        $user = User::factory()->create();
        $user->branches()->attach($this->branch->id);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        return $user->createToken('mcp:matrix', ['mcp:read'], now()->addHour())->plainTextToken;
    }

    private function viaToken(string $token, string $url)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson($url);
    }

    #[DataProvider('tools')]
    public function test_a_token_without_the_underlying_permission_gets_403_and_with_it_gets_200(string $tool): void
    {
        [, $permission] = self::TABLE[$tool];

        if ($permission === null) {
            // erp_search filters per group: no permission means no hits, the right one reveals them.
            $this->viaToken($this->tokenFor([]), $this->url($tool))->assertOk()->assertJsonPath('data.total', 0);
            $hits = $this->viaToken($this->tokenFor(['customers.view']), $this->url($tool))->assertOk();
            $this->assertGreaterThan(0, $hits->json('data.total'));

            return;
        }

        // Refused by the route's permission check, not by the MCP scope or the feature flag.
        $denied = $this->viaToken($this->tokenFor([]), $this->url($tool))->assertStatus(403);
        $this->assertNotContains($denied->json('code'), ['mcp_scope_denied', 'mcp_disabled']);

        // Every OTHER permission in the table must not unlock this tool.
        $others = array_values(array_diff(array_unique(array_filter(array_column(self::TABLE, 1))), [$permission]));
        $this->viaToken($this->tokenFor($others), $this->url($tool))->assertStatus(403);

        $this->viaToken($this->tokenFor([$permission]), $this->url($tool))->assertOk();
    }

    public function test_the_allowlist_and_the_table_describe_exactly_the_same_tools(): void
    {
        $fromTable = [];
        foreach (self::TABLE as $tool => [$uri]) {
            $fromTable[$uri] = $tool;
        }
        $allowed = McpReadOnly::ALLOWED;
        unset($allowed['api/v1/mcp/context']);

        ksort($fromTable);
        ksort($allowed);
        $this->assertSame($fromTable, $allowed);
        $this->assertSame(McpReadOnly::CONTEXT_TOOL, McpReadOnly::ALLOWED['api/v1/mcp/context']);
        $this->assertSame(15, count(self::TABLE));
    }

    public function test_the_permission_in_the_table_is_the_one_the_route_enforces(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $routes[$route->uri()] = $route->gatherMiddleware();
            }
        }

        foreach (self::TABLE as $tool => [$uri, $permission]) {
            $this->assertArrayHasKey($uri, $routes, "{$tool}: no GET route {$uri}");
            $enforced = array_values(array_filter($routes[$uri], fn ($m) => is_string($m) && str_starts_with($m, 'permission:')));
            $this->assertSame($permission === null ? [] : ['permission:'.$permission], $enforced, "{$tool}: route permission differs from the table");
        }
    }

    public function test_the_permission_matrix_document_matches_the_seeded_roles_for_every_tool_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $doc = (string) file_get_contents(base_path('../docs/product/permission-matrix.md'));

        $permissions = array_unique(array_merge(array_filter(array_column(self::TABLE, 1)), ['mcp.use', 'mcp.admin']));
        foreach ($permissions as $permission) {
            $this->assertSame(1, preg_match('/^\| `'.preg_quote($permission, '/').'` \|(.+)\|\s*$/m', $doc, $m), "{$permission} is missing from permission-matrix.md");
            $cells = array_map('trim', explode('|', $m[1]));
            $documented = [];
            foreach (self::ROLES as $i => $role) {
                if (($cells[$i] ?? '') === '✓') {
                    $documented[] = $role;
                }
            }
            $actual = Role::query()->whereIn('name', self::ROLES)->get()
                ->filter(fn (Role $role) => $role->hasPermissionTo($permission))
                ->pluck('name')->all();
            sort($documented);
            sort($actual);
            $this->assertSame($actual, $documented, "{$permission}: permission-matrix.md differs from the seeder");
        }
    }

    public function test_every_role_reaches_exactly_the_tools_its_permissions_allow(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (self::ROLES as $roleName) {
            $user = User::factory()->create();
            $user->branches()->attach($this->branch->id);
            $user->assignRole($roleName);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->app['auth']->forgetGuards();
            $token = $user->createToken('mcp:matrix', ['mcp:read'], now()->addHour())->plainTextToken;

            foreach (self::TABLE as $tool => [, $permission]) {
                if ($permission === null) {
                    continue;
                }
                $expected = $user->can($permission) ? 200 : 403;
                $this->viaToken($token, $this->url($tool))->assertStatus($expected, "{$roleName} x {$tool}");
            }
        }
    }
}
