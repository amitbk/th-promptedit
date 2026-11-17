<?php

use App\Enums\GenerationStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'whsec_test';
const PROVIDER_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

/**
 * Generation object as documented for a succeeded generation.
 *
 * @return array<string, mixed>
 */
function providerGeneration(string $status, array $overrides = []): array
{
    return $overrides + [
        'id' => PROVIDER_ID,
        'object' => 'generation',
        'status' => $status,
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => [],
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
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => null,
    ];
}

function succeededGeneration(array $overrides = []): array
{
    return providerGeneration('succeeded', $overrides + [
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen.png?expires=1&signature=abc',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'completed_at' => '2026-11-04T14:03:29Z',
    ]);
}

function failedGeneration(array $overrides = []): array
{
    return providerGeneration('failed', $overrides + [
        'error' => [
            'code' => 'internal_error',
            'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
        ],
        'completed_at' => '2026-11-04T14:03:20Z',
    ]);
}

function webhookEvent(string $type, array $generation, string $id = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

function signatureHeader(string $body, ?int $timestamp = null, string $secret = WEBHOOK_SECRET): string
{
    $timestamp ??= now()->timestamp;

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

function deliver(array $event, ?string $signature = null, ?string $body = null): TestResponse
{
    $body ??= json_encode($event);

    return test()->call('POST', route('webhooks.pixelforge', absolute: false), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? signatureHeader($body),
    ], $body);
}

function queuedGeneration(array $attributes = []): Generation
{
    return Generation::create($attributes + [
        'user_id' => User::factory()->create()->id,
        'provider_generation_id' => PROVIDER_ID,
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
    ]);
}

beforeEach(function () {
    config()->set('pixelforge.webhook_secret', WEBHOOK_SECRET);
    Queue::fake();
});

it('accepts a validly signed event without user authentication', function () {
    queuedGeneration();

    deliver(webhookEvent('generation.processing', providerGeneration('processing')))->assertNoContent();

    expect(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::PROCESSED);
});

it('does not require a CSRF token', function () {
    queuedGeneration();
    app()['env'] = 'production';

    deliver(webhookEvent('generation.processing', providerGeneration('processing')))->assertNoContent();
});

it('rejects invalid signatures', function (callable $tamper) {
    queuedGeneration();
    $event = webhookEvent('generation.succeeded', succeededGeneration());
    $body = json_encode($event);

    $response = deliver($event, $tamper($body), $body);

    $response->assertForbidden();
    expect(WebhookEvent::count())->toBe(0)
        ->and(Generation::sole()->status)->toBe(GenerationStatus::QUEUED);
})->with([
    'wrong secret' => [fn (string $body) => signatureHeader($body, secret: 'whsec_other')],
    'body altered after signing' => [fn (string $body) => signatureHeader($body.' ')],
    'timestamp too old' => [fn (string $body) => signatureHeader($body, now()->timestamp - 301)],
    'timestamp in the future' => [fn (string $body) => signatureHeader($body, now()->timestamp + 301)],
    'missing v1' => [fn (string $body) => 't='.now()->timestamp],
    'garbage' => [fn (string $body) => 'nonsense'],
    'empty' => [fn (string $body) => ''],
]);

it('rejects events when no webhook secret is configured', function () {
    config()->set('pixelforge.webhook_secret', null);
    $event = webhookEvent('generation.processing', providerGeneration('processing'));

    deliver($event)->assertForbidden();
});

it('moves a queued generation to processing', function () {
    $generation = queuedGeneration();

    deliver(webhookEvent('generation.processing', providerGeneration('processing')))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::PROCESSING);
});

it('records the actual cost of a succeeded generation from usage and its rate snapshot', function () {
    $generation = queuedGeneration(['estimated_provider_cost' => '0.373875', 'estimated_customer_cost' => '0.46734375']);

    deliver(webhookEvent('generation.succeeded', succeededGeneration()))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->token_usage)->toBe(succeededGeneration()['usage'])
        ->and($generation->provider_rates_snapshot)->toBe(succeededGeneration()['rates'])
        // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 per 1M tokens.
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->actual_customer_cost)->toBe('0.3818875000')
        ->and($generation->image_url)->toBe(succeededGeneration()['image_url'])
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-04T14:03:29+00:00')
        ->and($generation->failed_at)->toBeNull()
        // The estimate is untouched.
        ->and($generation->estimated_provider_cost)->toBe('0.37387500');
});

it('bills the rates in the event, not the rates snapshotted at estimate time', function () {
    $generation = queuedGeneration(['provider_rates_snapshot' => ['output_tokens' => '1.00']]);
    $rates = succeededGeneration()['rates'];
    $rates['output_tokens'] = '50.00';

    deliver(webhookEvent('generation.succeeded', succeededGeneration(['rates' => $rates])))->assertNoContent();

    $generation->refresh();

    expect($generation->provider_rates_snapshot['output_tokens'])->toBe('50.00')
        // 305,510 + 6450×10 = 370,010 per 1M tokens.
        ->and($generation->actual_provider_cost)->toBe('0.37001000');
});

