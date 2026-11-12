<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportRun;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Row-by-row import: cast -> validate -> detect duplicates -> create/update.
 * Each row is applied in its own transaction so one bad row never blocks the
 * rest (the runner wraps everything in one transaction for on_error=abort).
 */
abstract class AbstractRowDefinition implements ImportDefinition
{
    use CastsRows;

    public function options(): array
    {
        return [];
    }

    public function supportsDuplicatePolicy(): bool
    {
        return true;
    }

    public function defaultPolicy(): string
    {
        return 'skip';
    }

    public function isAtomic(): bool
    {
        return false;
    }

    public function permissions(string $policy): array
    {
        return $policy === 'update'
            ? [$this->createPermission(), $this->updatePermission()]
            : [$this->createPermission()];
    }

    abstract protected function createPermission(): string;

    abstract protected function updatePermission(): string;

    /** Load whatever lookup state the duplicate check needs. */
    abstract protected function prepare(ImportContext $ctx): void;

    /** Normalised natural key for in-file and existing-record matching; '' = no key. */
    abstract protected function keyFor(array $values): string;

    /**
     * @return array{id: int, branch_id: ?int}|null
     */
    abstract protected function find(string $key): ?array;

    abstract protected function createRecord(array $values, ImportContext $ctx): void;

    abstract protected function updateRecord(int $id, array $values, ImportContext $ctx): void;

    /** @return array<string, mixed> columns shown in the preview grid */
    abstract protected function summary(array $values): array;

    /**
     * Cross-field checks after casting.
     *
     * @return list<array{field: ?string, code: string, message: string}>
     */
    protected function validateValues(array $values, ImportContext $ctx): array
    {
        return [];
    }

    public function process(ImportRun $run, iterable $rows): void
    {
        $ctx = $run->ctx;
        $this->prepare($ctx);
        $seen = [];

        foreach ($rows as ['row' => $n, 'data' => $data]) {
            [$values, $castErrors] = $this->castRow($data, $ctx);

            $errors = array_map(fn (array $e) => [
                'field' => $e['field'],
                'code' => $e['code'],
                'params' => $e['params'],
                'message' => $this->messageFor($e['field'], $e['code'], $e['params']),
            ], $castErrors);

            if ($errors === []) {
                foreach ($this->validateValues($values, $ctx) as $extra) {
                    $errors[] = $extra + ['params' => []];
                }
            }

            if ($errors !== []) {
                $this->report($run, $n, $errors, $data);

                continue;
            }

            $key = $this->keyFor($values);
            if ($key !== '' && isset($seen[$key])) {
                $this->report($run, $n, [[
                    'field' => null,
                    'code' => 'duplicate_in_file',
                    'message' => "Duplicate of row {$seen[$key]} in the same file.",
                    'params' => ['first' => $seen[$key]],
                ]], $data);

                continue;
            }
            if ($key !== '') {
                $seen[$key] = $n;
            }

            $match = $key !== '' ? $this->find($key) : null;

            if ($match !== null && $ctx->branch !== null && $match['branch_id'] !== $ctx->branch->id) {
                // Never reveal or touch a record that belongs to another branch.
                $this->report($run, $n, [[
                    'field' => null,
                    'code' => 'other_branch',
                    'message' => 'A record with this key already exists in another branch.',
                    'params' => [],
                ]], $data);

                continue;
            }

            if ($match !== null && $ctx->policy === 'skip') {
                $run->skipped($n, $this->summary($values));

                continue;
            }

            if ($match !== null && $ctx->policy === 'fail') {
                $this->report($run, $n, [[
                    'field' => null,
                    'code' => 'duplicate_exists',
                    'message' => 'A matching record already exists.',
                    'params' => [],
                ]], $data);

                continue;
            }

            try {
                if ($ctx->apply) {
                    DB::transaction(fn () => $match
                        ? $this->updateRecord($match['id'], $values, $ctx)
                        : $this->createRecord($values, $ctx));
                }
            } catch (DomainException $e) {
                $this->report($run, $n, [['field' => null, 'code' => 'apply_failed', 'message' => $e->getMessage(), 'params' => []]], $data);

                continue;
            } catch (QueryException) {
                $this->report($run, $n, [['field' => null, 'code' => 'save_failed', 'message' => 'The record could not be saved (constraint violation).', 'params' => []]], $data);

                continue;
            }

            $match ? $run->updated($n, $this->summary($values)) : $run->created($n, $this->summary($values));
        }
    }

    /**
     * @param  list<array{field: ?string, code: string, message: string, params: array<string, mixed>}>  $errors
     * @param  array<string, string>  $data
     */
    private function report(ImportRun $run, int $row, array $errors, array $data): void
    {
        foreach ($errors as $error) {
            $run->error($row, $error['code'], $error['message'], $error['field'] ?? null, $error['params'] ?? [], $data);
        }
    }
}
