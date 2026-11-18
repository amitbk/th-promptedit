<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Reports\ProviderReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const RECON_RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
// 18×5 + 0×10 + 420×40 + 289×12.5 = 20,502.5 per 1M tokens.
const RECON_USAGE = ['input_text_tokens' => 18, 'input_image_tokens' => 0, 'output_tokens' => 420, 'reasoning_tokens' => 289, 'total_tokens' => 727];

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test');
    config()->set('pixelforge.api_key', 'secret-key');
    config()->set('inertia.ssr.enabled', false);
    $this->user = User::factory()->create(['email' => 'customer@example.com']);
});

/** A generation object as returned by GET /v1/generations. */
function providerItem(string $id, string $status, ?string $uuid, string $completedAt = '2026-11-10T12:00:20Z', array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'object' => 'generation',
        'status' => $status,
        'metadata' => $uuid ? ['generation_uuid' => $uuid] : [],
        'usage' => $status === 'succeeded' ? RECON_USAGE : null,
        'rates' => RECON_RATES,
        'created_at' => CarbonImmutable::parse($completedAt)->subSeconds(20)->toIso8601ZuluString(),
        'completed_at' => $completedAt,
    ];
}

/** A day row as returned by GET /v1/usage. */
function usageDay(string $date, int $generations = 0, string $cost = '0.000000000', ?array $tokens = null): array
{
    $tokens ??= $generations === 0
        ? ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]
        : array_map(fn (int $count) => $count * $generations, array_diff_key(RECON_USAGE, ['total_tokens' => 0]));

    return ['date' => $date, 'generations' => $generations, ...$tokens, 'cost_usd' => $cost];
}

/**
 * @param  list<array<string, mixed>>  $days
 * @param  list<list<array<string, mixed>>>  $pages
 */
function fakePixelforgeUsage(array $days, array $pages = [[]]): void
{
    $listResponses = Http::sequence();
    foreach ($pages as $index => $items) {
        $last = $index === count($pages) - 1;
        $listResponses->push(['object' => 'list', 'data' => $items, 'has_more' => ! $last, 'next_cursor' => $last ? null : "cursor_{$index}"]);
    }

    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'start_date' => $days[0]['date'], 'end_date' => end($days)['date'], 'data' => $days, 'total' => []]),
        'pixelforge.test/v1/generations*' => $listResponses,
    ]);
}

function localSucceeded(User $user, string $providerId, string $completedAt = '2026-11-10 12:00:20', string $cost = '0.02050250', array $attributes = []): Generation
{
    $generation = Generation::create($attributes + [
        'user_id' => $user->id,
        'provider_generation_id' => $providerId,
        'prompt' => 'A lighthouse',
        'size' => '1536x1024',
        'quality' => 'low',
        'status' => GenerationStatus::SUCCEEDED,
        'actual_provider_cost' => $cost,
        'actual_customer_cost' => '0.0256281250',
        'token_usage' => RECON_USAGE,
        'provider_rates_snapshot' => RECON_RATES,
    ]);
    $generation->forceFill(['completed_at' => $completedAt])->save();

    return $generation;
}

function reconcileDay(string $day = '2026-11-10'): array
{
    return app(ProviderReconciliationService::class)->reconcile(CarbonImmutable::parse($day, 'UTC'), CarbonImmutable::parse($day, 'UTC'));
}