it('charges nothing for a failed generation', function () {
    $generation = queuedGeneration(['estimated_provider_cost' => '0.373875', 'estimated_customer_cost' => '0.46734375']);

    deliver(webhookEvent('generation.failed', failedGeneration()))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::FAILED)
        ->and($generation->actual_provider_cost)->toBe('0.00000000')
        ->and($generation->actual_customer_cost)->toBe('0.0000000000')
        ->and($generation->failure_reason)->toContain('You were not charged')
        ->and($generation->failed_at->toIso8601String())->toBe('2026-11-04T14:03:20+00:00')
        ->and($generation->completed_at)->toBeNull();
});

it('does not process a duplicate delivery twice', function () {
    $generation = queuedGeneration();
    $event = webhookEvent('generation.succeeded', succeededGeneration());

    deliver($event)->assertNoContent();

    // Same event id with a different body: if it were applied again, the cost would change.
    $replay = webhookEvent('generation.succeeded', succeededGeneration([
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 4],
    ]));
    deliver($replay)->assertNoContent();
    deliver($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh()->actual_provider_cost)->toBe('0.30551000')
        ->and(WebhookEvent::sole()->payload['data']['object']['usage']['output_tokens'])->toBe(6450);
});

it('ignores a different event for a generation that is already final', function () {
    $generation = queuedGeneration();

    deliver(webhookEvent('generation.succeeded', succeededGeneration(), 'evt_succeeded'))->assertNoContent();
    deliver(webhookEvent('generation.succeeded', succeededGeneration(['usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 4]]), 'evt_resent'))->assertNoContent();

    expect($generation->refresh()->actual_provider_cost)->toBe('0.30551000')
        ->and(WebhookEvent::where('provider_event_id', 'evt_resent')->sole()->status)->toBe(WebhookEventStatus::IGNORED);
});

it('does not downgrade a succeeded generation when a late processing event arrives', function () {
    $generation = queuedGeneration();

    deliver(webhookEvent('generation.succeeded', succeededGeneration(), 'evt_succeeded'))->assertNoContent();
    deliver(webhookEvent('generation.processing', providerGeneration('processing'), 'evt_late'))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and(WebhookEvent::where('provider_event_id', 'evt_late')->sole()->status)->toBe(WebhookEventStatus::IGNORED);
});

it('does not let a failed event override a succeeded generation', function () {
    $generation = queuedGeneration();

    deliver(webhookEvent('generation.succeeded', succeededGeneration(), 'evt_succeeded'))->assertNoContent();
    deliver(webhookEvent('generation.failed', failedGeneration(), 'evt_failed'))->assertNoContent();

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_customer_cost)->toBe('0.3818875000')
        ->and($generation->failed_at)->toBeNull();
});

it('matches an event that arrives before the provider id is stored using the generation uuid', function () {
    $generation = queuedGeneration(['provider_generation_id' => null]);
    $object = succeededGeneration(['metadata' => ['generation_uuid' => $generation->uuid]]);

    deliver(webhookEvent('generation.succeeded', $object))->assertNoContent();

    $generation->refresh();

    expect($generation->provider_generation_id)->toBe(PROVIDER_ID)
        ->and($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_provider_cost)->toBe('0.30551000');
});

it('keeps an unmatched event and applies it when it is delivered again', function () {
    $event = webhookEvent('generation.succeeded', succeededGeneration());

    deliver($event)->assertNoContent();

    $stored = WebhookEvent::sole();
    expect($stored->status)->toBe(WebhookEventStatus::PENDING)
        ->and($stored->payload)->toBe($event);

    $generation = queuedGeneration();
    deliver($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::PROCESSED)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::SUCCEEDED);
});

it('never matches a generation that belongs to a different provider id through its uuid', function () {
    $generation = queuedGeneration(['provider_generation_id' => 'gen_other']);
    $object = succeededGeneration(['metadata' => ['generation_uuid' => $generation->uuid]]);

    deliver(webhookEvent('generation.succeeded', $object))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::QUEUED)
        ->and(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::PENDING);
});

it('ignores ping and unknown event types', function (string $type) {
    deliver(['id' => 'evt_ping', 'object' => 'event', 'type' => $type, 'data' => []])->assertNoContent();

    expect(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::IGNORED);
})->with(['ping', 'generation.something_new']);

it('marks a malformed succeeded event failed without touching the generation', function (array $overrides) {
    $generation = queuedGeneration();

    deliver(webhookEvent('generation.succeeded', succeededGeneration($overrides)))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::QUEUED)
        ->and($generation->actual_provider_cost)->toBeNull()
        ->and(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::FAILED)
        ->and(WebhookEvent::sole()->error)->not->toBeNull();
})->with([
    'missing usage' => [['usage' => null]],
    'non-integer token count' => [['usage' => ['input_text_tokens' => '12', 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1]]],
    'missing rates' => [['rates' => null]],
    'missing image url' => [['image_url' => null]],
]);

