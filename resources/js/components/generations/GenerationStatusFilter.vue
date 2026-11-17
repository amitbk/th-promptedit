<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { index } from '@/routes/generations';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    statuses: GenerationStatus[];
    active: GenerationStatus | null;
}>();

const labels: Record<GenerationStatus, string> = {
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const options = computed(() => [
    { value: null, label: 'All' },
    ...props.statuses.map((status) => ({
        value: status,
        label: labels[status],
    })),
]);
</script>

<template>
    <nav
        class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1"
        aria-label="Filter by status"
    >
        <Link
            v-for="option in options"
            :key="option.value ?? 'all'"
            :href="
                index({ query: option.value ? { status: option.value } : {} })
            "
            preserve-scroll
            :aria-current="option.value === active ? 'page' : undefined"
            :class="
                cn(
                    'shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                    option.value === active
                        ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                        : 'border-sidebar-border/70 bg-background text-muted-foreground hover:bg-accent hover:text-accent-foreground dark:border-sidebar-border',
                )
            "
        >
            {{ option.label }}
        </Link>
    </nav>
</template>
