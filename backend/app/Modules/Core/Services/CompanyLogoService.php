<?php

namespace App\Modules\Core\Services;

use App\Support\LogoDataUri;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The installation letterhead logo, stored under storage/app/branding with a
 * server-generated name and referenced by setting general.company_logo (the
 * value the report and invoice renderers already read through LogoDataUri).
 *
 * Only PNG and JPEG are accepted, decided from the file CONTENT (never the
 * client-supplied name or MIME type): SVG, GIF, WebP and anything else is
 * refused. Files this service did not create (a path an installer put in the
 * setting) keep working but are never deleted by it.
 */
class CompanyLogoService
{
    public const MAX_BYTES = LogoDataUri::MAX_BYTES;

    public const MAX_DIMENSION = 4000;

    private const DIRECTORY = 'branding';

    private const MANAGED = '#^branding/logo-[0-9a-f]{32}\.(png|jpg)$#';

    public function __construct(private readonly SettingService $settings) {}

    /** Validate and store the upload, replace the previous managed file; returns the new setting value. */
    public function store(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $size = $path !== false ? filesize($path) : false;
        if ($path === false || $size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw $this->invalid('The logo must be a non-empty file of at most 1 MB.');
        }

        $binary = file_get_contents($path);
        $info = $binary === false ? false : @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw $this->invalid('The logo must be a PNG or JPEG image (SVG, GIF and other formats are not allowed).');
        }
        if ($info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            throw $this->invalid('The logo dimensions must be between 16 and '.self::MAX_DIMENSION.' pixels per side.');
        }

        $directory = storage_path('app/'.self::DIRECTORY);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw $this->invalid('The logo could not be stored.');
        }

        $name = self::DIRECTORY.'/logo-'.bin2hex(random_bytes(16)).($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        if (file_put_contents(storage_path('app/'.$name), $binary) === false) {
            throw $this->invalid('The logo could not be stored.');
        }

        $old = (string) $this->settings->get('general', 'company_logo', '');
        $this->settings->set('general', 'company_logo', $name);
        $this->deleteIfManaged($old);

        return $name;
    }

    public function remove(): void
    {
        $old = (string) $this->settings->get('general', 'company_logo', '');
        $this->settings->set('general', 'company_logo', '');
        $this->deleteIfManaged($old);
    }

    public function has(): bool
    {
        return LogoDataUri::fromValue((string) $this->settings->get('general', 'company_logo', '')) !== null;
    }

    /** @return array{mime: string, binary: string}|null */
    public function image(): ?array
    {
        $uri = LogoDataUri::fromValue((string) $this->settings->get('general', 'company_logo', ''));
        if ($uri === null || preg_match('#^data:(image/(?:png|jpeg));base64,(.+)$#', $uri, $m) !== 1) {
            return null;
        }

        $binary = base64_decode($m[2], true);

        return $binary === false ? null : ['mime' => $m[1], 'binary' => $binary];
    }

    /** Cache-busting token that changes whenever the logo setting changes; null when there is no logo. */
    public function version(): ?string
    {
        return $this->has() ? substr(sha1((string) $this->settings->get('general', 'company_logo', '')), 0, 12) : null;
    }

    private function deleteIfManaged(string $value): void
    {
        if (preg_match(self::MANAGED, $value) === 1) {
            @unlink(storage_path('app/'.$value));
        }
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['logo' => $message]);
    }
}
