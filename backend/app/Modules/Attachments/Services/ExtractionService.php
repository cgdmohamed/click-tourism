<?php

namespace App\Modules\Attachments\Services;

use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\ProviderCredentials;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Models\AttachmentExtraction;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AI-assisted reading of an uploaded document (GAP-CORE-03). The result is only a proposal kept
 * with the document: it creates no business record, posts nothing and approves nothing. A person
 * reviews and corrects the values; confirming stores what they accepted, and any invoice, payment
 * or customer change is then made through the normal forms and their normal validation.
 */
class ExtractionService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly ProviderManager $assistant,
        private readonly ProviderCredentials $credentials,
        private readonly AttachmentService $attachments,
        private readonly OpenAiDocumentExtractor $extractor,
        private readonly AuditService $audit,
    ) {}

    /** Switched on, the data-processing notice accepted (ADR-0018) and a provider key present. */
    public function available(): bool
    {
        return $this->settings->bool('attachments', 'extraction_enabled', false)
            && $this->assistant->accepted()
            && $this->credentials->source() !== 'none';
    }

    public function latest(Attachment $attachment): ?AttachmentExtraction
    {
        return AttachmentExtraction::query()->where('attachment_id', $attachment->id)->orderByDesc('id')->first();
    }

    /** @return array{extraction: AttachmentExtraction, reused: bool} */
    public function request(Attachment $attachment, ?int $actorId): array
    {
        if (! $this->available()) {
            throw new DomainException('Document extraction is not enabled.');
        }
        if (! ExtractionSchema::supports($attachment->category)) {
            throw new DomainException('This kind of document cannot be read automatically; enter the details by hand.');
        }
        if ((int) $attachment->size_bytes > (int) config('attachments.extraction_max_kb') * 1024) {
            throw new DomainException('The document is too large to read automatically; enter the details by hand.');
        }

        return DB::transaction(function () use ($attachment, $actorId) {
            $open = AttachmentExtraction::query()->where('attachment_id', $attachment->id)
                ->where('status', AttachmentExtraction::SUCCEEDED)->lockForUpdate()->first();
            if ($open) {
                return ['extraction' => $open, 'reused' => true];
            }

            $model = $this->assistant->model();
            $row = AttachmentExtraction::query()->create([
                'attachment_id' => $attachment->id, 'status' => AttachmentExtraction::FAILED,
                'category' => $attachment->category, 'model' => $model, 'requested_by' => $actorId,
            ]);
            $this->audit->log('attachment.extraction_requested', $attachment, null, ['category' => $attachment->category], $actorId);

            try {
                $answer = $this->extractor->extract(
                    $attachment->category, $attachment->mime, $this->attachments->content($attachment), $attachment->original_name, $model,
                );
                $row->update(['status' => AttachmentExtraction::SUCCEEDED, 'proposed' => ['fields' => $this->normalise($attachment->category, $answer)]]);
                $this->audit->log('attachment.extraction_succeeded', $attachment, null, ['fields' => count(ExtractionSchema::fields($attachment->category))], $actorId);
            } catch (ProviderException|DomainException) {
                // The user keeps working by hand; the reason is a code, never the provider's body.
                $row->update(['error_code' => 'extraction_failed']);
                $this->audit->log('attachment.extraction_failed', $attachment, null, null, $actorId);
            }

            return ['extraction' => $row->refresh(), 'reused' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function confirm(AttachmentExtraction $extraction, array $values, ?int $actorId): AttachmentExtraction
    {
        return DB::transaction(function () use ($extraction, $values, $actorId) {
            $extraction = AttachmentExtraction::query()->lockForUpdate()->findOrFail($extraction->id);
            if ($extraction->status === AttachmentExtraction::CONFIRMED) {
                return $extraction; // repeating the confirmation changes nothing
            }
            if ($extraction->status !== AttachmentExtraction::SUCCEEDED) {
                throw new DomainException('Only a proposal waiting for review can be confirmed.');
            }
            $clean = ExtractionSchema::validateReviewed($extraction->category, $values);
            $extraction->update([
                'status' => AttachmentExtraction::CONFIRMED, 'confirmed' => $clean,
                'reviewed_by' => $actorId, 'reviewed_at' => now(),
            ]);
            // Field names only: the values may be personal data.
            $this->audit->log('attachment.extraction_confirmed', $extraction->attachment, null, ['fields' => array_keys(array_filter($clean, fn ($v) => $v !== null))], $actorId);

            return $extraction->refresh();
        });
    }

    public function reject(AttachmentExtraction $extraction, ?int $actorId): AttachmentExtraction
    {
        if ($extraction->status === AttachmentExtraction::SUCCEEDED) {
            $extraction->update(['status' => AttachmentExtraction::REJECTED, 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
            $this->audit->log('attachment.extraction_rejected', $extraction->attachment, null, null, $actorId);
        }

        return $extraction->refresh();
    }

    /**
     * Keeps only the fields of the category; a missing value is flagged, a value that is not valid
     * for its type or that the model marked unclear is flagged for the reviewer.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, array{value: ?string, flag: ?string}>
     */
    private function normalise(string $category, array $answer): array
    {
        $out = [];
        foreach (ExtractionSchema::fields($category) as $field => $type) {
            $cell = $answer[$field] ?? null;
            $raw = is_array($cell) ? ($cell['value'] ?? null) : null;
            [$value, $problem] = ExtractionSchema::normalise($type, $raw);
            if ($problem === null && is_array($cell) && ($cell['unclear'] ?? false) === true) {
                $problem = 'unclear';
            }
            $out[$field] = ['value' => $value, 'flag' => $problem];
        }

        return $out;
    }
}
