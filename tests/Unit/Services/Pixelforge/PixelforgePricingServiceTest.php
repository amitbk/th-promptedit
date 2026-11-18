<?php

use App\Services\Pixelforge\InvalidPixelforgePricingException;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function fakePricing(?array $body = null, int $status = 200): void
{
    Http::fake(['pixelforge.test/v1/pricing' => Http::response($body ?? pricingFixture(), $status)]);
}

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    config()->set('pixelforge.markup_percent', 25);
});

it('fetches and returns validated live pricing', function () {
    fakePricing();

    expect(app(PixelforgePricingService::class)->getPricing())->toBe(pricingFixture());

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/pricing');
});

it('matches the documented maximum cost worked example', function () {
    fakePricing();

    // High 1536x1024, 400-character prompt, 2 reference images.
    $estimate = app(PixelforgePricingService::class)->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($estimate->tokensMax)->toBe([
        'input_text_tokens' => 115,
        'reasoning_tokens' => 8000,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
    ])
        ->and($estimate->providerCostMax)->toBe('0.373875000')
        ->and($estimate->customerCostMax)->toBe('0.4673437500');
});

it('estimates the minimum with fewer text tokens and minimum reasoning', function () {
    fakePricing();

    $estimate = app(PixelforgePricingService::class)->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

    // 85 text, 1530 image, 6450 output, 1000 reasoning.
    expect($estimate->tokensMin['input_text_tokens'])->toBe(85)
        ->and($estimate->tokensMin['reasoning_tokens'])->toBe(1000)
        ->and($estimate->providerCostMin)->toBe('0.286225000')
        ->and($estimate->customerCostMin)->toBe('0.3577812500');
});

it('counts prompt characters, not bytes, and rounds up to whole tokens', function () {
    fakePricing();

    // 8 multibyte characters = 2 estimated tokens (16 bytes would wrongly be 4).
    $estimate = app(PixelforgePricingService::class)->estimate('éééééééé', '1024x1024', 'low');

    expect($estimate->tokensMin['input_text_tokens'])->toBe(1)
        ->and($estimate->tokensMax['input_text_tokens'])->toBe(3)
        ->and($estimate->tokensMax['input_image_tokens'])->toBe(0);
});

it('keeps provider and customer costs separate', function () {
    fakePricing();

    $estimate = app(PixelforgePricingService::class)->estimate('hello', '1024x1024', 'medium');

    expect($estimate->customerCostMax)->not->toBe($estimate->providerCostMax)
        ->and($estimate->customerCostMax)->toBe(app(PixelforgePricingService::class)->applyMarkup($estimate->providerCostMax));
});

it('computes provider cost exactly from usage and a rate snapshot', function () {
    $service = app(PixelforgePricingService::class);

    // Usage and rates from the documented succeeded generation.
    $cost = $service->providerCost(
        ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
        pricingFixture()['rates'],
    );

    expect($cost)->toBe('0.305510000');
});

it('applies the 25 percent markup with decimal arithmetic at ten decimals', function () {
    $service = app(PixelforgePricingService::class);

    expect($service->applyMarkup('0.305510000'))->toBe('0.3818875000')
        // The markup adds two decimal places, which are kept rather than rounded to the provider's scale.
        ->and($service->applyMarkup('0.12345678'))->toBe('0.1543209750')
        ->and($service->applyMarkup('0.00000001'))->toBe('0.0000000125')
        // Only a provider cost finer than 8 decimals can need rounding (half-up).
        ->and($service->applyMarkup('0.000000001'))->toBe('0.0000000013')
        ->and($service->applyMarkup('0'))->toBe('0.0000000000');
});

it('rejects an unpriced size or quality', function (string $size, string $quality) {
    fakePricing();

    expect(fn () => app(PixelforgePricingService::class)->estimate('hi', $size, $quality))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown size' => ['2048x2048', 'high'],
    'unknown quality' => ['1024x1024', 'ultra'],
]);

it('rejects invalid pricing responses', function (callable $mutate) {
    $body = pricingFixture();
    $mutate($body);
    fakePricing($body);

    expect(fn () => app(PixelforgePricingService::class)->getPricing())
        ->toThrow(InvalidPixelforgePricingException::class);
})->with([
    'wrong object' => [function (&$b) { $b['object'] = 'list'; }],
    'wrong currency' => [function (&$b) { $b['currency'] = 'eur'; }],
    'wrong unit' => [function (&$b) { $b['unit'] = 'per_token'; }],
    'missing rate' => [function (&$b) { unset($b['rates']['output_tokens']); }],
    'float rate' => [function (&$b) { $b['rates']['output_tokens'] = 40.0; }],
    'negative rate' => [function (&$b) { $b['rates']['output_tokens'] = '-40.00'; }],
    'zero characters per token' => [function (&$b) { $b['tokens']['characters_per_text_token'] = 0; }],
    'variance above one' => [function (&$b) { $b['tokens']['text_token_variance'] = 1.5; }],
    'missing image tokens' => [function (&$b) { unset($b['tokens']['per_reference_image']); }],
    'non-integer output tokens' => [function (&$b) { $b['tokens']['output']['low']['1024x1024'] = '280'; }],
    'missing reasoning quality' => [function (&$b) { unset($b['tokens']['reasoning']['high']); }],
    'reasoning min above max' => [function (&$b) { $b['tokens']['reasoning']['low'] = ['min' => 10, 'max' => 5]; }],
    'empty output' => [function (&$b) { $b['tokens']['output'] = []; }],
]);

it('propagates Pixelforge API errors', function () {
    fakePricing(['error' => ['code' => 'overloaded']], 503);

    expect(fn () => app(PixelforgePricingService::class)->getPricing())
        ->toThrow(PixelforgeException::class);
});
