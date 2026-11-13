<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Support\SchemaValidator;
use RuntimeException;

/**
 * The tools the assistant may call: exactly the stage 1-2 read catalogue of the MCP gateway.
 *
 * The single source is mcp-gateway/src/schemas.js. Its snapshot lives in
 * resources/assistant/tools.json (`npm run export:catalogue` in mcp-gateway) and
 * AssistantCatalogueParityTest fails when the snapshot, the gateway and McpReadOnly::ALLOWED
 * diverge. Nothing here can name a route that is not in that catalogue.
 */
class ToolCatalogue
{
    /** Route first segment => singular entity used in citations and links. */
    private const ENTITIES = [
        'search' => 'search',
        'customers' => 'customer',
        'suppliers' => 'supplier',
        'bookings' => 'booking',
        'invoices' => 'invoice',
        'payments' => 'payment',
        'expenses' => 'expense',
        'leads' => 'lead',
        'reports' => 'report',
    ];

    /** @var array<string, array{name: string, description: string, route: string, report: bool, inputSchema: array<string, mixed>}>|null */
    private ?array $tools = null;

    public function __construct(private readonly SchemaValidator $validator) {}

    /** @return array<string, array{name: string, description: string, route: string, report: bool, inputSchema: array<string, mixed>}> */
    public function all(): array
    {
        if ($this->tools === null) {
            $file = resource_path('assistant/tools.json');
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (! is_array($decoded) || ! is_array($decoded['tools'] ?? null)) {
                throw new RuntimeException('resources/assistant/tools.json is missing or invalid.');
            }

            $this->tools = [];
            foreach ($decoded['tools'] as $tool) {
                // The model never picks the branch: the session branch (X-Branch-Id) is used for every call.
                unset($tool['inputSchema']['properties']['branch_id']);
                $this->tools[$tool['name']] = $tool;
            }
        }

        return $this->tools;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    /** @return array{name: string, description: string, route: string, report: bool, inputSchema: array<string, mixed>}|null */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** OpenAI-style function definitions for the model. @return list<array<string, mixed>> */
    public function modelTools(): array
    {
        return array_values(array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['inputSchema'],
            ],
        ], $this->all()));
    }

    /** @return list<string> */
    public function errors(string $name, mixed $args): array
    {
        return $this->validator->errors($this->all()[$name]['inputSchema'], $args);
    }

    /** API path below /api/v1 with `{id}` filled from the validated id (it matches ^[A-Za-z0-9_-]+$). */
    public function path(string $name, array $args): string
    {
        return str_replace('{id}', rawurlencode((string) ($args['id'] ?? '')), $this->all()[$name]['route']);
    }

    /** Query parameters for the GET request; booleans travel as 1/0 and per_page defaults like the gateway. @return array<string, string> */
    public function query(string $name, array $args): array
    {
        $tool = $this->all()[$name];
        $query = [];
        foreach (array_keys($tool['inputSchema']['properties']) as $key) {
            if ($key === 'id' || ! array_key_exists($key, $args)) {
                continue;
            }
            $query[$key] = is_bool($args[$key]) ? ($args[$key] ? '1' : '0') : (string) $args[$key];
        }
        $default = $tool['inputSchema']['properties']['per_page']['default'] ?? null;
        if ($default !== null && ! isset($query['per_page'])) {
            $query['per_page'] = (string) $default;
        }

        return $query;
    }

    public function entity(string $name): string
    {
        $segment = explode('/', ltrim($this->all()[$name]['route'], '/'))[0];

        return self::ENTITIES[$segment] ?? 'record';
    }

    /** Get-by-id tools name one record: the id argument is the cited record. */
    public function isRecordTool(string $name): bool
    {
        return str_contains($this->all()[$name]['route'], '{id}');
    }
}
