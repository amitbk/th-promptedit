<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatUsd } from '@/lib/money';
import type { UsageTotals } from '@/types';

const props = defineProps<{
    days: (UsageTotals & { date: string })[];
}>();

const HEIGHT = 220;
const TOP = 12;
const BOTTOM = 28;
const LEFT = 64;
const GROUP = 26;
const BAR = 9;

// Numbers are used only to size the drawing; every value shown as text uses the exact decimal strings.
const points = computed(() =>
    props.days.map((day) => ({
        ...day,
        charges: Number(day.customer_charges),
        costs: Number(day.provider_costs),
        profit: Number(day.gross_profit),
    })),
);

const hasMoney = computed(() =>
    points.value.some((p) => p.charges !== 0 || p.costs !== 0),
);

const maxValue = computed(() =>
    Math.max(...points.value.flatMap((p) => [p.charges, p.costs, p.profit]), 0),
);
const minValue = computed(() =>
    Math.min(...points.value.map((p) => p.profit), 0),
);

const width = computed(() => LEFT + props.days.length * GROUP + 8);
const plotHeight = HEIGHT - TOP - BOTTOM;

function y(value: number): number {
    const span = maxValue.value - minValue.value || 1;

    return TOP + ((maxValue.value - value) / span) * plotHeight;
}

const baseline = computed(() => y(0));

const ticks = computed(() =>
    [0, 1, 2, 3].map((i) => {
        const value =
            minValue.value + ((maxValue.value - minValue.value) * i) / 3;

        return { value, y: y(value) };
    }),
);

const profitLine = computed(() =>
    points.value
        .map((p, i) => `${LEFT + i * GROUP + GROUP / 2},${y(p.profit)}`)
        .join(' '),
);

const labelEvery = computed(() =>
    Math.max(1, Math.ceil(props.days.length / 10)),
);

const active = ref<number | null>(null);

const selected = computed(() => {
    if (active.value !== null) {
        return props.days[active.value];
    }

    // Default to the latest day that had money, so the panel is useful straight away.
    return (
        [...props.days]
            .reverse()
            .find(
                (d) =>
                    d.billed_generations > 0 ||
                    Number(d.customer_charges) !== 0,
            ) ?? null
    );
});

function axisLabel(value: number): string {
    return `$${value.toFixed(Math.abs(value) >= 1 || value === 0 ? 2 : 4)}`;
}

function shortDate(date: string): string {
    return new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
}
</script>

<template>
    <section
        class="rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-xs dark:border-sidebar-border"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Daily revenue and cost</h2>
                <p class="text-xs text-muted-foreground">
                    By the UTC day each generation finished.
                </p>
            </div>
            <ul class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                <li class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-primary" /> Customer
                    charges
                </li>
                <li class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm bg-amber-500" /> Provider
                    costs
                </li>
                <li class="flex items-center gap-1.5">
                    <span class="h-0.5 w-3 rounded bg-emerald-500" /> Gross
                    profit
                </li>
            </ul>
        </div>

        <dl
            v-if="selected"
            class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-muted/50 p-3 text-xs sm:grid-cols-4"
            aria-live="polite"
        >
            <div>
                <dt class="text-muted-foreground">Day (UTC)</dt>
                <dd class="font-medium">{{ selected.date }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Charges</dt>
                <dd class="font-medium tabular-nums">
                    {{ formatUsd(selected.customer_charges, 10) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Provider costs</dt>
                <dd class="font-medium tabular-nums">
                    {{ formatUsd(selected.provider_costs, 9) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    Profit ({{ selected.billed_generations }} billed,
                    {{ selected.failed_generations }} failed)
                </dt>
                <dd class="font-medium tabular-nums">
                    {{ formatUsd(selected.gross_profit, 10) }}
                </dd>
            </div>
        </dl>

        <p
            v-if="!hasMoney"
            class="mt-6 rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            No billed generations in this range.
        </p>

        <div v-else class="-mx-1 mt-4 overflow-x-auto px-1">
            <svg
                :viewBox="`0 0 ${width} ${HEIGHT}`"
                :width="width"
                :height="HEIGHT"
                class="min-w-full text-muted-foreground"
                role="img"
                aria-label="Daily customer charges, provider costs and gross profit"
                @mouseleave="active = null"
            >
                <g v-for="tick in ticks" :key="tick.y">
                    <line
                        :x1="LEFT"
                        :x2="width"
                        :y1="tick.y"
                        :y2="tick.y"
                        class="stroke-border"
                        stroke-dasharray="3 4"
                    />
                    <text
                        :x="LEFT - 8"
                        :y="tick.y + 4"
                        text-anchor="end"
                        class="fill-current text-[10px] tabular-nums"
                    >
                        {{ axisLabel(tick.value) }}
                    </text>
                </g>
                <line
                    :x1="LEFT"
                    :x2="width"
                    :y1="baseline"
                    :y2="baseline"
                    class="stroke-muted-foreground/40"
                />

                <g
                    v-for="(point, i) in points"
                    :key="point.date"
                    class="cursor-pointer"
                    @mouseenter="active = i"
                    @focus="active = i"
                    @click="active = i"
                >
                    <rect
                        :x="LEFT + i * GROUP"
                        :y="TOP"
                        :width="GROUP"
                        :height="plotHeight"
                        :class="
                            active === i ? 'fill-muted/60' : 'fill-transparent'
                        "
                    />
                    <rect
                        :x="LEFT + i * GROUP + GROUP / 2 - BAR - 1"
                        :y="Math.min(y(point.charges), baseline)"
                        :width="BAR"
                        :height="Math.abs(baseline - y(point.charges))"
                        rx="2"
                        class="fill-primary"
                    />
                    <rect
                        :x="LEFT + i * GROUP + GROUP / 2 + 1"
                        :y="Math.min(y(point.costs), baseline)"
                        :width="BAR"
                        :height="Math.abs(baseline - y(point.costs))"
                        rx="2"
                        class="fill-amber-500"
                    />
                    <text
                        v-if="i % labelEvery === 0"
                        :x="LEFT + i * GROUP + GROUP / 2"
                        :y="HEIGHT - 8"
                        text-anchor="middle"
                        class="fill-current text-[10px]"
                    >
                        {{ shortDate(point.date) }}
                    </text>
                    <title>
                        {{ point.date }}: charges
                        {{ formatUsd(point.customer_charges, 10) }}, costs
                        {{ formatUsd(point.provider_costs, 9) }}, profit
                        {{ formatUsd(point.gross_profit, 10) }}
                    </title>
                </g>

                <polyline
                    :points="profitLine"
                    fill="none"
                    class="stroke-emerald-500"
                    stroke-width="2"
                    stroke-linejoin="round"
                    pointer-events="none"
                />
            </svg>
        </div>

        <table class="sr-only">
            <caption>
                Daily figures
            </caption>
            <thead>
                <tr>
                    <th scope="col">Day (UTC)</th>
                    <th scope="col">Customer charges</th>
                    <th scope="col">Provider costs</th>
                    <th scope="col">Gross profit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in days" :key="day.date">
                    <th scope="row">{{ day.date }}</th>
                    <td>{{ formatUsd(day.customer_charges, 10) }}</td>
                    <td>{{ formatUsd(day.provider_costs, 9) }}</td>
                    <td>{{ formatUsd(day.gross_profit, 10) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
