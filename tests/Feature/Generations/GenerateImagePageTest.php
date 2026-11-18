<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    $this->user = User::factory()->create();
});

function fakePricingOnly(int $status = 200): void
{
    Http::fake(['pixelforge.test/v1/pricing' => $status === 200
        ? Http::response(pricingFixture())
        : Http::response('down', $status)]);
}

function estimateQuery(array $overrides = []): array
{
    return $overrides + [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_image_count' => 2,
    ];
}

it('shows the generate page with the documented options', function () {
    $this->actingAs($this->user)
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('limits', ['prompt_length' => 4000, 'reference_images' => 4])
            ->where('markupPercent', 25));
});

it('requires authentication for the page and the estimate', function () {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->getJson(route('generations.estimate', estimateQuery()))->assertUnauthorized();
});

it('estimates the customer cost range with the markup, without provider costs', function () {
    fakePricingOnly();

    $this->actingAs($this->user)
        ->getJson(route('generations.estimate', estimateQuery()))
        ->assertOk()
        // The documented worst case ($0.373875) and its minimum ($0.286225), plus 25%.
        ->assertExactJson(['data' => [
            'customer_cost_min' => '0.3577812500',
            'customer_cost_max' => '0.4673437500',
        ]]);
});

it('changes the estimate when size or quality changes', function () {
    fakePricingOnly();
    $max = fn (array $query) => $this->actingAs($this->user)
        ->getJson(route('generations.estimate', estimateQuery($query + ['reference_image_count' => 0, 'prompt' => 'hi'])))
        ->json('data.customer_cost_max');

    $highLandscape = $max(['size' => '1536x1024', 'quality' => 'high']);
    $highSquare = $max(['size' => '1024x1024', 'quality' => 'high']);
    $lowSquare = $max(['size' => '1024x1024', 'quality' => 'low']);

    // "hi" is 1 estimated text token, up to 2 with the 15% variance. Output and reasoning come
    // from the pricing tables: high 6450/4300 output and 8000 reasoning; low 280 output and 500 reasoning.
    expect($highLandscape)->toBe('0.4475125000')
        ->and($highSquare)->toBe('0.3400125000')
        ->and($lowSquare)->toBe('0.0218250000');
});

it('estimates before a prompt is written', function () {
    fakePricingOnly();

    $this->actingAs($this->user)
        ->getJson(route('generations.estimate', estimateQuery(['prompt' => null, 'reference_image_count' => null])))
        ->assertOk()
        // No text or image tokens: 6450 output and 1000 reasoning tokens at minimum.
        ->assertJsonPath('data.customer_cost_min', '0.3381250000');
});

it('validates estimate input', function (array $overrides, string $field) {
    Http::fake();

    $this->actingAs($this->user)
        ->getJson(route('generations.estimate', estimateQuery($overrides)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'missing size' => [['size' => null], 'size'],
    'unknown size' => [['size' => '2048x2048'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many reference images' => [['reference_image_count' => 5], 'reference_image_count'],
    'negative reference images' => [['reference_image_count' => -1], 'reference_image_count'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
]);

it('returns 503 when pricing is unavailable', function () {
    fakePricingOnly(500);

    $this->actingAs($this->user)
        ->getJson(route('generations.estimate', estimateQuery()))
        ->assertStatus(503)
        ->assertJsonPath('message', 'Pricing is temporarily unavailable. Please try again shortly.');
});

it('reuses fetched pricing for repeated estimates', function () {
    fakePricingOnly();

    foreach (['a', 'ab', 'abc'] as $prompt) {
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', estimateQuery(['prompt' => $prompt])))
            ->assertOk();
    }

    Http::assertSentCount(1);
});

it('does not cache failed pricing', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::sequence()
        ->push('down', 500)
        ->push(pricingFixture())]);

    $this->actingAs($this->user)->getJson(route('generations.estimate', estimateQuery()))->assertStatus(503);
    $this->actingAs($this->user)->getJson(route('generations.estimate', estimateQuery()))->assertOk();
});

it('rejects a second submission while one is still in progress', function () {
    Http::fake();
    $lock = Cache::lock("generations:submitting:{$this->user->id}", 60);
    $lock->get();

    $this->actingAs($this->user)
        ->postJson(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Your previous image is still being submitted. Please wait a moment.');

    expect(Generation::count())->toBe(0);
    Http::assertNothingSent();

    $lock->release();
});

it('does not block other users while one user is submitting', function () {
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => Http::response(['id' => 'gen_other', 'rates' => pricingFixture()['rates']], 202),
    ]);
    Cache::lock("generations:submitting:{$this->user->id}", 60)->get();

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertCreated();
});

it('releases the lock after each submission, with a new idempotency key per generation', function (int $firstStatus) {
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => Http::sequence()
            ->push($firstStatus === 202 ? ['id' => 'gen_1', 'rates' => pricingFixture()['rates']] : ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], $firstStatus)
            ->push(['id' => 'gen_2', 'rates' => pricingFixture()['rates']], 202),
    ]);
    $payload = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'];

    $this->actingAs($this->user)->postJson(route('generations.store'), $payload);
    $this->actingAs($this->user)->postJson(route('generations.store'), $payload)->assertCreated();

    $keys = Http::recorded()
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/v1/generations'))
        ->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])
        ->values();

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1])
        ->and($keys->all())->toEqualCanonicalizing(Generation::pluck('uuid')->all());
})->with([
    'after a success' => [202],
    'after a provider failure' => [429],
]);

it('shows a newly submitted generation at the top of the history', function () {
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pricingFixture()),
        'pixelforge.test/v1/generations' => Http::response(['id' => 'gen_new', 'rates' => pricingFixture()['rates']], 202),
    ]);

    $uuid = $this->actingAs($this->user)
        ->postJson(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertCreated()
        ->json('data.uuid');

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.uuid', $uuid)
            ->where('generations.data.0.status', 'queued'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/generations')
        && array_keys($request->data()) === ['prompt', 'size', 'quality', 'metadata']);
});