it('reports a match when local records equal Pixelforge usage', function () {
    $local = localSucceeded($this->user, 'gen_1');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid)]]);

    $result = reconcileDay();

    expect($result['status'])->toBe('matched')
        ->and($result['complete'])->toBeTrue()
        ->and($result['discrepancies'])->toBe([])
        ->and($result['days'][0]['matched'])->toBeTrue()
        ->and($result['days'][0]['cost_difference'])->toBe('0.000000000')
        ->and($result['totals'])->toBe([
            'local_generations' => 1,
            'provider_generations' => 1,
            'local_cost' => '0.020502500',
            'provider_cost' => '0.020502500',
            'difference' => '0.000000000',
        ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/usage')
        && $request->data() === ['start_date' => '2026-11-10', 'end_date' => '2026-11-10']
        && $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('flags a generation Pixelforge billed that we never recorded', function () {
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_unknown', 'succeeded', null)]]);

    $result = reconcileDay();

    expect($result['status'])->toBe('differences')
        ->and($result['days'][0]['matched'])->toBeFalse()
        ->and($result['days'][0]['generation_difference'])->toBe(-1)
        ->and($result['days'][0]['cost_difference'])->toBe('-0.020502500')
        ->and($result['discrepancies'])->toHaveCount(1)
        ->and($result['discrepancies'][0])->toMatchArray([
            'type' => 'missing_locally',
            'provider_generation_id' => 'gen_unknown',
            'generation_uuid' => null,
            'provider_value' => '0.020502500',
        ]);
});

it('flags a generation stuck locally while Pixelforge billed it, matching it by our uuid', function () {
    $stuck = Generation::create([
        'user_id' => $this->user->id,
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_stuck', 'succeeded', $stuck->uuid)]]);

    $discrepancy = reconcileDay()['discrepancies'][0];

    expect($discrepancy)->toMatchArray([
        'type' => 'status_mismatch',
        'provider_generation_id' => 'gen_stuck',
        'generation_uuid' => $stuck->uuid,
        'user_email' => 'customer@example.com',
        'local_value' => null,
        'provider_value' => '0.020502500',
    ])->and($discrepancy['message'])->toContain('queued');
});

it('flags a generation we charged for that Pixelforge reports as failed', function () {
    $local = localSucceeded($this->user, 'gen_1');
    fakePixelforgeUsage([usageDay('2026-11-10')], [[providerItem('gen_1', 'failed', $local->uuid)]]);

    $result = reconcileDay();

    expect($result['discrepancies'])->toHaveCount(1)
        ->and($result['discrepancies'][0])->toMatchArray(['type' => 'status_mismatch', 'local_value' => '0.020502500', 'provider_value' => '0.000000000'])
        ->and($result['days'][0]['cost_difference'])->toBe('0.020502500');
});

it('flags a recorded cost that differs from Pixelforge usage at its rates', function () {
    $local = localSucceeded($this->user, 'gen_1', cost: '0.02050200');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid)]]);

    $result = reconcileDay();

    expect($result['discrepancies'])->toHaveCount(1)
        ->and($result['discrepancies'][0])->toMatchArray(['type' => 'cost_mismatch', 'local_value' => '0.020502000', 'provider_value' => '0.020502500'])
        ->and($result['days'][0]['cost_difference'])->toBe('-0.000000500');
});

it('flags token usage that differs from Pixelforge', function () {
    $local = localSucceeded($this->user, 'gen_1', attributes: ['token_usage' => ['output_tokens' => 1] + RECON_USAGE]);
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid)]]);

    $result = reconcileDay();

    expect(collect($result['discrepancies'])->pluck('type')->all())->toBe(['usage_mismatch'])
        ->and($result['days'][0]['matched'])->toBeFalse();
});

it('flags a generation dated on a different UTC day than Pixelforge dates it', function () {
    // Ours says just after midnight on the 11th; Pixelforge says late on the 10th.
    $local = localSucceeded($this->user, 'gen_1', '2026-11-11 00:00:01');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid, '2026-11-10T23:59:59Z')]]);

    $result = reconcileDay();

    expect(collect($result['discrepancies'])->pluck('type')->all())->toBe(['date_mismatch'])
        ->and($result['discrepancies'][0])->toMatchArray(['local_value' => '2026-11-11', 'provider_value' => '2026-11-10'])
        ->and($result['days'][0]['generation_difference'])->toBe(-1);
});

it('flags a generation we recorded as billed that Pixelforge does not report', function () {
    localSucceeded($this->user, 'gen_local_only');
    fakePixelforgeUsage([usageDay('2026-11-10')], [[]]);

    expect(reconcileDay()['discrepancies'][0])->toMatchArray([
        'type' => 'not_reported_by_provider',
        'provider_generation_id' => 'gen_local_only',
        'local_value' => '0.020502500',
    ]);
});

