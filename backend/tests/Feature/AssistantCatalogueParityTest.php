<?php

namespace Tests\Feature;

use App\Http\Middleware\McpReadOnly;
use App\Modules\Assistant\Services\ToolCatalogue;
use App\Modules\Assistant\Support\SchemaValidator;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The assistant must offer exactly the tools of the MCP gateway (mcp-gateway/src/schemas.js is
 * the single source) and nothing else. Fix a failure here by running
 * `npm run export:catalogue` in mcp-gateway, never by editing tools.json by hand.
 */
class AssistantCatalogueParityTest extends TestCase
{
    private function snapshot(): array
    {
        return json_decode((string) file_get_contents(resource_path('assistant/tools.json')), true)['tools'];
    }

    public function test_the_snapshot_equals_what_the_gateway_exports_now(): void
    {
        $script = base_path('../mcp-gateway/scripts/export-catalogue.js');
        $node = (new ExecutableFinder)->find('node');
        if ($node === null || ! is_file($script) || ! is_dir(dirname($script, 2).'/node_modules')) {
            $this->markTestSkipped('The mcp-gateway sources or node are not available in this environment.');
        }

        $process = new Process([$node, $script]);
        $process->mustRun();
        $fresh = json_decode($process->getOutput(), true)['tools'];

        $this->assertSame($fresh, $this->snapshot(), 'backend/resources/assistant/tools.json is stale: run `npm run export:catalogue` in mcp-gateway.');
    }

    public function test_tool_names_and_routes_equal_the_mcp_allowlist(): void
    {
        $fromSnapshot = [];
        foreach ($this->snapshot() as $tool) {
            // gateway route '/customers/{id}' <-> allowlist uri 'api/v1/customers/{customer}'
            $fromSnapshot[$tool['name']] = preg_replace('#\{id\}#', '{}', 'api/v1'.$tool['route']);
        }
        $allowed = McpReadOnly::ALLOWED;
        unset($allowed['api/v1/mcp/context']);
        $fromAllowlist = [];
        foreach ($allowed as $uri => $name) {
            $fromAllowlist[$name] = preg_replace('#\{[a-z_]+\}#', '{}', $uri);
        }

        ksort($fromSnapshot);
        ksort($fromAllowlist);
        $this->assertSame($fromAllowlist, $fromSnapshot);
    }

    public function test_every_tool_maps_to_a_registered_get_route_and_only_uses_supported_schema_keywords(): void
    {
        $get = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $get[] = preg_replace('#\{[a-z_]+\}#', '{}', $route->uri());
            }
        }

        $catalogue = app(ToolCatalogue::class);
        $this->assertCount(15, $catalogue->all());
        foreach ($catalogue->all() as $tool) {
            $this->assertContains(preg_replace('#\{id\}#', '{}', 'api/v1'.$tool['route']), $get, $tool['name']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
            $this->assertArrayNotHasKey('branch_id', $tool['inputSchema']['properties'], 'the model must not pick the branch');
            $this->assertKeywordsSupported($tool['inputSchema'], $tool['name']);
        }
    }

    private function assertKeywordsSupported(array $schema, string $where): void
    {
        foreach (array_keys($schema) as $keyword) {
            $this->assertContains($keyword, SchemaValidator::SUPPORTED_KEYWORDS, "{$where}: unsupported schema keyword {$keyword}");
        }
        foreach ($schema['properties'] ?? [] as $name => $child) {
            $this->assertKeywordsSupported($child, $where.'.'.$name);
        }
    }

    public function test_no_write_tool_exists(): void
    {
        foreach ($this->snapshot() as $tool) {
            $this->assertMatchesRegularExpression('/^erp_[a-z_]+$/', $tool['name']);
            $this->assertSame([], array_intersect(explode('_', $tool['name']), ['create', 'update', 'delete', 'post', 'approve', 'send', 'pay', 'export', 'write', 'set']));
        }
    }
}
