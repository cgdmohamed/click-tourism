<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Definitions\AccountImport;
use App\Modules\Imports\Definitions\CustomerImport;
use App\Modules\Imports\Definitions\EmployeeImport;
use App\Modules\Imports\Definitions\ImportDefinition;
use App\Modules\Imports\Definitions\OpeningBalanceImport;
use App\Modules\Imports\Definitions\SupplierImport;
use App\Modules\Imports\Definitions\TourPackageImport;
use App\Modules\Imports\Support\ImportFileException;
use Illuminate\Contracts\Container\Container;

/** Catalogue of importable entities. Add a definition here to expose a new template. */
class ImportRegistry
{
    private const DEFINITIONS = [
        CustomerImport::class,
        SupplierImport::class,
        EmployeeImport::class,
        TourPackageImport::class,
        AccountImport::class,
        OpeningBalanceImport::class,
    ];

    /** @var array<string, ImportDefinition>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container) {}

    /** @return array<string, ImportDefinition> */
    public function all(): array
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach (self::DEFINITIONS as $class) {
                $definition = $this->container->make($class);
                $this->resolved[$definition->entity()] = $definition;
            }
        }

        return $this->resolved;
    }

    public function get(string $entity): ImportDefinition
    {
        return $this->all()[$entity]
            ?? throw new ImportFileException('unknown_entity', "There is no import template for '{$entity}'.", [], 404);
    }
}
