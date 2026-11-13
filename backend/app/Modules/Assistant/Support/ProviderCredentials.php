<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Core\Models\Setting;
use App\Modules\Core\Services\SettingService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The provider API key. An administrator saves it from Settings (stored encrypted, flagged secret,
 * never returned by any endpoint); the environment value (OPENAI_API_KEY) remains a fallback for
 * installers who prefer it. The stored key wins, so changing it in the product takes effect at once.
 */
final class ProviderCredentials
{
    private const GROUP = 'assistant';

    private const KEY = 'openai_api_key';

    public function __construct(private readonly SettingService $settings) {}

    public function openAiKey(): string
    {
        $stored = $this->stored();

        return $stored !== '' ? $stored : trim((string) config('services.openai.key'));
    }

    /** @return 'settings'|'env'|'none' */
    public function source(): string
    {
        if ($this->stored() !== '') {
            return 'settings';
        }

        return trim((string) config('services.openai.key')) !== '' ? 'env' : 'none';
    }

    public function store(string $key): void
    {
        $this->settings->set(self::GROUP, self::KEY, Crypt::encryptString(trim($key)), true);
    }

    public function clear(): void
    {
        Setting::query()->where('group', self::GROUP)->where('key', self::KEY)->delete();
        Cache::forget('settings.'.self::GROUP.'.'.self::KEY);
    }

    private function stored(): string
    {
        $raw = Setting::query()->where('group', self::GROUP)->where('key', self::KEY)->value('value');
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($raw));
        } catch (DecryptException) {
            // The app key changed since the key was saved: treat it as absent, never as a credential.
            return '';
        }
    }
}
