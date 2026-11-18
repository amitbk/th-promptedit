<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { useMounted } from '@vueuse/core';
import { computed } from 'vue';
import GenerationImage from '@/components/generations/GenerationImage.vue';
import GenerationStatusBadge from '@/components/generations/GenerationStatusBadge.vue';
import { formatUsd } from '@/lib/money';
import type { GenerationHistoryItem } from '@/types';

const props = defineProps<{
    generation: GenerationHistoryItem;
}>();

// The page is server-rendered, where the user's locale and time zone are unknown. Render a fixed
// UTC date first so the server and browser output match, then switch to the user's local time.
const isMounted = useMounted();

const createdAt = computed(() =>
    new Intl.DateTimeFormat(
        isMounted.value ? undefined : 'en-GB',
        isMounted.value
            ? { dateStyle: 'medium', timeStyle: 'short' }
            : { dateStyle: 'medium', timeZone: 'UTC' },
    ).format(new Date(props.generation.created_at)),
);

const qualityLabel = computed(
    () =>
        props.generation.quality.charAt(0).toUpperCase() +
        props.generation.quality.slice(1) +
        ' quality',
);

const charged = computed(() => {
    const { status, actual_customer_cost } = props.generation;

    if (status === 'failed') {
        return { value: formatUsd('0'), note: 'Not charged' };
    }

    if (actual_customer_cost !== null) {
        return { value: formatUsd(actual_customer_cost), note: 'Final cost' };
    }

    return { value: '—', note: 'After it finishes' };
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-xs transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-primary/10"
    >
        <div class="relative">
            <GenerationImage :generation="generation" />
            <GenerationStatusBadge
                :status="generation.status"
                class="absolute top-3 left-3 shadow-sm"
            />
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <p
                class="line-clamp-3 text-sm leading-relaxed text-foreground"
                :title="generation.prompt"
            >
                {{ generation.prompt }}
            </p>

            <div class="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                <span class="rounded-full bg-muted px-2 py-0.5">
                    {{ generation.size.replace('x', ' × ') }}
                </span>
                <span class="rounded-full bg-muted px-2 py-0.5">
                    {{ qualityLabel }}
                </span>
            </div>

            <div
                v-if="
                    generation.status === 'failed' && generation.failure_reason
                "
                class="flex gap-2 rounded-xl bg-red-50 p-3 text-xs text-red-700 dark:bg-red-950/50 dark:text-red-300"
                role="note"
            >
                <CircleAlert class="mt-px size-4 shrink-0" aria-hidden="true" />
                <p>{{ generation.failure_reason }}</p>
            </div>

            <dl
                class="mt-auto grid grid-cols-2 gap-2 rounded-xl bg-secondary/60 p-3 text-xs"
            >
                <div>
                    <dt class="text-muted-foreground">Estimate</dt>
                    <dd
                        class="mt-0.5 font-semibold text-foreground tabular-nums"
                    >
                        up to
                        {{ formatUsd(generation.estimated_customer_cost) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd
                        class="mt-0.5 font-semibold tabular-nums"
                        :class="
                            generation.status === 'failed'
                                ? 'text-muted-foreground'
                                : 'text-foreground'
                        "
                    >
                        {{ charged.value }}
                    </dd>
                    <dd class="text-[11px] text-muted-foreground">
                        {{ charged.note }}
                    </dd>
                </div>
            </dl>

            <time
                :datetime="generation.created_at"
                class="text-xs text-muted-foreground"
            >
                {{ createdAt }}
            </time>
        </div>
    </article>
</template>
