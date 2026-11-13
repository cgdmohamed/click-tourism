<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Contracts\AssistantProvider;
use App\Modules\Assistant\Providers\NoneProvider;
use App\Modules\Assistant\Providers\OpenAiProvider;
use App\Modules\Core\Services\SettingService;

/** Picks the provider named by the setting assistant.provider. Unknown names fall back to 'none'. */
class ProviderManager
{
    /** name => provider class. Add new providers here (and to the settings validation). */
    public const PROVIDERS = [
        'none' => NoneProvider::class,
        'openai' => OpenAiProvider::class,
    ];

    public function __construct(private readonly SettingService $settings) {}

    public function providerName(): string
    {
        $name = strtolower(trim((string) $this->settings->get('assistant', 'provider', 'none')));

        return array_key_exists($name, self::PROVIDERS) ? $name : 'none';
    }

    public function provider(): AssistantProvider
    {
        return app(self::PROVIDERS[$this->providerName()]);
    }

    /** Switched on, a real provider chosen and its credential present. */
    public function usable(): bool
    {
        return $this->enabled() && $this->provider()->isConfigured();
    }

    public function enabled(): bool
    {
        return $this->settings->bool('assistant', 'enabled', false);
    }

    /** Persisted whole-number override of assistant.<key>, else config('assistant.<key>') (env). */
    public function limit(string $key): int
    {
        $stored = $this->settings->get('assistant', $key);
        $value = $stored !== null && ctype_digit($stored) ? (int) $stored : 0;

        return $value > 0 ? $value : max(1, (int) config('assistant.'.$key));
    }

    public function model(): string
    {
        $model = trim((string) $this->settings->get('assistant', 'model', ''));

        return $model !== '' ? $model : (string) config('assistant.default_model');
    }
}
