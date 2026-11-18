<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Generation object returned by POST /v1/generations, as published in the Pixelforge API reference.
 *
 * @return array<string, mixed>
 */
function queuedGenerationFixture(): array
{
    return [
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => ['generation_uuid' => 'x'],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ];
}

function generationPayload(array $overrides = []): array
{
    return $overrides + [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_image_urls' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];
}

function fakePixelforge(mixed $generationResponse): void
{
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => $generationResponse,
    ]);
}

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    $this->user = User::factory()->create();
});

it('creates a generation and submits it to Pixelforge with an idempotency key', function () {
    fakePixelforge(Http::response(queuedGenerationFixture(), 202));

    $response = $this->actingAs($this->user)->postJson(route('generations.store'), generationPayload());

    $generation = Generation::sole();

    $response->assertCreated()->assertExactJson(['data' => [
        'uuid' => $generation->uuid,
        'status' => 'queued',
        // Worked example from the Pixelforge docs: 400 chars, 2 reference images, high 1536x1024.
        'estimated_provider_cost' => '0.37387500',
        'estimated_customer_cost' => '0.4673437500',
        'provider_generation_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
    ]]);

    expect($generation->user_id)->toBe($this->user->id)
        ->and($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->reference_image_urls)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($generation->provider_rates_snapshot)->toBe(queuedGenerationFixture()['rates']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/generations')
        && $request->hasHeader('Idempotency-Key', $generation->uuid)
        && $request->data() === [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'metadata' => ['generation_uuid' => $generation->uuid],
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);
});

it('sends no reference_images field when none are given', function () {
    fakePixelforge(Http::response(queuedGenerationFixture(), 202));

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload(['reference_image_urls' => null]))
        ->assertCreated();

    expect(Generation::sole()->reference_image_urls)->toBeNull();

    Http::assertSent(fn (Request $request) => ! str_ends_with($request->url(), '/v1/generations')
        || ! array_key_exists('reference_images', $request->data()));
});

it('requires authentication', function () {
    Http::fake();

    $this->postJson(route('generations.store'), generationPayload())->assertUnauthorized();

    expect(Generation::count())->toBe(0);
    Http::assertNothingSent();
});

it('validates the request without calling Pixelforge', function (array $overrides, string $errorKey) {
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect(Generation::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'invalid size' => [['size' => '2048x2048'], 'size'],
    'invalid quality' => [['quality' => 'ultra'], 'quality'],
    'too many reference images' => [['reference_image_urls' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_image_urls'],
    'invalid reference url' => [['reference_image_urls' => ['not-a-url']], 'reference_image_urls.0'],
    'non-http reference url' => [['reference_image_urls' => ['ftp://example.com/a.png']], 'reference_image_urls.0'],
]);

it('marks the generation failed when Pixelforge rejects the prompt', function () {
    fakePixelforge(Http::response(['error' => [
        'type' => 'invalid_request_error',
        'code' => 'content_policy_violation',
        'message' => 'The prompt was rejected.',
    ]], 400));

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt']);

    $generation = Generation::sole();

    expect($generation->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->failure_reason)->toBe('The prompt was rejected.')
        ->and($generation->provider_generation_id)->toBeNull();
});

it('marks the generation failed and returns 429 when rate limited', function () {
    fakePixelforge(Http::response(['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429));

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload())
        ->assertStatus(429);

    expect(Generation::sole()->status)->toBe(GenerationStatus::FAILED);
});

it('marks the generation failed without exposing the API key when authentication fails', function () {
    fakePixelforge(Http::response(['error' => ['type' => 'authentication_error', 'code' => 'invalid_api_key', 'message' => 'Invalid key.']], 401));

    $response = $this->actingAs($this->user)->postJson(route('generations.store'), generationPayload());

    $response->assertStatus(502);
    expect($response->getContent())->not->toContain('secret-key')
        ->and(Generation::sole()->status)->toBe(GenerationStatus::FAILED);
});

it('marks the generation failed when Pixelforge is overloaded', function () {
    fakePixelforge(Http::response(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.']], 503));

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload())
        ->assertStatus(503);

    expect(Generation::sole()->status)->toBe(GenerationStatus::FAILED);
});

it('keeps the generation queued and sends it only once when the outcome is unknown', function (string $failure) {
    $attempts = 0;

    fakePixelforge(function () use ($failure, &$attempts) {
        $attempts++;

        return $failure === 'timeout'
            ? throw new ConnectionException('timed out')
            : Http::response('boom', 500);
    });

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload())
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.provider_generation_id', null);

    $generation = Generation::sole();

    expect($generation->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->failure_reason)->toBeNull()
        ->and($attempts)->toBe(1);
})->with([
    'timeout' => ['timeout'],
    'internal server error' => ['server_error'],
]);

it('returns 503 and creates nothing when pricing is unavailable', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response('down', 500)]);

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), generationPayload())
        ->assertStatus(503);

    expect(Generation::count())->toBe(0);
});
