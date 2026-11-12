<?php

namespace App\Modules\Imports\Support;

/**
 * Collector for the outcome of one pass over a file: counters, a capped
 * preview, and the full row-level error list used for the report.
 */
final class ImportRun
{
    public int $total = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<array<string, mixed>> */
    public array $preview = [];

    /** @var list<array{row: int, field: ?string, code: string, message: string, params: array<string, mixed>}> */
    public array $errors = [];

    /** @var array<int, array<string, string>> raw cells of rows that failed, by row number */
    public array $rawErrorRows = [];

    /** @var array<string, mixed> data a definition wants stored on the job (e.g. created batch id) */
    public array $result = [];

    public function __construct(
        public readonly ImportContext $ctx,
        private readonly int $previewLimit = 50,
    ) {}

    /** @param  array<string, mixed>  $summary */
    public function created(int $row, array $summary): void
    {
        $this->created++;
        $this->record($row, 'create', $summary);
    }

    /** @param  array<string, mixed>  $summary */
    public function updated(int $row, array $summary): void
    {
        $this->updated++;
        $this->record($row, 'update', $summary);
    }

    /** @param  array<string, mixed>  $summary */
    public function skipped(int $row, array $summary, string $reason = 'duplicate'): void
    {
        $this->skipped++;
        $this->record($row, 'skip', $summary + ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $raw
     */
    public function error(int $row, string $code, string $message, ?string $field = null, array $params = [], array $raw = []): void
    {
        $this->errors[] = ['row' => $row, 'field' => $field, 'code' => $code, 'message' => $message, 'params' => $params];
        if ($raw !== []) {
            $this->rawErrorRows[$row] ??= $raw;
        }
        // One preview line per failing row (first error wins).
        $last = end($this->preview);
        if (count($this->preview) < $this->previewLimit && ($last === false || ($last['row'] ?? null) !== $row)) {
            $this->preview[] = ['row' => $row, 'action' => 'error', 'message' => $message, 'values' => array_map(fn ($v) => mb_substr((string) $v, 0, 80), $raw)];
        }
    }

    public function errorRowCount(): int
    {
        return count(array_unique(array_column($this->errors, 'row')));
    }

    /** @param  array<string, mixed>  $summary */
    private function record(int $row, string $action, array $summary): void
    {
        if (count($this->preview) < $this->previewLimit) {
            $this->preview[] = ['row' => $row, 'action' => $action, 'values' => $summary];
        }
    }

    /** Discard counters after an atomic rollback. */
    public function resetApplied(): void
    {
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;
    }
}