it('flags one of our generations billed twice at Pixelforge, and counts each provider generation once', function () {
    $local = localSucceeded($this->user, 'gen_1');
    $copy = providerItem('gen_2', 'succeeded', $local->uuid);
    // gen_1 also appears on both pages, as can happen when new generations shift the cursor.
    fakePixelforgeUsage(
        [usageDay('2026-11-10', 2, '0.041005000')],
        [[providerItem('gen_1', 'succeeded', $local->uuid), $copy], [providerItem('gen_1', 'succeeded', $local->uuid)]],
    );

    $result = reconcileDay();
    $types = collect($result['discrepancies'])->pluck('type');

    expect($types->all())->toBe(['duplicate_at_provider'])
        ->and($result['discrepancies'][0]['provider_generation_id'])->toBe('gen_1, gen_2')
        ->and($result['discrepancies'][0]['message'])->toContain('2 of them billed')
        // Our side counts the generation once; the extra Pixelforge charge shows as a daily difference.
        ->and($result['days'][0]['local']['generations'])->toBe(1)
        ->and($result['days'][0]['cost_difference'])->toBe('-0.020502500');
});

it('pages through the list until it passes the start of the range', function () {
    $local = localSucceeded($this->user, 'gen_1');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [
        [providerItem('gen_newer', 'succeeded', null, '2026-11-12T10:00:00Z')],
        [providerItem('gen_1', 'succeeded', $local->uuid), providerItem('gen_old', 'succeeded', null, '2026-11-01T10:00:00Z')],
        [providerItem('gen_never_fetched', 'succeeded', null, '2026-10-01T10:00:00Z')],
    ]);

    $result = reconcileDay();

    expect($result['status'])->toBe('matched')->and($result['complete'])->toBeTrue();
    Http::assertSentCount(3); // usage, page 1, page 2
});

it('does not report missing provider records when the list was cut short', function () {
    localSucceeded($this->user, 'gen_local');
    $pages = array_fill(0, 21, [providerItem('gen_newer', 'succeeded', null, '2026-11-12T10:00:00Z')]);
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], $pages);

    $result = reconcileDay();

    expect($result['complete'])->toBeFalse()
        ->and(collect($result['discrepancies'])->pluck('type')->all())->not->toContain('not_reported_by_provider');
});

it('reports reconciliation as unavailable when Pixelforge cannot be reached, without changing records', function (int $status) {
    $local = localSucceeded($this->user, 'gen_1');
    Http::fake(['pixelforge.test/*' => Http::response(['error' => ['code' => 'overloaded']], $status)]);

    $result = reconcileDay();

    expect($result['status'])->toBe('unavailable')
        ->and($result['message'])->toContain('could not be loaded')
        ->and($local->refresh()->actual_provider_cost)->toBe('0.02050250');
})->with([401, 429, 503]);

it('rejects an unexpected usage response as unavailable', function () {
    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [['date' => '2026-11-10', 'generations' => 1, 'cost_usd' => 0.02]]]),
        'pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]),
    ]);

    expect(reconcileDay()['status'])->toBe('unavailable');
});

it('never changes local financial records, even when they differ', function () {
    $local = localSucceeded($this->user, 'gen_1', cost: '0.01');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid)]]);
    $before = $local->refresh()->only(['status', 'actual_provider_cost', 'actual_customer_cost', 'token_usage', 'completed_at', 'updated_at']);

    reconcileDay();

    expect($local->refresh()->only(array_keys($before)))->toEqual($before);
});

it('reuses the Pixelforge snapshot for repeated reports of the same range', function () {
    fakePixelforgeUsage([usageDay('2026-11-10')], [[]]);

    reconcileDay();
    reconcileDay();

    Http::assertSentCount(2); // one usage call and one list call in total
});

it('loads reconciliation as a deferred prop on the admin report', function () {
    $local = localSucceeded($this->user, 'gen_1');
    fakePixelforgeUsage([usageDay('2026-11-10', 1, '0.020502500')], [[providerItem('gen_1', 'succeeded', $local->uuid)]]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.usage-report', ['start_date' => '2026-11-10', 'end_date' => '2026-11-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('reconciliation')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('reconciliation.status', 'matched')
                ->where('reconciliation.totals.provider_cost', '0.020502500')));
});
