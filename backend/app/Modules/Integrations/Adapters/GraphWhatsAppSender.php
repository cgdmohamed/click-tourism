<?php

namespace App\Modules\Integrations\Adapters;

use App\Modules\Integrations\Contracts\WhatsAppSender;
use App\Modules\Integrations\Data\SentMessage;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Services\IntegrationSettings;

/**
 * WhatsApp Cloud API sender.
 *
 * UNVERIFIED against current official docs (no internet access when written):
 *   - Endpoint: POST {graph}/{version}/{phone-number-id}/messages
 *   - Text body: {"messaging_product":"whatsapp","to":"<digits>","type":"text","text":{"body":"..."}}
 *   - Template body: {"messaging_product":"whatsapp","to":"<digits>","type":"template",
 *       "template":{"name":"...","language":{"code":"en_US"},"components":[...]}}
 *   - Response: {"messages":[{"id":"wamid...."}]}
 *   - Free-form text is only deliverable inside the 24-hour customer service
 *     window; business-initiated messages must be approved templates. Pricing,
 *     template review and rate limits are external dependencies.
 * Verify at developers.facebook.com/docs/whatsapp/cloud-api/reference/messages.
 */
class GraphWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private readonly GraphApiClient $client,
        private readonly IntegrationSettings $settings,
    ) {}

    public function sendText(string $to, string $body): SentMessage
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => false],
        ]);
    }

    public function sendTemplate(string $to, string $templateName, string $language, array $components = []): SentMessage
    {
        $template = ['name' => $templateName, 'language' => ['code' => $language]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): SentMessage
    {
        $phoneNumberId = $this->settings->whatsappPhoneNumberId();
        if ($phoneNumberId === '' || preg_match('/^\d{1,40}$/', $phoneNumberId) !== 1) {
            throw IntegrationException::notConfigured('WhatsApp phone number id');
        }

        $url = sprintf('%s/%s/%s/messages', $this->settings->graphBaseUrl(), $this->settings->whatsappGraphVersion(), $phoneNumberId);
        $data = $this->client->post($url, (string) config('services.whatsapp.access_token'), $payload, 'WhatsApp send');

        $id = (string) ($data['messages'][0]['id'] ?? '');
        if ($id === '') {
            throw new IntegrationException('WhatsApp send returned no message id.', false, 'invalid_response');
        }

        return new SentMessage($id);
    }
}
