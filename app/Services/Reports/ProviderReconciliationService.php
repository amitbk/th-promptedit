<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgePricingService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Compares our recorded provider costs with what Pixelforge says it billed. Read-only: differences
 * are reported for an operator to investigate, and no financial record is ever changed here.
 *
 * Two levels: daily totals from GET /v1/usage (Pixelforge's source of truth for this API key, dated
 * by UTC completion day), and individual generations from GET /v1/generations, matched to ours by
 * provider id or by the generation_uuid we send as metadata.
 */
class ProviderReconciliationService
{
    private const USAGE_PATH = '/v1/usage';

    private const GENERATIONS_PATH = '/v1/generations';

    private const PAGE_SIZE = 100;

    /** Upper bound on list pages per reconciliation (2,000 generations), to stay well inside the rate limit. */
    private const MAX_PAGES = 20;

    /** Generations finish minutes after creation, so look this far before the range for ones completing in it. */
    private const LOOKBACK_DAYS = 1;

    private const CACHE_SECONDS = 300;

    private const TOKEN_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PixelforgePricingService $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->utc()->startOfDay();
        $to = $end->utc()->endOfDay();

        try {
            $snapshot = $this->providerSnapshot($from, $to);
        } catch (PixelforgeException|UnexpectedValueException $e) {
            Log::warning('Pixelforge usage could not be fetched for reconciliation.', ['reason' => $e->getMessage()]);

            return [
                'status' => 'unavailable',
                'message' => __('Pixelforge usage could not be loaded, so costs were not reconciled. Try again shortly.'),
                'fetched_at' => null,
                'complete' => false,
                'days' => [],
                'totals' => null,
                'discrepancies' => [],
            ];
        }

        $local = $this->localBilled($from, $to);
        $days = $this->compareDays($snapshot['usage'], $local);
        $discrepancies = $this->compareGenerations($snapshot['generations'], $snapshot['complete'], $local, $from, $to);

        $localCost = $local->reduce(fn (BigDecimal $sum, Generation $g) => $sum->plus($g->actual_provider_cost ?? '0'), BigDecimal::zero());
        $providerCost = collect($snapshot['usage'])->reduce(fn (BigDecimal $sum, array $day) => $sum->plus($day['cost_usd']), BigDecimal::zero());
        $matched = $discrepancies === [] && collect($days)->every(fn (array $day) => $day['matched']);

