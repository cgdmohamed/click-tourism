<?php

namespace App\Http\Controllers\Api;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Models\AttachmentExtraction;
use App\Modules\Attachments\Services\AttachmentTargets;
use App\Modules\Attachments\Services\ExtractionService;
use App\Modules\Core\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI-assisted extraction (ADR-0021). A proposal only: it never creates a business record and
 * never posts, approves or pays anything. Review and confirmation are mandatory steps.
 */
class AttachmentExtractionController extends ApiController
{
    public function __construct(private readonly AttachmentTargets $targets, private readonly ExtractionService $service) {}

    public function show(Attachment $attachment): JsonResponse
    {
        $this->targets->authorizeAttachment($this->user(), $attachment, 'view');
        $extraction = $this->service->latest($attachment);

        return $this->ok($extraction?->toApiArray());
    }

    public function store(Attachment $attachment): JsonResponse
    {
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $attachment, 'extract');
        try {
            $result = $this->service->request($attachment, $user->id);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 'extraction_unavailable', 422);
        }

        return $this->ok($result['extraction']->toApiArray() + ['reused' => $result['reused']]);
    }

    public function confirm(Request $request, AttachmentExtraction $extraction): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array'], 'values.*' => ['nullable', 'string', 'max:255']]);
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $extraction->attachment, 'extract');
        try {
            return $this->ok($this->service->confirm($extraction, $data['values'], $user->id)->toApiArray());
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 'extraction_invalid', 422);
        }
    }

    public function reject(AttachmentExtraction $extraction): JsonResponse
    {
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $extraction->attachment, 'extract');

        return $this->ok($this->service->reject($extraction, $user->id)->toApiArray());
    }

    private function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
