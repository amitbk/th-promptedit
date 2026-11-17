<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import {
    BadgeDollarSign,
    Images,
    Server,
    TrendingUp,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import DailyFinanceChart from '@/components/admin/DailyFinanceChart.vue';
import GenerationStatusBreakdown from '@/components/admin/GenerationStatusBreakdown.vue';
import ReconciliationPanel from '@/components/admin/ReconciliationPanel.vue';
import ReportDateRangeFilter from '@/components/admin/ReportDateRangeFilter.vue';
import ReportStatCard from '@/components/admin/ReportStatCard.vue';
import UserBreakdownTable from '@/components/admin/UserBreakdownTable.vue';
import Heading from '@/components/Heading.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsd } from '@/lib/money';
import { usageReport } from '@/routes/admin';
import type { Reconciliation, UsageReport, UsageReportFilters } from '@/types';

const props = defineProps<{
    filters: UsageReportFilters;
    report: UsageReport;
    reconciliation?: Reconciliation;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage & profit', href: usageReport() }],
    },
});

const summary = computed(() => props.report.summary);
const profitNegative = computed(() =>
    summary.value.gross_profit.startsWith('-'),
);
</script>

<template>
    <Head title="Usage & profit" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Usage & profit"
            description="What users were charged, what Pixelforge charged us, and the difference. All dates are UTC."
        />

        <ReportDateRangeFilter :filters="filters" />

        <div
            v-if="summary.unpriced_generations > 0"
            class="flex gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
            role="alert"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                {{ summary.unpriced_generations }} succeeded
                {{
                    summary.unpriced_generations === 1
                        ? 'generation has'
                        : 'generations have'
                }}
                no recorded actual cost and
                {{ summary.unpriced_generations === 1 ? 'is' : 'are' }}
                counted as $0. Estimates are never used in this report.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <ReportStatCard
                label="Customer charges"
                :value="formatUsd(summary.customer_charges)"
                :exact="summary.customer_charges"
                :hint="`${summary.billed_generations} billed generations`"
                :icon="BadgeDollarSign"
            />
            <ReportStatCard
                label="Provider costs"
                :value="formatUsd(summary.provider_costs)"
                :exact="summary.provider_costs"
                hint="What Pixelforge charged us"
                :icon="Server"
                tone="muted"
            />
            <ReportStatCard
                label="Gross profit"
                :value="formatUsd(summary.gross_profit)"
                :exact="summary.gross_profit"
                :hint="
                    summary.margin_percent !== null
                        ? `${summary.margin_percent}% margin`
                        : 'No charges in this range'
                "
                :icon="TrendingUp"
                :tone="profitNegative ? 'negative' : 'positive'"
            />
            <ReportStatCard
                label="Generations"
                :value="String(summary.created_generations)"
                :hint="`${report.statuses.failed} failed, ${summary.in_progress_generations} in progress`"
                :icon="Images"
            />
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <DailyFinanceChart :days="report.daily" />
            <GenerationStatusBreakdown :statuses="report.statuses" />
        </div>

        <UserBreakdownTable :users="report.users" />

        <Deferred data="reconciliation">
            <template #fallback>
                <section
                    class="space-y-3 rounded-2xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
                    aria-busy="true"
                >
                    <p class="text-base font-semibold">
                        Reconciliation with Pixelforge
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Loading Pixelforge's usage…
                    </p>
                    <Skeleton class="h-14 w-full rounded-xl" />
                    <div class="grid gap-3 sm:grid-cols-3">
                        <Skeleton class="h-16 rounded-xl" />
                        <Skeleton class="h-16 rounded-xl" />
                        <Skeleton class="h-16 rounded-xl" />
                    </div>
                </section>
            </template>

            <ReconciliationPanel
                v-if="reconciliation"
                :reconciliation="reconciliation"
            />
        </Deferred>

        <p class="text-xs text-muted-foreground">
            Charges and costs come only from the actual amounts recorded when
            each generation finished, dated by the UTC day it finished, as in
            Pixelforge's usage report. Failed generations count as $0.
            Generation counts are by the UTC day they were created.
        </p>
    </div>
</template>