it('rejects a signed payload without an event id or type', function () {
    deliver(['object' => 'event'])->assertUnprocessable();

    expect(WebhookEvent::count())->toBe(0);
});

it('keeps the extra decimal places of the customer cost', function () {
    $generation = queuedGeneration();
    // One text token at $0.01 per 1M tokens is $0.00000001; with 25% markup, $0.0000000125.
    $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 1];
    $rates = array_fill_keys(array_keys(succeededGeneration()['rates']), '0.01');

    deliver(webhookEvent('generation.succeeded', succeededGeneration(['usage' => $usage, 'rates' => $rates])))->assertNoContent();

    $generation->refresh();

    expect($generation->actual_provider_cost)->toBe('0.00000001')
        ->and($generation->actual_customer_cost)->toBe('0.0000000125');
});

it('applies a pending event once the create call stores the provider id', function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');

    // The event carries no metadata, so it cannot be matched until the provider id is known.
    $event = webhookEvent('generation.succeeded', succeededGeneration());
    deliver($event)->assertNoContent();
    expect(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::PENDING);

    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => Http::response(providerGeneration('queued'), 202),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1536x1024', 'quality' => 'high'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded');

    $generation = Generation::sole();

    expect($generation->status)->toBe(GenerationStatus::SUCCEEDED)
        ->and($generation->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->actual_customer_cost)->toBe('0.3818875000')
        ->and(WebhookEvent::sole()->status)->toBe(WebhookEventStatus::PROCESSED);

    // A redelivery of the same event does nothing more.
    deliver($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($generation->refresh()->actual_provider_cost)->toBe('0.30551000');
});

it('retries only pending events that match the generation, and never twice', function () {
    $other = succeededGeneration(['id' => 'gen_other']);
    deliver(webhookEvent('generation.succeeded', $other, 'evt_other'))->assertNoContent();
    deliver(webhookEvent('generation.succeeded', succeededGeneration(), 'evt_mine'))->assertNoContent();

    $generation = queuedGeneration();
    $service = app(PixelforgeWebhookService::class);

    $service->retryPending($generation);

    expect(WebhookEvent::where('provider_event_id', 'evt_mine')->sole()->status)->toBe(WebhookEventStatus::PROCESSED)
        ->and(WebhookEvent::where('provider_event_id', 'evt_other')->sole()->status)->toBe(WebhookEventStatus::PENDING)
        ->and($generation->refresh()->actual_provider_cost)->toBe('0.30551000');

    $processedAt = WebhookEvent::where('provider_event_id', 'evt_mine')->sole()->processed_at;

    $service->retryPending($generation);

    expect(WebhookEvent::where('provider_event_id', 'evt_mine')->sole()->processed_at->equalTo($processedAt))->toBeTrue()
        ->and($generation->refresh()->actual_provider_cost)->toBe('0.30551000');
});

it('queues the image download once when a generation succeeds, not inside the request', function () {
    $generation = queuedGeneration();
    Http::fake();

    deliver(webhookEvent('generation.succeeded', succeededGeneration()))->assertNoContent();

    Queue::assertPushed(StoreGenerationImage::class, 1);
    Queue::assertPushed(StoreGenerationImage::class, fn (StoreGenerationImage $job) => $job->generationId === $generation->id);
    Http::assertNothingSent();
    // Billing is already recorded when the job is queued; the job only adds the stored copy.
    expect($generation->refresh()->actual_provider_cost)->toBe('0.30551000')
        ->and($generation->image_path)->toBeNull();
});

it('does not queue duplicate image downloads for duplicate or later events', function () {
    queuedGeneration();
    $event = webhookEvent('generation.succeeded', succeededGeneration(), 'evt_first');

    deliver($event)->assertNoContent();
    deliver($event)->assertNoContent();
    deliver(webhookEvent('generation.succeeded', succeededGeneration(), 'evt_resent'))->assertNoContent();
    deliver(webhookEvent('generation.processing', providerGeneration('processing'), 'evt_late'))->assertNoContent();

    Queue::assertPushed(StoreGenerationImage::class, 1);
});

it('does not queue an image download for processing, failed or malformed events', function () {
    queuedGeneration();

    deliver(webhookEvent('generation.processing', providerGeneration('processing'), 'evt_processing'))->assertNoContent();
    deliver(webhookEvent('generation.succeeded', succeededGeneration(['image_url' => null]), 'evt_malformed'))->assertNoContent();
    deliver(webhookEvent('generation.failed', failedGeneration(), 'evt_failed'))->assertNoContent();

    Queue::assertNothingPushed();
});

it('queues the image download when a pending event is applied later', function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    deliver(webhookEvent('generation.succeeded', succeededGeneration()))->assertNoContent();
    Queue::assertNothingPushed();

    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => Http::response(providerGeneration('queued'), 202),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1536x1024', 'quality' => 'high'])
        ->assertCreated();

    Queue::assertPushed(StoreGenerationImage::class, 1);
});
