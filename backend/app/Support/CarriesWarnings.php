<?php

namespace App\Support;

/**
 * Non-persistent, additive warnings attached to a model for the current
 * response only (for example a credit-limit warning). Never stored.
 */
trait CarriesWarnings
{
    /** @var array<int, array<string, mixed>> */
    protected array $transientWarnings = [];

    /** @param  array<int, array<string, mixed>>  $warnings */
    public function withWarnings(array $warnings): static
    {
        $this->transientWarnings = $warnings;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function warnings(): array
    {
        return $this->transientWarnings;
    }
}
