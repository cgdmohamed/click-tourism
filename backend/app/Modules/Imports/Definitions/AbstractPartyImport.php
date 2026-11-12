<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Imports\Support\ImportContext;
use Illuminate\Support\Facades\DB;

/**
 * Customers and suppliers have no unique business code, so duplicates are
 * detected inside the importing branch by the first available of: tax id,
 * email, phone, English name. Existing records are indexed under every key
 * they carry so a re-import of an exported file matches whichever it uses.
 */
abstract class AbstractPartyImport extends AbstractRowDefinition
{
    /** @var array<string, array{id: int, branch_id: ?int}> */
    private array $index = [];

    abstract protected function table(): string;

    abstract protected function hasTaxId(): bool;

    protected function prepare(ImportContext $ctx): void
    {
        $this->index = [];
        if ($ctx->branch === null) {
            return;
        }

        $columns = ['id', 'branch_id', 'email', 'phone', 'name_en'];
        if ($this->hasTaxId()) {
            $columns[] = 'tax_id';
        }

        DB::table($this->table())->where('branch_id', $ctx->branch->id)->orderBy('id')
            ->select($columns)->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $entry = ['id' => (int) $row->id, 'branch_id' => (int) $row->branch_id];
                    foreach ($this->keysOf((array) $row) as $key) {
                        $this->index[$key] ??= $entry;
                    }
                }
            });
    }

    protected function keyFor(array $values): string
    {
        return $this->keysOf($values)[0];
    }

    protected function find(string $key): ?array
    {
        return $this->index[$key] ?? null;
    }

    /**
     * Candidate keys in priority order; the last one (name) always exists.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function keysOf(array $record): array
    {
        $keys = [];
        if ($this->hasTaxId() && ! empty($record['tax_id'])) {
            $keys[] = 'tax:'.mb_strtolower(trim((string) $record['tax_id']));
        }
        if (! empty($record['email'])) {
            $keys[] = 'email:'.mb_strtolower(trim((string) $record['email']));
        }
        if (! empty($record['phone'])) {
            $keys[] = 'phone:'.preg_replace('/\D+/', '', (string) $record['phone']);
        }
        $keys[] = 'name:'.mb_strtolower(trim((string) ($record['name_en'] ?? '')));

        return $keys;
    }
}
