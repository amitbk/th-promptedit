<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{
    sizes: string[];
    invalid?: boolean;
}>();

const model = defineModel<string>({ required: true });

const shapes: Record<string, { label: string; class: string }> = {
    '1024x1024': { label: 'Square', class: 'h-7 w-7' },
    '1536x1024': { label: 'Landscape', class: 'h-6 w-9' },
    '1024x1536': { label: 'Portrait', class: 'h-9 w-6' },
};
</script>

<template>
    <div
        role="radiogroup"
        aria-label="Image size"
        :aria-invalid="invalid || undefined"
        class="grid grid-cols-3 gap-2"
    >
        <label
            v-for="size in sizes"
            :key="size"
            :class="
                cn(
                    'flex cursor-pointer flex-col items-center gap-2 rounded-2xl border p-3 text-center transition-all focus-within:ring-[3px] focus-within:ring-ring/50',
                    model === size
                        ? 'border-primary bg-primary/5 shadow-sm'
                        : 'border-input hover:border-primary/40 hover:bg-accent/50',
                )
            "
        >
            <input
                v-model="model"
                type="radio"
                name="size"
                :value="size"
                class="sr-only"
            />
            <span
                class="flex h-10 items-center justify-center"
                aria-hidden="true"
            >
                <span
                    :class="
                        cn(
                            'rounded-md border-2 transition-colors',
                            shapes[size]?.class ?? 'h-7 w-7',
                            model === size
                                ? 'border-primary bg-primary/15'
                                : 'border-muted-foreground/40',
                        )
                    "
                />
            </span>
            <span class="text-sm font-medium">
                {{ shapes[size]?.label ?? size }}
            </span>
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ size.replace('x', ' × ') }}
            </span>
        </label>
    </div>
</template>
