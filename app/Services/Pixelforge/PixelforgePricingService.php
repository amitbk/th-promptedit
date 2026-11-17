<?php

namespace App\Services\Pixelforge;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class PixelforgePricingService
{
    private const PRICING_PATH = '/v1/pricing';

    private const MONEY_SCALE = 9;

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    private const RATE_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * Fetch the live pricing and validate it against the documented schema.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     * @throws InvalidPixelforgePricingException
     */
    public function getPricing(): array
    {
        $pricing = $this->client->get(self::PRICING_PATH);

        $this->validate($pricing);

        return $pricing;
    }

    /**
     * Estimate the cost range of a generation before it is submitted.
     *
     * @throws PixelforgeException
     * @throws InvalidPixelforgePricingException
     * @throws InvalidArgumentException
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount = 0): PixelforgeCostEstimate
    {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException('Reference image count cannot be negative.');
        }

        $pricing = $this->getPricing();
        $tokens = $pricing['tokens'];

        $outputTokens = Arr::get($tokens, "output.{$quality}.{$size}");
        $reasoning = Arr::get($tokens, "reasoning.{$quality}");

        if ($outputTokens === null || $reasoning === null) {
            throw new InvalidArgumentException("Pixelforge does not price quality '{$quality}' at size '{$size}'.");
        }

        // Documented estimate: ceil(characters / characters_per_text_token), within ±variance.
        $perToken = $tokens['characters_per_text_token'];
        $estimatedText = intdiv(mb_strlen($prompt) + $perToken - 1, $perToken);
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);

        $fixed = [
            'input_image_tokens' => $tokens['per_reference_image'] * $referenceImageCount,
            'output_tokens' => $outputTokens,
        ];

        $tokensMin = [
            'input_text_tokens' => BigDecimal::of($estimatedText)->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor)->toInt(),
            'reasoning_tokens' => $reasoning['min'],
        ] + $fixed;

        $tokensMax = [
            'input_text_tokens' => BigDecimal::of($estimatedText)->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt(),
            'reasoning_tokens' => $reasoning['max'],
        ] + $fixed;

        $providerMin = $this->providerCost($tokensMin, $pricing['rates']);
        $providerMax = $this->providerCost($tokensMax, $pricing['rates']);

        return new PixelforgeCostEstimate(
            tokensMin: $tokensMin,
            tokensMax: $tokensMax,
            rates: $pricing['rates'],
            providerCostMin: $providerMin,
            providerCostMax: $providerMax,
            customerCostMin: $this->applyMarkup($providerMin),
            customerCostMax: $this->applyMarkup($providerMax),
        );
    }

    /**
     * Pixelforge's cost for the given token counts: sum(tokens × rate per 1M) in USD.
     *
     * @param  array<string, int>  $tokens
     * @param  array<string, string>  $rates
     */
    public function providerCost(array $tokens, array $rates): string
    {
        $total = BigDecimal::zero();

        foreach (self::RATE_KEYS as $key) {
            $total = $total->plus(
                BigDecimal::of($tokens[$key] ?? 0)
                    ->multipliedBy($rates[$key])
                    ->dividedByExact(self::TOKENS_PER_RATE_UNIT)
            );
        }

        return (string) $total->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Customer price for a provider cost: cost plus the configured markup, rounded half-up to 9 decimals.
     */
    public function applyMarkup(string $providerCost): string
    {
        $multiplier = BigDecimal::of(100 + (int) config('pixelforge.markup_percent'))->dividedByExact(100);

        return (string) BigDecimal::of($providerCost)
            ->multipliedBy($multiplier)
            ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidPixelforgePricingException
     */
    private function validate(array $data): void
    {
        foreach (['object' => 'pricing', 'currency' => 'usd', 'unit' => 'per_1m_tokens'] as $field => $expected) {
            if (Arr::get($data, $field) !== $expected) {
                throw $this->invalid("'{$field}' must be '{$expected}'.");
            }
        }

        foreach (self::RATE_KEYS as $key) {
            $rate = Arr::get($data, "rates.{$key}");

            if (! is_string($rate) || ! preg_match('/^\d+(\.\d+)?$/', $rate)) {
                throw $this->invalid("'rates.{$key}' must be a non-negative decimal string.");
            }
        }

        $this->requireInt($data, 'tokens.characters_per_text_token', 1);
        $this->requireInt($data, 'tokens.per_reference_image', 0);

        $variance = Arr::get($data, 'tokens.text_token_variance');

        if ((! is_int($variance) && ! is_float($variance)) || $variance < 0 || $variance > 1) {
            throw $this->invalid("'tokens.text_token_variance' must be a number between 0 and 1.");
        }

        $output = Arr::get($data, 'tokens.output');

        if (! is_array($output) || $output === []) {
            throw $this->invalid("'tokens.output' must be a non-empty object.");
        }

        foreach ($output as $quality => $sizes) {
            if (! is_array($sizes) || $sizes === []) {
                throw $this->invalid("'tokens.output.{$quality}' must be a non-empty object.");
            }

            foreach (array_keys($sizes) as $size) {
                $this->requireInt($data, "tokens.output.{$quality}.{$size}", 0);
            }

            $this->requireInt($data, "tokens.reasoning.{$quality}.min", 0);
            $this->requireInt($data, "tokens.reasoning.{$quality}.max", 0);

            if ($data['tokens']['reasoning'][$quality]['min'] > $data['tokens']['reasoning'][$quality]['max']) {
                throw $this->invalid("'tokens.reasoning.{$quality}' min cannot exceed max.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireInt(array $data, string $path, int $min): void
    {
        $value = Arr::get($data, $path);

        if (! is_int($value) || $value < $min) {
            throw $this->invalid("'{$path}' must be an integer >= {$min}.");
        }
    }

    private function invalid(string $reason): InvalidPixelforgePricingException
    {
        return new InvalidPixelforgePricingException("Invalid Pixelforge pricing response: {$reason}");
    }
}
