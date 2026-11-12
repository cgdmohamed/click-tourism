<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\BranchScope;
use App\Modules\Imports\Models\ImportJob;
use App\Modules\Imports\Services\ImportRegistry;
use App\Modules\Imports\Services\ImportService;
use App\Modules\Imports\Services\ImportTemplateService;
use App\Modules\Imports\Support\ImportFileException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic import framework: versioned templates, dry-run preview, confirmed
 * background import jobs and the partial-failure report. Authorisation is per
 * entity (the same permission that would create/update the record by hand) and
 * every job is scoped to the branch it was started in.
 */
class ImportController extends ApiController
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportService $imports,
        private readonly ImportTemplateService $templates,
    ) {}

    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = [];

        foreach ($this->registry->all() as $definition) {
            if (! $this->imports->canRun($user, $definition)) {
                continue;
            }
            $items[] = [
                'entity' => $definition->entity(),
                'version' => $definition->version(),
                'label_en' => $definition->labels()['en'],
                'label_ar' => $definition->labels()['ar'],
                'supports_duplicate_policy' => $definition->supportsDuplicatePolicy(),
                'default_duplicate_policy' => $definition->defaultPolicy(),
                'atomic' => $definition->isAtomic(),
                'permissions' => $definition->permissions($definition->defaultPolicy()),
                'columns' => array_map(fn ($column) => $column->toApiArray(), $definition->columns()),
                'options' => collect($definition->options())->map(fn (array $option, string $key) => [
                    'key' => $key,
                    'label_en' => $option['en'],
                    'label_ar' => $option['ar'],
                    'required' => $option['required'],
                ])->values()->all(),
            ];
        }

        return $this->ok([
            'limits' => [
                'max_file_bytes' => (int) config('imports.max_file_bytes'),
                'max_rows' => (int) config('imports.max_rows'),
                'formats' => ['csv', 'xlsx'],
            ],
            'templates' => $items,
        ]);
    }

    public function template(Request $request, string $entity): BinaryFileResponse
    {
        $definition = $this->registry->get($entity);
        $this->authorizeEntity($request, $definition);

        $query = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        $format = $query['format'] ?? 'csv';
        $locale = $query['locale'] ?? (app()->getLocale() === 'en' ? 'en' : 'ar');

        $built = $this->templates->build($definition, $locale, $format);

        return response()->download($built['path'], $this->templates->filename($definition, $locale, $format), [
            'Content-Type' => $built['mime'],
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedEntities = [];
        foreach ($this->registry->all() as $definition) {
            if ($this->imports->canRun($user, $definition)) {
                $allowedEntities[] = $definition->entity();
            }
        }

        $branchIds = app(BranchScope::class)->allowedBranchIds($user);
        $branch = $this->branch();

        $jobs = ImportJob::query()->with(['branch', 'creator'])
            ->whereIn('entity', $allowedEntities)
            ->whereIn('branch_id', $branchIds)
            ->when($branch && ! $request->boolean('all_branches'), fn ($query) => $query->where('branch_id', $branch->id))
            ->when($request->query('entity'), fn ($query, $entity) => $query->where('entity', $entity))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        $jobs->getCollection()->transform(fn (ImportJob $job) => $this->summary($job));

        return $this->paginated($jobs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'string', 'max:40'],
            'file' => ['required', 'file'],
            'dry_run' => ['nullable', 'boolean'],
            'duplicate_policy' => ['nullable', Rule::in(ImportService::policies())],
            'on_error' => ['nullable', Rule::in(['skip', 'abort'])],
            'template_version' => ['nullable', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
        ]);

        $job = $this->imports->create(
            $request->user(),
            $this->branchOrFail(),
            $data['entity'],
            $request->file('file'),
            [
                'dry_run' => $request->has('dry_run') ? $request->boolean('dry_run') : true,
                'duplicate_policy' => $data['duplicate_policy'] ?? null,
                'on_error' => $data['on_error'] ?? null,
                'template_version' => $data['template_version'] ?? null,
                'options' => $data['options'] ?? [],
            ],
        );

        return $this->created($job->toApiArray());
    }

    public function show(Request $request, ImportJob $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        return $this->ok($job->load(['branch', 'creator'])->toApiArray());
    }

    public function confirm(Request $request, ImportJob $job): JsonResponse
    {
        $this->authorizeJob($request, $job);

        return $this->ok($this->imports->confirm($request->user(), $job)->toApiArray());
    }

    public function destroy(Request $request, ImportJob $job): Response
    {
        $this->authorizeJob($request, $job);
        $this->imports->discard($request->user(), $job);

        return response()->noContent();
    }

    public function errors(Request $request, ImportJob $job): StreamedResponse
    {
        $this->authorizeJob($request, $job);

        $disk = Storage::disk(config('imports.disk'));
        if (! $job->error_report_path || ! $disk->exists($job->error_report_path)) {
            throw new ImportFileException('no_error_report', 'This import has no error report.', [], 404);
        }

        return $disk->download($job->error_report_path, "import-{$job->entity}-{$job->public_id}-errors.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function authorizeEntity(Request $request, $definition): void
    {
        if (! $this->imports->canRun($request->user(), $definition)) {
            throw new AuthorizationException('You are not allowed to import this data.');
        }
    }

    private function authorizeJob(Request $request, ImportJob $job): void
    {
        $this->authorizeBranchId($job->branch_id);
        $this->authorizeEntity($request, $this->registry->get($job->entity));
    }

    /** List rows omit the heavy preview/error payloads. */
    private function summary(ImportJob $job): array
    {
        return array_diff_key($job->toApiArray(), array_flip(['preview', 'errors']));
    }
}
