<?php

namespace App\Services\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Operator report of what users were charged, what Pixelforge charged us, and the difference.
 *
 * Money comes only from the actual costs recorded when generations finished; estimates are never used.
 * Amounts are summed with BigDecimal, never floats, and returned as exact decimal strings.
 *
 * Money is dated by the UTC day a generation finished, which is how Pixelforge's usage report dates
 * billing. Generation counts by status are dated by the UTC day they were created.
 */
class UsageReportService
{
    /** Scale of provider costs, matching Pixelforge's cost_usd. */
    public const PROVIDER_SCALE = 9;

    /** Scale of customer charges and profit, matching the customer cost columns. */
    public const CUSTOMER_SCALE = 10;

    /**
     * @return array{
     *     summary: array<string, string|int|null>,
     *     statuses: array<string, int>,
     *     daily: list<array<string, string|int>>,
     *     users: list<array<string, string|int>>,
     * }
     */
    public function report(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->utc()->startOfDay();
        $to = $end->utc()->endOfDay();

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[$day->toDateString()] = $this->emptyTotals();
        }

        $users = [];
        $total = $this->emptyTotals();
        $unpriced = 0;

        $this->finishedGenerations($from, $to)->each(function (Generation $generation) use (&$days, &$users, &$total, &$unpriced) {
            $day = $this->finishedAt($generation)->utc()->toDateString();

            if (! isset($days[$day])) {
                return;
            }

            $users[$generation->user_id] ??= $this->emptyTotals();

            if ($generation->status === GenerationStatus::SUCCEEDED
                && ($generation->actual_customer_cost === null || $generation->actual_provider_cost === null)) {
                // Never fall back to the estimate; count it so the gap is visible.
                $unpriced++;
            }

            $this->add($days[$day], $generation);
            $this->add($users[$generation->user_id], $generation);
            $this->add($total, $generation);
        });

        $statuses = $this->statusCounts($from, $to);

        return [
            'summary' => [
                ...$this->present($total),
                'margin_percent' => $this->marginPercent($total),
                'unpriced_generations' => $unpriced,
                'created_generations' => array_sum($statuses),
                'in_progress_generations' => $statuses[GenerationStatus::QUEUED->value] + $statuses[GenerationStatus::PROCESSING->value],
            ],
            'statuses' => $statuses,
            'daily' => array_map(
                fn (string $date, array $totals) => ['date' => $date, ...$this->present($totals)],
                array_keys($days),
                $days,
            ),
            'users' => $this->presentUsers($users),
        ];
    }

    /**
     * Succeeded and failed generations whose finishing UTC day falls in the range.
     *
     * Rejected submissions never get a completed or failed time, so they are dated by creation.
     *
     * @return LazyCollection<int, Generation>
     */
    private function finishedGenerations(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Generation::query()
            ->select(['id', 'user_id', 'status', 'actual_customer_cost', 'actual_provider_cost', 'completed_at', 'failed_at', 'created_at'])
            ->whereIn('status', [GenerationStatus::SUCCEEDED, GenerationStatus::FAILED])
            ->whereBetween(DB::raw('COALESCE(completed_at, failed_at, created_at)'), [$from, $to])
            ->lazyById(500);
    }

    /**
     * Adds one finished generation to a running total. Failed generations are free, whatever was stored on them.
     *
     * @param  array{billed: int, failed: int, customer: BigDecimal, provider: BigDecimal}  $totals
     */
    private function add(array &$totals, Generation $generation): void
    {
        if ($generation->status === GenerationStatus::FAILED) {
            $totals['failed']++;

            return;
        }

        $totals['billed']++;
        $totals['customer'] = $totals['customer']->plus($generation->actual_customer_cost ?? '0');
        $totals['provider'] = $totals['provider']->plus($generation->actual_provider_cost ?? '0');
    }

    private function finishedAt(Generation $generation): CarbonImmutable
    {
        return CarbonImmutable::instance($generation->completed_at ?? $generation->failed_at ?? $generation->created_at);
    }

    /**
     * Generations created in the range, by status, including those still in progress.
     *
     * @return array<string, int>
     */
    private function statusCounts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = Generation::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = [];
        foreach (GenerationStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $statuses;
    }

    /**
     * @param  array<int, array{billed: int, failed: int, customer: BigDecimal, provider: BigDecimal}>  $users
     * @return list<array<string, string|int>>
     */
    private function presentUsers(array $users): array
    {
        $details = User::query()->whereKey(array_keys($users))->get(['id', 'name', 'email'])->keyBy('id');

        $rows = [];
        foreach ($users as $id => $totals) {
            $rows[] = [
                'id' => $id,
                'name' => $details[$id]->name ?? __('Deleted user'),
                'email' => $details[$id]->email ?? '',
                ...$this->present($totals),
            ];
        }

        // Highest charges first; BigDecimal comparison keeps the ordering exact.
        usort($rows, fn (array $a, array $b) => BigDecimal::of($b['customer_charges'])->compareTo($a['customer_charges']) ?: $a['id'] <=> $b['id']);

        return $rows;
    }

    /**
     * @return array{billed: int, failed: int, customer: BigDecimal, provider: BigDecimal}
     */
    private function emptyTotals(): array
    {
        return ['billed' => 0, 'failed' => 0, 'customer' => BigDecimal::zero(), 'provider' => BigDecimal::zero()];
    }

    /**
     * @param  array{billed: int, failed: int, customer: BigDecimal, provider: BigDecimal}  $totals
     * @return array{billed_generations: int, failed_generations: int, customer_charges: string, provider_costs: string, gross_profit: string}
     */
    private function present(array $totals): array
    {
        return [
            'billed_generations' => $totals['billed'],
            'failed_generations' => $totals['failed'],
            'customer_charges' => (string) $totals['customer']->toScale(self::CUSTOMER_SCALE),
            'provider_costs' => (string) $totals['provider']->toScale(self::PROVIDER_SCALE),
            'gross_profit' => (string) $totals['customer']->minus($totals['provider'])->toScale(self::CUSTOMER_SCALE),
        ];
    }

    /**
     * Gross profit as a percentage of customer charges, to one decimal place.
     *
     * @param  array{customer: BigDecimal, provider: BigDecimal}  $totals
     */
    private function marginPercent(array $totals): ?string
    {
        if ($totals['customer']->isZero()) {
            return null;
        }

        return (string) $totals['customer']->minus($totals['provider'])
            ->multipliedBy(100)
            ->dividedBy($totals['customer'], 1, RoundingMode::HalfUp);
    }
}
