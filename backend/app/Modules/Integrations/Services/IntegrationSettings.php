<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\SettingService;
use App\Modules\Integrations\Models\WebhookEvent;
use App\Modules\Integrations\Models\WhatsAppMessage;

/**
 * Typed access to the `integrations` settings group (ADR-0010).
 *
 * Non-secret identifiers and flags live in settings; secrets (app secret,
 * verify token, access token) are read ONLY from env-backed config and are
 * never stored, returned or logged. Everything is disabled by default.
 */
class IntegrationSettings
{
    public const GROUP = 'integrations';

    /** Keys writable through PUT /integrations/settings (all non-secret). */
    public const WRITABLE = [
        'meta_leads_enabled',
        'meta_page_id',
        'meta_default_branch_id',
        'meta_form_branch_map',
        'meta_graph_version',
        'whatsapp_enabled',
        'whatsapp_outbound_enabled',
        'whatsapp_phone_number_id',
        'whatsapp_business_account_id',
        'whatsapp_graph_version',
        'whatsapp_stop_keywords',
    ];

    public function __construct(private readonly SettingService $settings) {}

    public function metaEnabled(): bool
    {
        return $this->settings->bool(self::GROUP, 'meta_leads_enabled');
    }

    public function whatsappEnabled(): bool
    {
        return $this->settings->bool(self::GROUP, 'whatsapp_enabled');
    }

    /** Outbound is a separate switch: inbound logging does not imply sending. */
    public function whatsappOutboundEnabled(): bool
    {
        return $this->whatsappEnabled() && $this->settings->bool(self::GROUP, 'whatsapp_outbound_enabled');
    }

    public function metaGraphVersion(): string
    {
        return $this->version('meta_graph_version', (string) config('services.meta.graph_version'));
    }

    public function whatsappGraphVersion(): string
    {
        return $this->version('whatsapp_graph_version', (string) config('services.meta.graph_version'));
    }

    public function graphBaseUrl(): string
    {
        return rtrim((string) config('services.meta.graph_base_url', 'https://graph.facebook.com'), '/');
    }

    public function whatsappPhoneNumberId(): string
    {
        $value = trim((string) $this->settings->get(self::GROUP, 'whatsapp_phone_number_id', ''));

        return $value !== '' ? $value : trim((string) config('services.whatsapp.phone_number_id'));
    }

    public function metaPageId(): string
    {
        return trim((string) $this->settings->get(self::GROUP, 'meta_page_id', ''));
    }

    /** Branch for a Meta lead: form mapping, then default branch, then first active branch. */
    public function branchForMetaForm(?string $formId): ?Branch
    {
        $map = json_decode((string) $this->settings->get(self::GROUP, 'meta_form_branch_map', ''), true);
        if ($formId !== null && is_array($map) && ! empty($map[$formId])) {
            $branch = Branch::query()->where('public_id', (string) $map[$formId])->where('is_active', true)->first();
            if ($branch) {
                return $branch;
            }
        }

        return $this->defaultBranch();
    }

    /** Configured default branch, else the first active branch. */
    public function defaultBranch(): ?Branch
    {
        $default = trim((string) $this->settings->get(self::GROUP, 'meta_default_branch_id', ''));
        if ($default !== '') {
            $branch = Branch::query()->where('public_id', $default)->where('is_active', true)->first();
            if ($branch) {
                return $branch;
            }
        }

        return Branch::query()->where('is_active', true)->orderBy('id')->first();
    }

    /** @return list<string> */
    public function stopKeywords(): array
    {
        $raw = (string) $this->settings->get(self::GROUP, 'whatsapp_stop_keywords', '');

        return array_values(array_filter(array_map(fn ($word) => mb_strtolower(trim($word)), explode(',', $raw))));
    }

    public function markVerified(string $provider): void
    {
        $this->settings->set(self::GROUP, $provider.'_webhook_verified_at', now()->toIso8601String());
    }

    /**
     * Admin screen payload. Secrets are reported as `configured` booleans only.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $lastMeta = WebhookEvent::query()->where('provider', 'meta')->latest('id')->first();
        $lastWhatsapp = WebhookEvent::query()->where('provider', 'whatsapp')->latest('id')->first();

        return [
            'meta' => [
                'enabled' => $this->metaEnabled(),
                'page_id' => $this->metaPageId(),
                'default_branch_id' => (string) $this->settings->get(self::GROUP, 'meta_default_branch_id', ''),
                'form_branch_map' => (string) $this->settings->get(self::GROUP, 'meta_form_branch_map', ''),
                'graph_version' => $this->metaGraphVersion(),
                'webhook_url' => url('/api/v1/webhooks/meta'),
                'configured' => [
                    'app_secret' => $this->filled(config('services.meta.app_secret')),
                    'verify_token' => $this->filled(config('services.meta.verify_token')),
                    'access_token' => $this->filled(config('services.meta.access_token')),
                ],
                'webhook_verified_at' => $this->nullable($this->settings->get(self::GROUP, 'meta_webhook_verified_at')),
                'last_event_at' => $lastMeta?->created_at?->toIso8601String(),
                'failed_events' => WebhookEvent::query()->where('provider', 'meta')->where('status', 'failed')->count(),
            ],
            'whatsapp' => [
                'enabled' => $this->whatsappEnabled(),
                'outbound_enabled' => $this->settings->bool(self::GROUP, 'whatsapp_outbound_enabled'),
                'phone_number_id' => $this->whatsappPhoneNumberId(),
                'business_account_id' => (string) $this->settings->get(self::GROUP, 'whatsapp_business_account_id', ''),
                'graph_version' => $this->whatsappGraphVersion(),
                'stop_keywords' => (string) $this->settings->get(self::GROUP, 'whatsapp_stop_keywords', ''),
                'webhook_url' => url('/api/v1/webhooks/whatsapp'),
                'configured' => [
                    'app_secret' => $this->filled(config('services.whatsapp.app_secret')),
                    'verify_token' => $this->filled(config('services.whatsapp.verify_token')),
                    'access_token' => $this->filled(config('services.whatsapp.access_token')),
                ],
                'webhook_verified_at' => $this->nullable($this->settings->get(self::GROUP, 'whatsapp_webhook_verified_at')),
                'last_event_at' => $lastWhatsapp?->created_at?->toIso8601String(),
                'failed_events' => WebhookEvent::query()->where('provider', 'whatsapp')->where('status', 'failed')->count(),
                'queued_messages' => WhatsAppMessage::query()->where('direction', 'out')->where('status', 'queued')->count(),
                'failed_messages' => WhatsAppMessage::query()->where('direction', 'out')->where('status', 'failed')->count(),
            ],
        ];
    }

    private function version(string $key, string $fallback): string
    {
        $value = trim((string) $this->settings->get(self::GROUP, $key, ''));
        $value = $value !== '' ? $value : $fallback;

        return preg_match('/^v\d{1,2}\.\d{1,2}$/', $value) === 1 ? $value : 'v21.0';
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function nullable(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }
}
