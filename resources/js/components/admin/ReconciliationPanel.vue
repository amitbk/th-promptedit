<script setup lang="ts">
import { CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { DiscrepancyType, Reconciliation } from '@/types';

const props = defineProps<{
    reconciliation: Reconciliation;
}>();

const labels: Record<DiscrepancyType, string> = {
    missing_locally: 'Missing from our records',
    status_mismatch: 'Status differs',
    cost_mismatch: 'Cost differs',
    usage_mismatch: 'Token usage differs',
    date_mismatch: 'Counted on another day',
    not_reported_by_provider: 'Not reported by Pixelforge',
    duplicate_at_provider: 'Billed more than once',
};

const showAllDays = ref(false);

const visibleDays = computed(() =>
    props.reconciliation.days.filter(
        (day) =>
            showAllDays.value ||
            !day.matched ||
            day.local.generations > 0 ||
            day.provider.generations > 0,
    ),
);

const mismatchedDays = computed(
    () => props.reconciliation.days.filter((day) => !day.matched).length,
);

const fetchedAt = computed(() =>
    props.reconciliation.fetched_at
        ? new Date(props.reconciliation.fetched_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : null,
);

/** A signed amount: "+$0.000001" or "-$0.000001", and "$0.00" for zero. */
function signed(amount: string): string {
    const formatted = formatUsd(amount, 9);

    return amount.startsWith('-') || /^0(\.0+)?$/.test(amount)
        ? formatted
        : `+${formatted}`;
}

function shortId(id: string | null): string {
    return id ?? '—';
}
</script>

<template>
    <section
        class="rounded-2xl border border-sidebar-border/70 bg-card shadow-xs dark:border-sidebar-border"
    >
        <div class="space-y-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">
                        Reconciliation with Pixelforge
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Our recorded provider costs compared with GET /v1/usage
                        for this API key.
                        <template v-if="fetchedAt">
                            Pixelforge data from {{ fetchedAt }}.
                        </template>
                    </p>
                </div>
            </div>

            <div
                role="status"
                :class="
                    cn(
                        'flex gap-3 rounded-xl p-4 text-sm',
                        reconciliation.status === 'matched' &&
                            'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
                        reconciliation.status === 'differences' &&
                            'bg-amber-50 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
                        reconciliation.status === 'unavailable' &&
                            'bg-muted text-muted-foreground',
                    )
                "
            >
                <CircleCheck
                    v-if="reconciliation.status === 'matched'"
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <TriangleAlert
                    v-else-if="reconciliation.status === 'differences'"
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <Info
                    v-else
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <div class="space-y-1">
                    <p class="font-medium">
                        <template v-if="reconciliation.status === 'matched'">
                            Our records match Pixelforge's usage.
                        </template>
                        <template
                            v-else-if="reconciliation.status === 'differences'"
                        >
                            {{ reconciliation.discrepancies.length }}
                            generation
                            {{
                                reconciliation.discrepancies.length === 1
                                    ? 'issue'
                                    : 'issues'
                            }}
                            and {{ mismatchedDays }}
                            {{ mismatchedDays === 1 ? 'day' : 'days' }} that
                            differ.
                        </template>
                        <template v-else>{{ reconciliation.message }}</template>
                    </p>
                    <p
                        v-if="reconciliation.status === 'differences'"
                        class="text-xs opacity-80"
                    >
                        Nothing has been changed. Review each difference below
                        before correcting any record.
                    </p>
                    <p
                        v-if="
                            !reconciliation.complete &&
                            reconciliation.status !== 'unavailable'
                        "
                        class="text-xs opacity-80"
                    >
                        Pixelforge's generation list was too long to read in
                        full, so generations we recorded but Pixelforge did not
                        report are not checked. Use a shorter range.
                    </p>
                </div>
            </div>

            <dl
                v-if="reconciliation.totals"
                class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3"
            >
                <div class="rounded-xl bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        Our provider costs
                    </dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">
                        {{ formatUsd(reconciliation.totals.local_cost, 9) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{ reconciliation.totals.local_generations }} billed
                        generations
                    </dd>
                </div>
                <div class="rounded-xl bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        Pixelforge usage
                    </dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">
                        {{ formatUsd(reconciliation.totals.provider_cost, 9) }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{ reconciliation.totals.provider_generations }} billed
                        generations
                    </dd>
                </div>
                <div class="rounded-xl bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        Difference (ours − Pixelforge)
                    </dt>
                    <dd
                        class="mt-0.5 font-semibold tabular-nums"
                        :class="{
                            'text-amber-700 dark:text-amber-300':
                                reconciliation.totals.difference !==
                                '0.000000000',
                        }"
                    >
                        {{ signed(reconciliation.totals.difference) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div v-if="reconciliation.days.length" class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead
                    class="border-y border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground dark:border-sidebar-border"
                >
                    <tr>
                        <th scope="col" class="px-5 py-2.5 font-medium">
                            Day (UTC)
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Generations (ours / Pixelforge)
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Our cost
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Pixelforge cost
                        </th>
                        <th
                            scope="col"
                            class="px-5 py-2.5 text-right font-medium"
                        >
                            Difference
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                >
                    <tr
                        v-for="day in visibleDays"
                        :key="day.date"
                        :class="{
                            'bg-amber-50/60 dark:bg-amber-950/20': !day.matched,
                        }"
                    >
                        <th
                            scope="row"
                            class="px-5 py-2.5 text-left font-medium"
                        >
                            <span class="flex items-center gap-2">
                                <CircleCheck
                                    v-if="day.matched"
                                    class="size-3.5 text-emerald-500"
                                    aria-label="Matches"
                                />
                                <TriangleAlert
                                    v-else
                                    class="size-3.5 text-amber-500"
                                    aria-label="Differs"
                                />
                                {{ day.date }}
                            </span>
                        </th>
                        <td class="px-3 py-2.5 text-right tabular-nums">
                            {{ day.local.generations }} /
                            {{ day.provider.generations }}
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums">
                            {{ formatUsd(day.local.cost, 9) }}
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums">
                            {{ formatUsd(day.provider.cost, 9) }}
                        </td>
                        <td
                            class="px-5 py-2.5 text-right font-medium tabular-nums"
                            :class="{
                                'text-amber-700 dark:text-amber-300':
                                    !day.matched,
                            }"
                        >
                            {{ signed(day.cost_difference) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="px-5 py-3">
                <button
                    type="button"
                    class="text-xs font-medium text-primary underline-offset-4 hover:underline"
                    @click="showAllDays = !showAllDays"
                >
                    {{
                        showAllDays
                            ? 'Show only days with activity or differences'
                            : `Show all ${reconciliation.days.length} days`
                    }}
                </button>
            </div>
        </div>

        <div
            v-if="reconciliation.discrepancies.length"
            class="border-t border-sidebar-border/70 p-5 dark:border-sidebar-border"
        >
            <h3 class="text-sm font-semibold">Generations to review</h3>
            <ul class="mt-3 space-y-3">
                <li
                    v-for="(item, index) in reconciliation.discrepancies"
                    :key="index"
                    class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 text-sm dark:border-amber-900/60 dark:bg-amber-950/20"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/60 dark:text-amber-100"
                        >
                            {{ labels[item.type] ?? item.type }}
                        </span>
                        <code class="text-xs break-all text-muted-foreground">
                            {{ shortId(item.provider_generation_id) }}
                        </code>
                    </div>
                    <p class="mt-2">{{ item.message }}</p>
                    <dl
                        class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-muted-foreground sm:grid-cols-2"
                    >
                        <div v-if="item.user_email" class="flex gap-1">
                            <dt>User:</dt>
                            <dd class="text-foreground">
                                {{ item.user_email }}
                            </dd>
                        </div>
                        <div v-if="item.generation_uuid" class="flex gap-1">
                            <dt>Our generation:</dt>
                            <dd class="break-all text-foreground">
                                {{ item.generation_uuid }}
                            </dd>
                        </div>
                        <div
                            v-if="item.local_value !== null"
                            class="flex gap-1"
                        >
                            <dt>Ours:</dt>
                            <dd class="break-all text-foreground tabular-nums">
                                {{ item.local_value }}
                            </dd>
                        </div>
                        <div
                            v-if="item.provider_value !== null"
                            class="flex gap-1"
                        >
                            <dt>Pixelforge:</dt>
                            <dd class="break-all text-foreground tabular-nums">
                                {{ item.provider_value }}
                            </dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </div>
    </section>
</template>
