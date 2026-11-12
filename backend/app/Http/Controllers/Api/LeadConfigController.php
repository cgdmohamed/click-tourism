<?php

namespace App\Http\Controllers\Api;

use App\Modules\Core\Services\AuditService;
use App\Modules\CRM\Models\AssignmentRule;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manage the configurable lead sources and pipeline stages (GAP-CRM-02).
 *
 * Codes are the stable key used by leads, assignment rules and the UI, so they
 * are immutable after creation. Records in use are never hard-deleted: sources
 * are deactivated and the caller gets a validation message instead.
 */
class LeadConfigController extends ApiController
{
    public function sources(Request $request): JsonResponse
    {
        $sources = LeadSource::query()
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get()
            ->map(fn (LeadSource $source) => $source->toApiArray());

        return $this->ok($sources);
    }

    public function storeSource(Request $request, AuditService $audit): JsonResponse
    {
        $data = $this->validateSource($request);
        $source = LeadSource::query()->create($data + ['is_active' => $data['is_active'] ?? true]);

        $audit->log('lead_source.created', $source, null, $source->toApiArray(), $this->actorId());

        return $this->created($source->toApiArray());
    }

    public function updateSource(Request $request, LeadSource $source, AuditService $audit): JsonResponse
    {
        $data = $this->validateSource($request, $source);
        $before = $source->toApiArray();
        $source->update($data);

        $audit->log('lead_source.updated', $source, $before, $source->refresh()->toApiArray(), $this->actorId());

        return $this->ok($source->toApiArray());
    }

    public function destroySource(LeadSource $source, AuditService $audit): JsonResponse
    {
        $this->assertSourceUnused($source);
        $before = $source->toApiArray();
        $audit->log('lead_source.deleted', $source, $before, null, $this->actorId());
        $source->delete();

        return $this->ok(['code' => $before['code'], 'deleted' => true]);
    }

    public function stages(): JsonResponse
    {
        $stages = LeadStage::query()->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn (LeadStage $stage) => $stage->toApiArray());

        return $this->ok($stages);
    }

    public function storeStage(Request $request, AuditService $audit): JsonResponse
    {
        $data = $this->validateStage($request);
        $stage = LeadStage::query()->create($data + [
            'sort_order' => $data['sort_order'] ?? 0,
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
        ]);

        $audit->log('lead_stage.created', $stage, null, $stage->toApiArray(), $this->actorId());

        return $this->created($stage->toApiArray());
    }

    public function updateStage(Request $request, LeadStage $stage, AuditService $audit): JsonResponse
    {
        $data = $this->validateStage($request, $stage);
        $before = $stage->toApiArray();
        $stage->update($data);

        $audit->log('lead_stage.updated', $stage, $before, $stage->refresh()->toApiArray(), $this->actorId());

        return $this->ok($stage->toApiArray());
    }

    public function destroyStage(LeadStage $stage, AuditService $audit): JsonResponse
    {
        if ($stage->code === 'new') {
            throw ValidationException::withMessages([
                'stage' => ['The default "new" stage cannot be deleted; rename it instead.'],
            ]);
        }

        if (Lead::query()->where('stage_id', $stage->id)->exists()) {
            throw ValidationException::withMessages([
                'stage' => ['This stage is used by leads. Move those leads to another stage before deleting it.'],
            ]);
        }

        $before = $stage->toApiArray();
        $audit->log('lead_stage.deleted', $stage, $before, null, $this->actorId());
        $stage->delete();

        return $this->ok(['code' => $before['code'], 'deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validateSource(Request $request, ?LeadSource $source = null): array
    {
        if ($source === null && is_string($request->input('code'))) {
            $request->merge(['code' => strtolower(trim($request->input('code')))]);
        }

        $rules = [
            'name_ar' => [$source ? 'sometimes' : 'required', 'string', 'max:255'],
            'name_en' => [$source ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($source === null) {
            $rules['code'] = ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('lead_sources', 'code')];
        }

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    private function validateStage(Request $request, ?LeadStage $stage = null): array
    {
        if ($stage === null && is_string($request->input('code'))) {
            $request->merge(['code' => strtolower(trim($request->input('code')))]);
        }

        $rules = [
            'name_ar' => [$stage ? 'sometimes' : 'required', 'string', 'max:255'],
            'name_en' => [$stage ? 'sometimes' : 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ];

        if ($stage === null) {
            $rules['code'] = ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('lead_stages', 'code')];
        }

        $data = $request->validate($rules);

        $isWon = (bool) ($data['is_won'] ?? $stage?->is_won ?? false);
        $isLost = (bool) ($data['is_lost'] ?? $stage?->is_lost ?? false);
        if ($isWon && $isLost) {
            throw ValidationException::withMessages([
                'is_won' => ['A stage cannot be both won and lost.'],
            ]);
        }

        return $data;
    }

    private function assertSourceUnused(LeadSource $source): void
    {
        if ($source->code === 'other') {
            throw ValidationException::withMessages([
                'source' => ['The default "other" source cannot be deleted; deactivate it instead.'],
            ]);
        }

        if (Lead::query()->where('source_id', $source->id)->exists()
            || AssignmentRule::query()->where('source_id', $source->id)->exists()) {
            throw ValidationException::withMessages([
                'source' => ['This source is used by leads or assignment rules. Deactivate it instead of deleting it.'],
            ]);
        }
    }
}