        return [
            'status' => $matched ? 'matched' : 'differences',
            'message' => null,
            'fetched_at' => $snapshot['fetched_at'],
            'complete' => $snapshot['complete'],
            'days' => $days,
            'totals' => [
                'local_generations' => $local->count(),
                'provider_generations' => collect($snapshot['usage'])->sum('generations'),
                'local_cost' => $this->money($localCost),
                'provider_cost' => $this->money($providerCost),
                'difference' => $this->money($localCost->minus($providerCost)),
            ],
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Pixelforge's view of the range, fetched once and briefly cached so reloading the report does not
     * spend the per-minute request limit. Only complete, validated snapshots are cached.
     *
     * @return array{usage: list<array<string, mixed>>, generations: list<array<string, mixed>>, complete: bool, fetched_at: string}
     *
     * @throws PixelforgeException
     * @throws UnexpectedValueException
     */
    private function providerSnapshot(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = 'pixelforge.reconciliation.'.$from->toDateString().'.'.$to->toDateString();

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($from, $to) {
            [$generations, $complete] = $this->fetchGenerations($from->subDays(self::LOOKBACK_DAYS));

            return [
                'usage' => $this->fetchUsage($from, $to),
                'generations' => $generations,
                'complete' => $complete,
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws PixelforgeException
     * @throws UnexpectedValueException
     */
    private function fetchUsage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = $this->client->get(self::USAGE_PATH, [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ]);

        if (($response['object'] ?? null) !== 'usage' || ! is_array($response['data'] ?? null)) {
            throw new UnexpectedValueException('Usage response is not a usage object.');
        }

        foreach ($response['data'] as $day) {
            $valid = is_array($day)
                && is_string($day['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day['date'])
                && is_int($day['generations'] ?? null)
                && is_string($day['cost_usd'] ?? null) && preg_match('/^\d+(\.\d+)?$/', $day['cost_usd'])
                && collect(self::TOKEN_KEYS)->every(fn (string $key) => is_int($day[$key] ?? null));

            if (! $valid) {
                throw new UnexpectedValueException('Usage response has an invalid day.');
            }
        }

        return array_values($response['data']);
    }

    /**
     * Our generations at Pixelforge, newest first, back to the given creation time. Deduplicated by id,
     * so a generation repeated across pages is never counted twice.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} The generations, and whether the list is complete.
     *
     * @throws PixelforgeException
     */
    private function fetchGenerations(CarbonImmutable $createdSince): array
    {
        $generations = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->get(self::GENERATIONS_PATH, array_filter([
                'limit' => self::PAGE_SIZE,
                'cursor' => $cursor,
            ]));

            $reachedStart = false;

            foreach ($response['data'] ?? [] as $generation) {
                if (! is_array($generation) || ! is_string($generation['id'] ?? null) || ! is_string($generation['created_at'] ?? null)) {
                    continue;
                }

                if (CarbonImmutable::parse($generation['created_at'])->lessThan($createdSince)) {
                    $reachedStart = true;

                    continue;
                }

                $generations[$generation['id']] = $generation;
            }

            $cursor = $response['next_cursor'] ?? null;

            if ($reachedStart || ! ($response['has_more'] ?? false) || ! is_string($cursor)) {
                return [array_values($generations), true];
            }
        }

        return [array_values($generations), false];
    }

    /**
     * Our succeeded generations completed in the range: what we recorded as billed by Pixelforge.
     *
     * @return Collection<int, Generation>
     */
    private function localBilled(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Generation::query()
            ->with('user:id,email')
            ->where('status', GenerationStatus::SUCCEEDED)
            ->whereBetween('completed_at', [$from, $to])
            ->get(['id', 'uuid', 'user_id', 'provider_generation_id', 'status', 'actual_provider_cost', 'token_usage', 'completed_at']);
    }

    /**
     * @param  list<array<string, mixed>>  $usage
     * @param  Collection<int, Generation>  $local
     * @return list<array<string, mixed>>
     */
    private function compareDays(array $usage, Collection $local): array
    {
        $localByDay = $local->groupBy(fn (Generation $g) => $g->completed_at->utc()->toDateString());

        return array_map(function (array $day) use ($localByDay) {
            $generations = $localByDay->get($day['date'], collect());

            $ours = ['generations' => $generations->count()];
            foreach (self::TOKEN_KEYS as $key) {
                $ours[$key] = $generations->sum(fn (Generation $g) => (int) ($g->token_usage[$key] ?? 0));
            }
            $ours['cost'] = $this->money($generations->reduce(fn (BigDecimal $sum, Generation $g) => $sum->plus($g->actual_provider_cost ?? '0'), BigDecimal::zero()));

            $theirs = Arr::only($day, ['generations', ...self::TOKEN_KEYS]) + ['cost' => $this->money(BigDecimal::of($day['cost_usd']))];
            $difference = BigDecimal::of($ours['cost'])->minus($theirs['cost']);

            return [
                'date' => $day['date'],
                'matched' => $difference->isZero() && Arr::except($ours, 'cost') == Arr::except($theirs, 'cost'),
                'local' => $ours,
                'provider' => $theirs,
                'cost_difference' => $this->money($difference),
                'generation_difference' => $ours['generations'] - $theirs['generations'],
            ];
        }, $usage);
    }

    /**
     * @param  list<array<string, mixed>>  $providerGenerations
     * @param  Collection<int, Generation>  $localBilled
     * @return list<array<string, string|null>>
     */
    private function compareGenerations(array $providerGenerations, bool $complete, Collection $localBilled, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Only finished generations count; billing is dated by when they completed.
        $finished = collect($providerGenerations)->filter(fn (array $p) => in_array($p['status'] ?? null, ['succeeded', 'failed'], true)
            && is_string($p['completed_at'] ?? null)
            && CarbonImmutable::parse($p['completed_at'])->betweenIncluded($from, $to));

        $uuids = $finished->map(fn (array $p) => $p['metadata']['generation_uuid'] ?? null)->filter()->values();
        $known = Generation::query()
            ->with('user:id,email')
            ->where(fn ($query) => $query->whereIn('provider_generation_id', $finished->pluck('id'))->orWhereIn('uuid', $uuids))
            ->get();
        $byProviderId = $known->whereNotNull('provider_generation_id')->keyBy('provider_generation_id');
        $byUuid = $known->keyBy('uuid');

        $discrepancies = [];

        // One local generation submitted more than once at Pixelforge would be billed more than once.
        foreach ($finished->groupBy(fn (array $p) => $p['metadata']['generation_uuid'] ?? $p['id']) as $uuid => $copies) {
            if ($copies->count() > 1) {
                $billed = $copies->where('status', 'succeeded')->count();
                $discrepancies[] = $this->discrepancy(
                    'duplicate_at_provider',
                    $copies->pluck('id')->implode(', '),
                    $byUuid->get($uuid),
                    __(':count Pixelforge generations share one of our generations, :billed of them billed.', ['count' => $copies->count(), 'billed' => $billed])
                );
            }
        }

        foreach ($finished as $provider) {
            $local = $byProviderId->get($provider['id']) ?? $byUuid->get($provider['metadata']['generation_uuid'] ?? '');
            $succeeded = $provider['status'] === 'succeeded';

            if ($local === null) {
                if ($succeeded) {
                    $discrepancies[] = $this->discrepancy(
                        'missing_locally',
                        $provider['id'],
                        null,
                        __('Pixelforge billed this generation, but we have no record of it. It may belong to another API key or environment.'),
                        providerValue: $this->providerCost($provider)
                    );
                }

                continue;
            }

            if ($local->status->value !== $provider['status']) {
                $discrepancies[] = $this->discrepancy(
                    'status_mismatch',
                    $provider['id'],
                    $local,
                    $succeeded
                        ? __('Pixelforge billed this generation but ours is :status, so the customer was not charged. The success webhook was probably not received.', ['status' => $local->status->value])
                        : __('Pixelforge reports this generation as failed but ours is :status.', ['status' => $local->status->value]),
                    $local->status === GenerationStatus::SUCCEEDED ? $this->money(BigDecimal::of($local->actual_provider_cost ?? '0')) : null,
                    $succeeded ? $this->providerCost($provider) : $this->money(BigDecimal::zero())
                );

                continue;
            }

            if (! $succeeded) {
                continue;
            }

            $expected = $this->providerCost($provider);
            $recorded = $this->money(BigDecimal::of($local->actual_provider_cost ?? '0'));

            if ($expected === null || BigDecimal::of($recorded)->compareTo($expected) !== 0) {
                $discrepancies[] = $this->discrepancy(
                    'cost_mismatch',
                    $provider['id'],
                    $local,
                    __('Our recorded provider cost differs from Pixelforge\'s usage at its rates.'),
                    $recorded,
                    $expected
                );
            }

            if (Arr::only($local->token_usage ?? [], self::TOKEN_KEYS) != Arr::only($provider['usage'] ?? [], self::TOKEN_KEYS)) {
                $discrepancies[] = $this->discrepancy(
                    'usage_mismatch',
                    $provider['id'],
                    $local,
                    __('Our recorded token usage differs from Pixelforge\'s.'),
                    json_encode(Arr::only($local->token_usage ?? [], self::TOKEN_KEYS)),
                    json_encode(Arr::only($provider['usage'] ?? [], self::TOKEN_KEYS))
                );
            }

            $providerDay = CarbonImmutable::parse($provider['completed_at'])->utc()->toDateString();
            $localDay = $local->completed_at?->utc()->toDateString();

            if ($localDay !== $providerDay) {
                $discrepancies[] = $this->discrepancy(
                    'date_mismatch',
                    $provider['id'],
                    $local,
                    __('Completed on a different UTC day, so it is counted on another day than Pixelforge counts it.'),
                    $localDay,
                    $providerDay
                );
            }
        }

        if ($complete) {
            $reported = $finished->pluck('id')->flip();

            foreach ($localBilled as $local) {
                if ($local->provider_generation_id === null || ! $reported->has($local->provider_generation_id)) {
                    $discrepancies[] = $this->discrepancy(
                        'not_reported_by_provider',
                        $local->provider_generation_id,
                        $local,
                        __('We recorded a provider cost for this generation, but Pixelforge does not report it as completed in this range.'),
                        $this->money(BigDecimal::of($local->actual_provider_cost ?? '0')),
                        null
                    );
                }
            }
        }

        return $discrepancies;
    }

    /**
     * What Pixelforge billed for one generation: its reported usage at its own rates.
     *
     * @param  array<string, mixed>  $provider
     */
    private function providerCost(array $provider): ?string
    {
        $usage = $provider['usage'] ?? null;
        $rates = $provider['rates'] ?? null;

        $valid = is_array($usage) && is_array($rates) && collect(PixelforgePricingService::RATE_KEYS)->every(
            fn (string $key) => is_int($usage[$key] ?? null) && is_string($rates[$key] ?? null) && preg_match('/^\d+(\.\d+)?$/', $rates[$key]),
        );

        return $valid ? $this->pricing->providerCost($usage, $rates) : null;
    }

    /**
     * @return array<string, string|null>
     */
    private function discrepancy(string $type, ?string $providerId, ?Generation $local, string $message, ?string $localValue = null, ?string $providerValue = null): array
    {
        return [
            'type' => $type,
            'provider_generation_id' => $providerId,
            'generation_uuid' => $local?->uuid,
            'user_email' => $local?->user?->email,
            'message' => $message,
            'local_value' => $localValue,
            'provider_value' => $providerValue,
        ];
    }

    private function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(UsageReportService::PROVIDER_SCALE);
    }
}
