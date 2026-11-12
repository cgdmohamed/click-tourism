<?php

namespace App\Modules\Reports\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Reports\Support\ReportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Shared delivery step of every report export: validates format/language,
 * enforces size caps, writes the provenance audit event and builds the
 * download response. Permission (`reports.export`), branch scope and
 * throttling are enforced by the route and the calling controller.
 */
class ReportExporter
{
    public function __construct(
        private readonly ReportDocument $documents,
        private readonly ReportCsv $csv,
        private readonly AuditService $audit,
    ) {}

    /** @return array{format: string, language: string} */
    public function options(Request $request): array
    {
        $options = $request->validate([
            'format' => ['nullable', 'in:csv,xlsx,pdf'],
            'language' => ['nullable', 'in:ar,en,bilingual'],
        ]);

        return [
            'format' => $options['format'] ?? 'csv',
            'language' => $options['language'] ?? 'bilingual',
        ];
    }

    /**
     * @param  array{format: string, language: string}  $options
     * @param  array<string, mixed>  $filters  Non-personal report parameters recorded in the audit event
     * @param  array<int, ReportRow|array<int, mixed>>  $rows
     * @param  array{period?: ?string, currency?: ?string}  $meta
     */
    public function respond(
        array $options,
        ?Branch $branch,
        ?int $actorId,
        string $report,
        array $filters,
        array $rows,
        string $basename,
        string $title,
        array $meta = [],
    ): Response {
        $format = $options['format'];
        $language = $options['language'];

        if ($format === 'pdf' && count($rows) > ReportDocument::PDF_MAX_ROWS) {
            throw ValidationException::withMessages([
                'format' => ['Too many rows for a PDF ('.count($rows).'; max '.ReportDocument::PDF_MAX_ROWS.'). Use CSV or XLSX, or narrow the filters.'],
            ]);
        }

        // Provenance: who exported what, in which format, for which branch scope. No party names or amounts.
        $this->audit->log('report.exported', null, null, $filters + [
            'report' => $report,
            'format' => $format,
            'language' => $language,
            'branch' => $branch?->public_id,
            'rows' => count($rows),
        ], $actorId);

        $meta['branch'] = $branch?->toApiArray();

        [$content, $contentType] = match ($format) {
            'xlsx' => [$this->documents->xlsx($rows, $title), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [$this->documents->pdf($rows, $title, $language, $meta), 'application/pdf'],
            default => [$this->csv->build($rows), 'text/csv; charset=UTF-8'],
        };

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename).'.'.$format;

        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
