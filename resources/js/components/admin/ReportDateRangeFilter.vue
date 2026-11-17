<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CalendarRange } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { usageReport } from '@/routes/admin';
import type { UsageReportFilters } from '@/types';

const props = defineProps<{
    filters: UsageReportFilters;
}>();

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

const start = ref(props.filters.start_date);
const end = ref(props.filters.end_date);

watch(
    () => props.filters,
    (filters) => {
        start.value = filters.start_date;
        end.value = filters.end_date;
    },
);

const DAY_MS = 86_400_000;

/** Today's date in UTC, the time zone the whole report uses. */
const todayUtc = () => new Date().toISOString().slice(0, 10);
const shiftDays = (date: string, days: number) =>
    new Date(Date.parse(`${date}T00:00:00Z`) + days * DAY_MS)
        .toISOString()
        .slice(0, 10);

const dayCount = computed(() =>
    start.value && end.value
        ? Math.round(
              (Date.parse(`${end.value}T00:00:00Z`) -
                  Date.parse(`${start.value}T00:00:00Z`)) /
                  DAY_MS,
          ) + 1
        : 0,
);

const problem = computed(() => {
    if (!start.value || !end.value) {
        return 'Choose a start and end date.';
    }

    if (dayCount.value < 1) {
        return 'The start date must be on or before the end date.';
    }

    if (dayCount.value > props.filters.max_days) {
        return `The range can cover at most ${props.filters.max_days} days.`;
    }

    return null;
});

const presets = [
    { label: '7 days', days: 7 },
    { label: '30 days', days: 30 },
    { label: '90 days', days: 90 },
];

const activePreset = computed(() =>
    end.value === todayUtc()
        ? (presets.find((p) => p.days === dayCount.value)?.days ?? null)
        : null,
);

function apply(startDate = start.value, endDate = end.value): void {
    if (problem.value && startDate === start.value && endDate === end.value) {
        return;
    }

    router.get(
        usageReport.url({
            query: { start_date: startDate, end_date: endDate },
        }),
        {},
        { preserveScroll: true },
    );
}

function applyPreset(days: number): void {
    const endDate = todayUtc();
    const startDate = shiftDays(endDate, -(days - 1));

    start.value = startDate;
    end.value = endDate;
    apply(startDate, endDate);
}
</script>

<template>
    <form
        class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 shadow-xs lg:flex-row lg:items-end"
        @submit.prevent="apply()"
    >
        <div class="grid flex-1 grid-cols-2 gap-3 sm:max-w-md">
            <div class="grid gap-1.5">
                <Label for="start_date">
                    From
                    <span class="text-red-500" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="start_date"
                    v-model="start"
                    type="date"
                    required
                    :max="end || undefined"
                    :aria-invalid="errors.start_date ? true : undefined"
                    class="rounded-xl"
                />
                <InputError :message="errors.start_date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="end_date">
                    To
                    <span class="text-red-500" aria-hidden="true">*</span>
                </Label>
                <Input
                    id="end_date"
                    v-model="end"
                    type="date"
                    required
                    :min="start || undefined"
                    :aria-invalid="errors.end_date ? true : undefined"
                    class="rounded-xl"
                />
                <InputError :message="errors.end_date" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="preset in presets"
                :key="preset.days"
                type="button"
                :class="
                    cn(
                        'rounded-full border px-3 py-1.5 text-sm font-medium transition-colors',
                        activePreset === preset.days
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                    )
                "
                :aria-pressed="activePreset === preset.days"
                @click="applyPreset(preset.days)"
            >
                {{ preset.label }}
            </button>
            <Button type="submit" class="rounded-full" :disabled="!!problem">
                <CalendarRange />
                Apply
            </Button>
        </div>

        <p
            class="text-xs text-muted-foreground lg:ml-auto lg:max-w-56 lg:text-right"
            :class="{ 'text-red-600 dark:text-red-400': problem }"
            aria-live="polite"
        >
            {{
                problem ?? `${dayCount} day${dayCount === 1 ? '' : 's'}, in UTC`
            }}
        </p>
    </form>
</template>
