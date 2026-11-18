<script setup lang="ts">
import { RefreshCw, Sparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsd } from '@/lib/money';
import type { GenerationEstimate } from '@/types';

defineProps<{
    estimate: GenerationEstimate | null;
    loading: boolean;
    failed: boolean;
    markupPercent: number;
}>();

defineEmits<{
    retry: [];
}>();
</script>

<template>
    <section
        class="relative overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-br from-primary/10 via-card to-card p-5 shadow-lg shadow-primary/5"
        aria-live="polite"
        :aria-busy="loading"
    >
        <Sparkles
            class="absolute -top-3 -right-3 size-20 text-primary/10"
            aria-hidden="true"
        />

        <!-- Right padding keeps the text clear of the decorative icon. -->
        <div class="relative pr-14">
            <p class="text-sm font-medium text-muted-foreground">
                Estimated cost
            </p>

            <div v-if="loading && !estimate" class="mt-2 space-y-2">
                <Skeleton class="h-9 w-40 rounded-lg" />
                <Skeleton class="h-4 w-56" />
            </div>

            <div v-else-if="failed" class="mt-2 space-y-2">
                <p class="text-sm">We couldn't load pricing right now.</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="$emit('retry')"
                >
                    <RefreshCw />
                    Try again
                </Button>
            </div>

            <div
                v-else-if="estimate"
                class="mt-1 transition-opacity"
                :class="{ 'opacity-50': loading }"
            >
                <p class="text-4xl font-semibold tracking-tight tabular-nums">
                    <span class="text-base font-normal text-muted-foreground">
                        up to
                    </span>
                    {{ formatUsd(estimate.customer_cost_max) }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground tabular-nums">
                    Usually between
                    {{ formatUsd(estimate.customer_cost_min) }} and
                    {{ formatUsd(estimate.customer_cost_max) }}
                </p>
            </div>

            <p v-else class="mt-2 text-sm text-muted-foreground">
                Check your prompt and options to see the cost.
            </p>
        </div>

        <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
            Includes a {{ markupPercent }}% service fee. This is an estimate:
            you're charged the actual cost once your image is ready, which
            depends on how much work it takes. Failed images are free.
        </p>
    </section>
</template>
