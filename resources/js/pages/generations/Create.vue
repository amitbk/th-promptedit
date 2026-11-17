<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { LoaderCircle, Wand2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AlertError from '@/components/AlertError.vue';
import GenerationEstimateCard from '@/components/generations/GenerationEstimateCard.vue';
import GenerationQualityPicker from '@/components/generations/GenerationQualityPicker.vue';
import GenerationSizePicker from '@/components/generations/GenerationSizePicker.vue';
import ReferenceImageInputs from '@/components/generations/ReferenceImageInputs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useGenerationEstimate } from '@/composables/useGenerationEstimate';
import { create, index, store } from '@/routes/generations';
import type { CreatedGeneration } from '@/types';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    limits: { prompt_length: number; reference_images: number };
    markupPercent: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate image', href: create() }],
    },
});

const form = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_urls: string[];
    },
    { data: CreatedGeneration }
>({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: props.qualities.includes('medium')
        ? 'medium'
        : (props.qualities[0] ?? 'medium'),
    reference_image_urls: [],
});

const filledReferenceUrls = computed(() =>
    form.reference_image_urls.map((url) => url.trim()).filter(Boolean),
);

const { estimate, loading, failed, refresh } = useGenerationEstimate(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    referenceImageCount: filledReferenceUrls.value.length,
}));

// Field errors keyed like the request ("reference_image_urls.0"); typed loosely for the per-row lookup.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const referenceErrors = computed(() =>
    Object.fromEntries(
        form.reference_image_urls.map((_, i) => [
            i,
            errors.value[`reference_image_urls.${i}`],
        ]),
    ),
);

// A field's error disappears as soon as the user edits it, rather than lingering until the next submit.
watch(
    () => form.prompt,
    () => form.clearErrors('prompt'),
);
watch(
    () => form.size,
    () => form.clearErrors('size'),
);
watch(
    () => form.quality,
    () => form.clearErrors('quality'),
);
watch(
    () => [...form.reference_image_urls],
    () => {
        const keys = Object.keys(form.errors).filter((key) =>
            key.startsWith('reference_image_urls'),
        );

        if (keys.length > 0) {
            form.clearErrors(...(keys as Parameters<typeof form.clearErrors>));
        }
    },
);

// Set synchronously on the first click, before any await, so repeated clicks or Ctrl+Enter
// cannot send a second request. Stays set after success while we navigate away.
const submitting = ref(false);
const submissionError = ref<string | null>(null);

const promptLength = computed(() => [...form.prompt].length);
const canSubmit = computed(
    () => !submitting.value && form.prompt.trim() !== '',
);

function messageFrom(body: string, fallback: string): string {
    try {
        const message = (JSON.parse(body) as { message?: unknown }).message;

        return typeof message === 'string' && message !== ''
            ? message
            : fallback;
    } catch {
        return fallback;
    }
}

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    submitting.value = true;
    submissionError.value = null;
    // Drop empty rows so error indices match the rows the user sees.
    form.reference_image_urls = filledReferenceUrls.value;

    try {
        const created = await form.post(store.url(), {
            onHttpException: (response) => {
                submissionError.value = messageFrom(
                    response.data,
                    'We couldn’t submit your image. Please try again.',
                );
            },
            onNetworkError: () => {
                submissionError.value =
                    'We couldn’t reach the server. Check your connection and try again.';
            },
        });

        if (!created) {
            // Validation errors are now shown beside their fields.
            submitting.value = false;

            return;
        }

        if (created.data.provider_generation_id) {
            toast.success('Your image is on its way.');
        } else {
            toast.info(
                'Submitted. We’re confirming it with the image service; it will update in your history.',
            );
        }

        router.visit(index());
    } catch {
        submitting.value = false;
    }
}
</script>

<template>
    <Head title="Generate image" />

    <form
        class="mx-auto grid w-full max-w-6xl flex-1 gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
        novalidate
        @submit.prevent="submit"
    >
        <div class="space-y-6">
            <Heading
                title="Generate an image"
                description="Describe what you want to see. You'll see the cost before you create it."
            />

            <AlertError
                v-if="submissionError"
                title="Your image wasn't created"
                :errors="[submissionError]"
            />

            <div
                class="space-y-6 rounded-2xl border border-border bg-card p-5 shadow-xs md:p-6"
            >
                <div class="space-y-2">
                    <div class="flex items-end justify-between gap-2">
                        <Label for="prompt">
                            Prompt
                            <span class="text-red-500" aria-hidden="true"
                                >*</span
                            >
                        </Label>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            :class="{
                                'text-red-600':
                                    promptLength > limits.prompt_length,
                            }"
                        >
                            {{ promptLength.toLocaleString() }} /
                            {{ limits.prompt_length.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        v-focus
                        name="prompt"
                        rows="5"
                        required
                        :disabled="submitting"
                        :aria-invalid="errors.prompt ? true : undefined"
                        aria-describedby="prompt-hint"
                        placeholder="A lighthouse on a cliff at dawn, oil painting, warm light and crashing waves"
                        class="w-full resize-y rounded-2xl border border-input bg-transparent px-4 py-3 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30"
                        @keydown.meta.enter.prevent="submit"
                        @keydown.ctrl.enter.prevent="submit"
                    />
                    <p id="prompt-hint" class="text-xs text-muted-foreground">
                        Be specific about the subject, style and lighting.
                        Longer prompts cost slightly more.
                    </p>
                    <InputError :message="errors.prompt" />
                </div>

                <div class="space-y-2">
                    <Label as="span">
                        Size
                        <span class="text-red-500" aria-hidden="true">*</span>
                    </Label>
                    <GenerationSizePicker
                        v-model="form.size"
                        :sizes="sizes"
                        :invalid="!!errors.size"
                    />
                    <InputError :message="errors.size" />
                </div>

                <div class="space-y-2">
                    <Label as="span">
                        Quality
                        <span class="text-red-500" aria-hidden="true">*</span>
                    </Label>
                    <GenerationQualityPicker
                        v-model="form.quality"
                        :qualities="qualities"
                        :invalid="!!errors.quality"
                    />
                    <InputError :message="errors.quality" />
                </div>

                <div class="space-y-2">
                    <Label as="span">
                        Reference images
                        <span class="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <p class="text-xs text-muted-foreground">
                        Links to images whose style you'd like to follow. Each
                        one adds to the cost.
                    </p>
                    <ReferenceImageInputs
                        v-model="form.reference_image_urls"
                        :max="limits.reference_images"
                        :errors="referenceErrors"
                        :disabled="submitting"
                    />
                    <InputError :message="errors.reference_image_urls" />
                </div>
            </div>
        </div>

        <aside class="lg:pt-[4.5rem]">
            <div class="space-y-4 lg:sticky lg:top-6">
                <GenerationEstimateCard
                    :estimate="estimate"
                    :loading="loading"
                    :failed="failed"
                    :markup-percent="markupPercent"
                    @retry="refresh"
                />

                <Button
                    type="submit"
                    size="lg"
                    class="h-12 w-full rounded-xl bg-brand-gradient text-base shadow-lg shadow-primary/30 hover:opacity-95"
                    :disabled="!canSubmit"
                    :aria-busy="submitting"
                >
                    <LoaderCircle v-if="submitting" class="animate-spin" />
                    <Wand2 v-else />
                    {{ submitting ? 'Creating…' : 'Generate image' }}
                </Button>
                <p class="text-center text-xs text-muted-foreground">
                    Tip: press Ctrl + Enter to generate.
                </p>
            </div>
        </aside>
    </form>
</template>
