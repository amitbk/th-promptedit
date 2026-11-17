<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

defineProps<{
    label: string;
    value: string;
    /** Exact value, shown on hover when `value` is rounded for display. */
    exact?: string;
    hint?: string;
    icon: Component;
    tone?: 'default' | 'positive' | 'negative' | 'muted';
}>();
</script>

<template>
    <section
        class="relative overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-xs dark:border-sidebar-border"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-medium text-muted-foreground">{{ label }}</p>
            <span
                :class="
                    cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-xl',
                        tone === 'positive' &&
                            'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                        tone === 'negative' &&
                            'bg-red-500/10 text-red-600 dark:text-red-400',
                        tone === 'muted' && 'bg-muted text-muted-foreground',
                        (!tone || tone === 'default') &&
                            'bg-primary/10 text-primary',
                    )
                "
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>
        </div>
        <p
            class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
            :title="exact"
        >
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">
            {{ hint }}
        </p>
    </section>
</template>
