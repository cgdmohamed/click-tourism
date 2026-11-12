<?php

namespace App\Modules\Integrations\Adapters;

use App\Modules\Integrations\Contracts\MetaLeadFetcher;
use App\Modules\Integrations\Data\MetaLead;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Services\IntegrationSettings;

/**
 * Retrieves a Lead Ads submission from the Graph API.
 *
 * UNVERIFIED against current official docs (no internet access when written):
 *   - Request: GET {graph}/{version}/{leadgen_id}?fields=id,created_time,ad_id,form_id,field_data
 *   - Response: {"id": "...", "created_time": "...", "ad_id": "...", "form_id": "...",
 *               "field_data": [{"name": "email", "values": ["a@b.com"]}, ...]}
 *   - Required token permissions/scopes (e.g. leads_retrieval, pages_manage_metadata,
 *     pages_show_list) and the need for App Review / Advanced Access.
 * Verify at developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving.
 */
class GraphMetaLeadFetcher implements MetaLeadFetcher
{
    public function __construct(
        private readonly GraphApiClient $client,
        private readonly IntegrationSettings $settings,
    ) {}

    public function fetch(string $leadgenId): MetaLead
    {
        if (preg_match('/^\d{1,40}$/', $leadgenId) !== 1) {
            throw new IntegrationException('Invalid leadgen id.', false, 'invalid_leadgen_id');
        }

        $url = sprintf(
            '%s/%s/%s?fields=%s',
            $this->settings->graphBaseUrl(),
            $this->settings->metaGraphVersion(),
            $leadgenId,
            'id,created_time,ad_id,form_id,field_data',
        );

        $data = $this->client->get($url, (string) config('services.meta.access_token'), 'Meta lead fetch');

        $fields = [];
        foreach ((array) ($data['field_data'] ?? []) as $item) {
            $name = (string) ($item['name'] ?? '');
            $values = (array) ($item['values'] ?? []);
            if ($name !== '' && $values !== []) {
                $fields[$name] = implode(', ', array_map('strval', $values));
            }
        }

        return new MetaLead(
            leadgenId: (string) ($data['id'] ?? $leadgenId),
            formId: isset($data['form_id']) ? (string) $data['form_id'] : null,
            pageId: null,
            adId: isset($data['ad_id']) ? (string) $data['ad_id'] : null,
            fields: $fields,
        );
    }
}
