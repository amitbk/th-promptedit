<script setup lang="ts">
import { ImagePlus, Link2, X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    max: number;
    /** Validation errors keyed by row index. */
    errors: Record<number, string | undefined>;
    disabled?: boolean;
}>();

const urls = defineModel<string[]>({ required: true });

// Bound rather than written inline: vue-tsc loses the v-for variables when a component in the
// loop has a static attribute containing "//".
const placeholder = 'https://example.com/style.png';

function add(): void {
    urls.value = [...urls.value, ''];
}

function remove(index: number): void {
    urls.value = urls.value.filter((_, i) => i !== index);
}

function update(index: number, value: string | number): void {
    urls.value = urls.value.map((url, i) =>
        i === index ? String(value) : url,
    );
}
</script>

<template>
    <div class="space-y-2">
        <div v-for="(url, index) in urls" :key="index" class="space-y-1">
            <div class="flex items-center gap-2">
                <div class="relative flex-1">
                    <Link2
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        :id="`reference-image-${index}`"
                        :model-value="url"
                        type="url"
                        inputmode="url"
                        :placeholder="placeholder"
                        class="h-10 rounded-xl pl-9"
                        :aria-label="`Reference image URL ${index + 1}`"
                        :aria-invalid="errors[index] ? true : undefined"
                        :disabled="disabled"
                        @update:model-value="update(index, $event)"
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="shrink-0 rounded-full"
                    :aria-label="`Remove reference image ${index + 1}`"
                    :disabled="disabled"
                    @click="remove(index)"
                >
                    <X />
                </Button>
            </div>
            <InputError :message="errors[index]" />
        </div>

        <Button
            v-if="urls.length < max"
            type="button"
            variant="outline"
            class="w-full rounded-xl border-dashed"
            :disabled="disabled"
            @click="add"
        >
            <ImagePlus />
            Add reference image
            <span class="text-muted-foreground">
                ({{ urls.length }}/{{ max }})
            </span>
        </Button>
        <p v-else class="text-xs text-muted-foreground">
            You can add up to {{ max }} reference images.
        </p>
    </div>
</template>
