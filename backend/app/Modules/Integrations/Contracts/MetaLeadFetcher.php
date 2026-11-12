<?php

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\Data\MetaLead;
use App\Modules\Integrations\Exceptions\IntegrationException;

/** Fetches the details of a Meta Lead Ads submission by its leadgen id. */
interface MetaLeadFetcher
{
    /** @throws IntegrationException */
    public function fetch(string $leadgenId): MetaLead;
}
