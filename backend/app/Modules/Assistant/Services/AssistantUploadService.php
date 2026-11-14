<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Models\AssistantUpload;
use App\Modules\Attachments\Services\AttachmentService;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to an assistant message (ADR-0028): a transfer receipt, a payment slip, a supplier
 * invoice photo. Same byte checks as record attachments (real type, size, no active content in a
 * PDF), stored on the private disk under a random name for a few hours, readable only by the user
 * who uploaded them, and removed by `assistant:prune-history`. The file is never attached to a record.
 */
class AssistantUploadService
{
    public const MAX_PER_MESSAGE = 3;

    private const MAX_LIVE_PER_USER = 20;

    public function __construct(private readonly AttachmentService $checks) {}

    /** @throws \DomainException when the file fails a check or the user holds too many files */
    public function store(User $user, UploadedFile $file): AssistantUpload
    {
        $content = (string) file_get_contents($file->getRealPath());
        $mime = $this->checks->inspect($content, strlen($content));

        $live = AssistantUpload::query()->where('user_id', $user->id)->where('expires_at', '>', now())->count();
        if ($live >= self::MAX_LIVE_PER_USER) {
            throw new \DomainException('Too many files are waiting. They are removed automatically after a few hours.');
        }

        $extension = (string) (config('attachments.allowed')[$mime] ?? 'bin');
        $path = 'assistant-uploads/'.now()->format('Y/m').'/'.Str::ulid()->toBase32().'.'.$extension;
        if (Storage::disk(config('attachments.disk'))->put($path, $content) !== true) {
            throw new \DomainException('The file could not be saved because storage is full or not writable.');
        }

        return AssistantUpload::query()->create([
            'user_id' => $user->id,
            'name' => $this->cleanName($file->getClientOriginalName()),
            'mime' => $mime,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'stored_path' => $path,
            'expires_at' => now()->addHours((int) config('assistant.upload_ttl_hours', 6)),
        ]);
    }

    /**
     * The user's own, unexpired uploads for these ids, in the order given.
     *
     * @param  list<string>  $ids
     * @return list<AssistantUpload>|null null when any id is unknown, expired or not theirs
     */
    public function resolve(User $user, array $ids): ?array
    {
        $ids = array_values(array_unique($ids));
        $rows = AssistantUpload::query()->where('user_id', $user->id)->where('expires_at', '>', now())->whereIn('public_id', $ids)->get()->keyBy('public_id');
        if ($rows->count() !== count($ids)) {
            return null;
        }

        return array_map(fn (string $id) => $rows[$id], $ids);
    }

    /**
     * What the provider receives for these files.
     *
     * @param  list<AssistantUpload>  $uploads
     * @return list<array{name: string, mime: string, content: string}>
     */
    public function contents(array $uploads): array
    {
        return array_map(fn (AssistantUpload $u) => [
            'name' => $u->name,
            'mime' => $u->mime,
            'content' => (string) Storage::disk(config('attachments.disk'))->get($u->stored_path),
        ], $uploads);
    }

    public function delete(AssistantUpload $upload): void
    {
        Storage::disk(config('attachments.disk'))->delete($upload->stored_path);
        $upload->delete();
    }

    /** Removes expired files and their rows. */
    public function prune(): int
    {
        $count = 0;
        AssistantUpload::query()->where('expires_at', '<=', now())->each(function (AssistantUpload $upload) use (&$count) {
            $this->delete($upload);
            $count++;
        });

        return $count;
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($name)) ?? 'file';

        return mb_substr($name === '' ? 'file' : $name, 0, 120);
    }
}
