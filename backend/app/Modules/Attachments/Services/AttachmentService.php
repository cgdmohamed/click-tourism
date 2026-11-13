<?php

namespace App\Modules\Attachments\Services;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Core\Services\AuditService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Store one uploaded file for a record. Submitting the same file again for the same record
     * returns the stored one instead of a second copy.
     *
     * @return array{attachment: Attachment, duplicate: bool}
     */
    public function store(UploadedFile $file, string $type, Model $record, string $category, ?string $notes, ?int $actorId): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $mime = $this->inspect($content, $file->getSize() ?: strlen($content));
        $sha = hash('sha256', $content);

        return DB::transaction(function () use ($content, $mime, $sha, $file, $type, $record, $category, $notes, $actorId) {
            $existing = Attachment::query()
                ->where('attachable_type', $type)->where('attachable_id', $record->getKey())
                ->where('sha256', $sha)->lockForUpdate()->first();
            if ($existing) {
                return ['attachment' => $existing, 'duplicate' => true];
            }

            $path = 'attachments/'.now()->format('Y/m').'/'.Str::ulid().'.'.config('attachments.allowed')[$mime];
            Storage::disk(config('attachments.disk'))->put($path, $content);

            $attachment = Attachment::query()->create([
                'attachable_type' => $type,
                'attachable_id' => $record->getKey(),
                'branch_id' => $record->getAttribute('branch_id'),
                'category' => $category,
                'original_name' => $this->cleanName((string) $file->getClientOriginalName()),
                'stored_path' => $path,
                'mime' => $mime,
                'size_bytes' => strlen($content),
                'sha256' => $sha,
                'notes' => $notes,
                'uploaded_by' => $actorId,
            ]);
            $this->audit->log('attachment.uploaded', $attachment, null, [
                'record' => $type.':'.$record->getAttribute('public_id'), 'category' => $category, 'size_bytes' => strlen($content),
            ], $actorId);

            return ['attachment' => $attachment, 'duplicate' => false];
        });
    }

    public function update(Attachment $attachment, array $data, ?int $actorId): Attachment
    {
        $before = ['category' => $attachment->category, 'notes' => $attachment->notes];
        $attachment->update(array_intersect_key($data, ['category' => 1, 'notes' => 1]));
        $this->audit->log('attachment.updated', $attachment, $before, ['category' => $attachment->category], $actorId);

        return $attachment;
    }

    /** The record disappears from lists; the file is kept so financial evidence is not lost. */
    public function delete(Attachment $attachment, ?int $actorId): void
    {
        $attachment->delete();
        $this->audit->log('attachment.deleted', $attachment, ['category' => $attachment->category], null, $actorId);
    }

    public function content(Attachment $attachment): string
    {
        $disk = Storage::disk(config('attachments.disk'));
        if (! $disk->exists($attachment->stored_path)) {
            throw new DomainException('The stored file is missing.');
        }

        return (string) $disk->get($attachment->stored_path);
    }

    /**
     * Security checks on the bytes, never on what the client says: size, real type from the content,
     * an allowlist, a decodable image, and no active content inside a PDF.
     */
    private function inspect(string $content, int $size): string
    {
        if ($size <= 0 || $size > (int) config('attachments.max_size_kb') * 1024) {
            throw new DomainException('The file is empty or larger than the allowed size.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        if (! array_key_exists($mime, config('attachments.allowed'))) {
            throw new DomainException('Only PDF, JPEG, PNG and WebP files are accepted.');
        }
        if ($mime === 'application/pdf') {
            if (! str_starts_with($content, '%PDF-') || preg_match('~/(JavaScript|JS|Launch|OpenAction|AA|EmbeddedFile)\b~', $content) === 1) {
                throw new DomainException('This PDF contains active content and was refused.');
            }
        } elseif (@getimagesizefromstring($content) === false) {
            throw new DomainException('The image could not be read.');
        }

        return $mime;
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name)) ?? 'document';

        return Str::limit(trim($name, " .\t\n") ?: 'document', 150, '');
    }
}
