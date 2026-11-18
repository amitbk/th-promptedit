<script setup lang="ts">
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    statuses: Record<GenerationStatus, number>;
}>();

const segments = [
    { status: 'succeeded', label: 'Succeeded', class: 'bg-emerald-500' },
    { status: 'failed', label: 'Failed', class: 'bg-red-500' },
    { status: 'processing', label: 'Processing', class: 'bg-sky-500' },
    { status: 'queued', label: 'Queued', class: 'bg-slate-400' },
] as const;

const total = computed(() =>
    Object.values(props.statuses).reduce((sum, count) => sum + count, 0),
);

const share = (count: number) =>
    total.value === 0 ? 0 : (count / total.value) * 100;
</script>

<template>
    <section
        class="rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-xs dark:border-sidebar-border"
    >
        <h2 class="text-base font-semibold">Generations by status</h2>
        <p class="text-xs text-muted-foreground">
            {{ total }} created in this range (UTC), including failures.
        </p>

        <div
            class="mt-4 flex h-3 overflow-hidden rounded-full bg-muted"
            role="img"
            :aria-label="
                segments
                    .map((s) => `${s.label}: ${statuses[s.status]}`)
                    .join(', ')
            "
        >
            <span
                v-for="segment in segments"
                :key="segment.status"
                :class="segment.class"
                :style="{ width: `${share(statuses[segment.status])}%` }"
            />
        </div>

        <ul class="mt-4 grid grid-cols-2 gap-3">
            <li
                v-for="segment in segments"
                :key="segment.status"
                class="flex items-center gap-2 text-sm"
            >
                <span
                    :class="['size-2.5 shrink-0 rounded-full', segment.class]"
                    aria-hidden="true"
                />
                <span class="text-muted-foreground">{{ segment.label }}</span>
                <span class="ml-auto font-medium tabular-nums">
                    {{ statuses[segment.status] }}
                </span>
            </li>
        </ul>
    </section>
</template>
