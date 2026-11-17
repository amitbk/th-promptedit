import { useHttp } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import type { MaybeRefOrGetter } from 'vue';
import { ref, toValue } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { GenerationEstimate } from '@/types';

export type GenerationEstimateInput = {
    prompt: string;
    size: string;
    quality: string;
    referenceImageCount: number;
};

/**
 * Keeps a live customer-cost estimate for the generation form, re-fetched (debounced) whenever an
 * input that affects pricing changes. Only the latest request's result is used, so a slow response
 * for old inputs can never overwrite the estimate for the current ones.
 */
export function useGenerationEstimate(
    input: MaybeRefOrGetter<GenerationEstimateInput>,
) {
    const http = useHttp<Record<string, never>, { data: GenerationEstimate }>(
        {},
    );
    const estimate = ref<GenerationEstimate | null>(null);
    const loading = ref(false);
    const failed = ref(false);
    let latestRequest = 0;

    async function refresh(): Promise<void> {
        const { prompt, size, quality, referenceImageCount } = toValue(input);
        const request = ++latestRequest;

        http.cancel();
        loading.value = true;
        failed.value = false;

        try {
            const response = await http.get(
                estimateRoute.url({
                    query: {
                        prompt,
                        size,
                        quality,
                        reference_image_count: referenceImageCount,
                    },
                }),
            );

            if (request === latestRequest) {
                // Undefined when the inputs failed validation, e.g. an over-long prompt.
                estimate.value = response?.data ?? null;
            }
        } catch {
            // A cancelled request belongs to an older input; only the latest one can fail the estimate.
            if (request === latestRequest) {
                estimate.value = null;
                failed.value = true;
            }
        } finally {
            if (request === latestRequest) {
                loading.value = false;
            }
        }
    }

    watchDebounced(() => toValue(input), refresh, {
        debounce: 350,
        deep: true,
        immediate: true,
    });

    return { estimate, loading, failed, refresh };
}
