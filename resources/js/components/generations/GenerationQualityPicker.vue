<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{
    qualities: string[];
    invalid?: boolean;
}>();

const model = defineModel<string>({ required: true });

const hints: Record<string, string> = {
    low: 'Fastest, cheapest',
    medium: 'Balanced',
    high: 'Most detail',
};
</script>

<template>
    <div
        role="radiogroup"
        aria-label="Quality"
        :aria-invalid="invalid || undefined"
        class="grid grid-cols-3 gap-1 rounded-2xl bg-muted p-1"
    >
        <label
            v-for="quality in qualities"
            :key="quality"
            :class="
                cn(
                    'flex cursor-pointer flex-col items-center rounded-xl px-2 py-2 text-center transition-all focus-within:ring-[3px] focus-within:ring-ring/50',
                    model === quality
                        ? 'bg-background shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
        >
            <input
                v-model="model"
                type="radio"
                name="quality"
                :value="quality"
                class="sr-only"
            />
            <span class="text-sm font-medium capitalize">{{ quality }}</span>
            <span
                v-if="hints[quality]"
                class="text-[11px] text-muted-foreground"
            >
                {{ hints[quality] }}
            </span>
        </label>
    </div>
</template>
