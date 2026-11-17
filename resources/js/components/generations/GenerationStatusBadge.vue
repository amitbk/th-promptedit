<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
}>();

const appearance = {
    queued: {
        label: 'Queued',
        icon: Clock,
        spin: false,
        class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    },
    processing: {
        label: 'Processing',
        icon: LoaderCircle,
        spin: true,
        class: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    },
    succeeded: {
        label: 'Succeeded',
        icon: CircleCheck,
        spin: false,
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    },
    failed: {
        label: 'Failed',
        icon: CircleX,
        spin: false,
        class: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    },
} as const;

const current = computed(() => appearance[props.status]);
</script>

<template>
    <Badge variant="outline" :class="['border-transparent', current.class]">
        <component
            :is="current.icon"
            :class="{ 'animate-spin': current.spin }"
            aria-hidden="true"
        />
        {{ current.label }}
    </Badge>
</template>
