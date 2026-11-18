<?php

namespace App\Services\Pixelforge;

/**
 * A pre-submission estimate, never the final charge.
 *
 * Text tokens (±variance) and reasoning tokens (a quality-based range) are only known once a
 * generation completes, so the estimate is a minimum/maximum range. Actual billing uses the
 * completed generation's token usage and the rate snapshot stored on that generation.
 *
 * Money values are decimal strings in USD: 9 decimal places for provider costs, 10 for customer costs.
 */
final readonly class PixelforgeCostEstimate
{
    /**
     * @param  array<string, int>  $tokensMin  Keyed like the pricing `rates`.
     * @param  array<string, int>  $tokensMax
     * @param  array<string, string>  $rates  USD per 1M tokens, as returned by Pixelforge.
     */
    public function __construct(
        public array $tokensMin,
        public array $tokensMax,
        public array $rates,
        public string $providerCostMin,
        public string $providerCostMax,
        public string $customerCostMin,
        public string $customerCostMax,
    ) {}
}
