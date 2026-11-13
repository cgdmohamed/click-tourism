<?php

namespace App\Http\Controllers\Api;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Services\AttachmentService;
use App\Modules\Attachments\Services\AttachmentTargets;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Private document attachments (ADR-0021). Every route re-checks the user's right to the record
 * the document belongs to; files are streamed through the API and never exposed by a URL.
 */
class AttachmentController extends ApiController
{
    public function __construct(private readonly AttachmentTargets $targets, private readonly AttachmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(AttachmentTargets::types())],
            'attachable_id' => ['required', 'string', 'max:26'],
        ]);
        $user = $this->user();
        $record = $this->targets->find($data['attachable_type'], $data['attachable_id']);
        $this->targets->authorizeRecord($user, $data['attachable_type'], $record, 'view');

        $rows = Attachment::query()->with('uploader')
            ->where('attachable_type', $data['attachable_type'])->where('attachable_id', $record->getKey())
            ->orderByDesc('id')->get();
        $visible = $this->targets->canSeeSensitive($user) ? $rows : $rows->reject(fn (Attachment $a) => $a->isSensitive());

        return $this->ok([
            'items' => $visible->map->toApiArray()->values()->all(),
            'hidden_sensitive_count' => $rows->count() - $visible->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $max = (int) config('attachments.max_files_per_upload');
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(AttachmentTargets::types())],
            'attachable_id' => ['required', 'string', 'max:26'],
            'category' => ['required', Rule::in(Attachment::CATEGORIES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'files' => ['required', 'array', 'min:1', 'max:'.$max],
            'files.*' => ['required', 'file'],
        ]);
        $user = $this->user();
        $record = $this->targets->find($data['attachable_type'], $data['attachable_id']);
        $this->targets->authorizeRecord($user, $data['attachable_type'], $record, 'upload');
        if (in_array($data['category'], Attachment::SENSITIVE, true) && ! $this->targets->canSeeSensitive($user)) {
            return ApiResponse::error('Your role may not upload documents with personal data.', 'forbidden', 403);
        }

        $stored = [];
        $rejected = [];
        foreach ($request->file('files', []) as $file) {
            try {
                $result = $this->service->store($file, $data['attachable_type'], $record, $data['category'], $data['notes'] ?? null, $user->id);
                $stored[] = $result['attachment']->toApiArray() + ['duplicate' => $result['duplicate']];
            } catch (DomainException $e) {
                $rejected[] = ['name' => $file->getClientOriginalName(), 'reason' => $e->getMessage()];
            }
        }

        return $this->created(['items' => $stored, 'rejected' => $rejected]);
    }

    public function show(Attachment $attachment): JsonResponse
    {
        $this->targets->authorizeAttachment($this->user(), $attachment, 'view');

        return $this->ok($attachment->load('uploader')->toApiArray());
    }

    public function update(Request $request, Attachment $attachment): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', Rule::in(Attachment::CATEGORIES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $attachment, 'upload');
        if (isset($data['category']) && in_array($data['category'], Attachment::SENSITIVE, true) && ! $this->targets->canSeeSensitive($user)) {
            return ApiResponse::error('Your role may not classify documents as personal data.', 'forbidden', 403);
        }

        return $this->ok($this->service->update($attachment, $data, $user->id)->load('uploader')->toApiArray());
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $attachment, 'delete');
        $this->service->delete($attachment, $user->id);

        return $this->ok(['deleted' => true]);
    }

    /** Download (attachment) or preview (inline). Both are audited and use locked-down headers. */
    private function file(Attachment $attachment, AuditService $audit, bool $inline): Response
    {
        $user = $this->user();
        $this->targets->authorizeAttachment($user, $attachment, 'view');
        try {
            $content = $this->service->content($attachment);
        } catch (DomainException) {
            abort(404);
        }
        $audit->log($inline ? 'attachment.previewed' : 'attachment.downloaded', $attachment, null, ['category' => $attachment->category], $user->id);

        $safeName = str_replace(['"', '\\', "\r", "\n"], '_', $attachment->original_name);

        return response($content, 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safeName.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function download(Request $request, Attachment $attachment, AuditService $audit): Response
    {
        return $this->file($attachment, $audit, false);
    }

    public function preview(Request $request, Attachment $attachment, AuditService $audit): Response
    {
        return $this->file($attachment, $audit, true);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
