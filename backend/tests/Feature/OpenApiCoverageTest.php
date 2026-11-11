<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/architecture/openapi.yaml and the registered /api/v1 routes in
 * sync. Path parameter names are ignored ({id}, {payment}, ... all compare as
 * {}), methods are compared.
 *
 * Fix a failure by documenting the operation in openapi.yaml (preferred) or,
 * for a genuinely internal endpoint, by adding it to UNDOCUMENTED_ROUTES.
 */
class OpenApiCoverageTest extends TestCase
{
    /**
     * Routes intentionally absent from the public contract, as "method /path"
     * with normalised parameters. Empty on purpose: the webhook verification
     * endpoints are public and are documented too, so nothing is hidden.
     *
     * @var list<string>
     */
    private const UNDOCUMENTED_ROUTES = [];

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function test_every_api_route_is_documented(): void
    {
        $this->assertNotEmpty($this->routeOperations(), 'No /api/v1 routes were found.');

        $missing = array_values(array_diff(
            array_keys($this->routeOperations()),
            array_keys($this->documentedOperations()),
            self::UNDOCUMENTED_ROUTES,
        ));

        $this->assertSame(
            [],
            $missing,
            "Routes missing from docs/architecture/openapi.yaml:\n".implode("\n", $missing),
        );
    }

    public function test_every_documented_operation_has_a_route(): void
    {
        $stale = array_values(array_diff(
            array_keys($this->documentedOperations()),
            array_keys($this->routeOperations()),
        ));

        $this->assertSame(
            [],
            $stale,
            "openapi.yaml documents operations without a registered route:\n".implode("\n", $stale),
        );
    }

    public function test_allow_list_only_contains_existing_routes(): void
    {
        $unknown = array_values(array_diff(self::UNDOCUMENTED_ROUTES, array_keys($this->routeOperations())));

        $this->assertSame([], $unknown, 'Remove allow-listed routes that no longer exist.');
    }

    public function test_component_references_resolve(): void
    {
        $spec = $this->spec();
        $broken = [];

        array_walk_recursive($spec, function ($value, $key) use ($spec, &$broken) {
            if ($key !== '$ref' || ! is_string($value) || ! str_starts_with($value, '#/')) {
                return;
            }
            $node = $spec;
            foreach (explode('/', substr($value, 2)) as $segment) {
                if (! is_array($node) || ! array_key_exists($segment, $node)) {
                    $broken[] = $value;

                    return;
                }
                $node = $node[$segment];
            }
        });

        $this->assertSame([], array_values(array_unique($broken)), 'Unresolved $ref targets in openapi.yaml.');
    }

    /** @return array<string, true> */
    private function routeOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/v1/') && $uri !== '/api/v1') {
                continue;
            }

            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if (in_array($method, self::METHODS, true)) {
                    $operations[$method.' '.$this->normalise(substr($uri, strlen('/api/v1')))] = true;
                }
            }
        }

        ksort($operations);

        return $operations;
    }

    /** @return array<string, true> */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->spec()['paths'] ?? [] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (in_array($method, self::METHODS, true)) {
                    $operations[$method.' '.$this->normalise($path)] = true;
                }
            }
        }

        ksort($operations);

        return $operations;
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path('../docs/architecture/openapi.yaml'));
    }

    private function normalise(string $path): string
    {
        $path = preg_replace('/\{[^}]+\}/', '{}', '/'.trim($path, '/'));

        return $path === '' ? '/' : $path;
    }
}
