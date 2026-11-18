<script setup lang="ts">
import { CircleX, ImageOff, ImagePlus, LoaderCircle } from '@lucide/vue';
import { ref, watch } from 'vue';
import type { GenerationHistoryItem } from '@/types';

const props = defineProps<{
    generation: GenerationHistoryItem;
}>();

const failedToLoad = ref(false);

watch(
    () => props.generation.image_url,
    () => (failedToLoad.value = false),
);
</script>

<template>
    <div
        class="relative aspect-square overflow-hidden bg-gradient-to-br from-muted to-muted/40"
    >
        <a
            v-if="generation.image_url && !failedToLoad"
            :href="generation.image_url"
            target="_blank"
            rel="noopener"
            class="group block size-full"
        >
            <img
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover transition-transform duration-500 group-hover:scale-105"
                @error="failedToLoad = true"
            />
            <span class="sr-only">Open full-size image</span>
        </a>

        <div
            v-else
            class="flex size-full flex-col items-center justify-center gap-2 p-6 text-center text-muted-foreground"
        >
            <template v-if="generation.image_pending">
                <div class="relative">
                    <ImagePlus class="size-10 opacity-40" aria-hidden="true" />
                    <LoaderCircle
                        class="absolute -right-2 -bottom-2 size-5 animate-spin text-primary"
                        aria-hidden="true"
                    />
                </div>
                <p class="text-sm font-medium text-foreground">
                    Preparing your image
                </p>
                <p class="text-xs">It will appear here in a moment.</p>
            </template>

            <template v-else-if="generation.status === 'failed'">
                <CircleX class="size-10 text-red-500/70" aria-hidden="true" />
                <p class="text-sm font-medium text-foreground">No image</p>
                <p class="text-xs">This generation failed.</p>
            </template>

            <template
                v-else-if="failedToLoad || generation.status === 'succeeded'"
            >
                <ImageOff class="size-10 opacity-40" aria-hidden="true" />
                <p class="text-sm font-medium text-foreground">
                    Image unavailable
                </p>
            </template>

            <template v-else>
                <div
                    class="size-16 animate-pulse rounded-2xl bg-gradient-to-br from-primary/20 to-primary/5"
                    aria-hidden="true"
                />
                <p class="text-sm font-medium text-foreground">
                    {{
                        generation.status === 'queued'
                            ? 'Waiting in line'
                            : 'Creating your image'
                    }}
                </p>
            </template>
        </div>
    </div>
</template>
