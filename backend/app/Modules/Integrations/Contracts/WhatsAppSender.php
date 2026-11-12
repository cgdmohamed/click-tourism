<?php

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\Data\SentMessage;
use App\Modules\Integrations\Exceptions\IntegrationException;

/** Outbound WhatsApp Cloud API adapter. Phone numbers are digits only. */
interface WhatsAppSender
{
    /** @throws IntegrationException */
    public function sendText(string $to, string $body): SentMessage;

    /**
     * @param  array<int, array<string, mixed>>  $components
     *
     * @throws IntegrationException
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $components = []): SentMessage;
}
