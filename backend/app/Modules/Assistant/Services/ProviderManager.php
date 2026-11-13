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

    /** Switched on, the data-processing notice accepted, a real provider chosen and its credential present. */
    public function usable(): bool
    {
        return $this->enabled() && $this->accepted() && $this->provider()->isConfigured();
    }

    public function enabled(): bool
    {
        return $this->settings->bool('assistant', 'enabled', false);
    }

    /**
     * The administrator (or installer) accepted the data-processing notice (ADR-0018). An empty
     * timestamp means not accepted; enabling the assistant requires it.
     */
    public function accepted(): bool
    {
        return trim((string) $this->settings->get('assistant', 'data_processing_accepted_at', '')) !== '';
    }

    /** The chosen provider holds a credential in the environment (never a setting). */
    public function keyConfigured(): bool
    {
        return $this->provider()->isConfigured();
    }

    /**
     * The provider model discovery may ask (ADR-0018). The chosen provider wins; while the
     * installation still has the default provider "none" (a fresh install) the first provider
     * whose key exists in the environment is used, so putting the key in `.env` is enough to
     * list the models an administrator can then pick from. Null when no key is present.
     *
     * @return array{name: string, provider: AssistantProvider}|null
     */
    public function discoveryProvider(): ?array
    {
        $chosen = $this->provider();
        if ($this->providerName() !== 'none' && $chosen->isConfigured()) {
            return ['name' => $this->providerName(), 'provider' => $chosen];
        }

        foreach (self::PROVIDERS as $name => $class) {
            if ($name === 'none') {
                continue;
            }
            $provider = app($class);
            if ($provider->isConfigured()) {
                return ['name' => $name, 'provider' => $provider];
            }
        }

        return null;
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
