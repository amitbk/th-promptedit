<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Images, Wand2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import GenerationStatusFilter from '@/components/generations/GenerationStatusFilter.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type {
    GenerationHistoryItem,
    GenerationStatus,
    Paginated,
} from '@/types';

const props = defineProps<{
    generations: Paginated<GenerationHistoryItem>;
    filters: { status: GenerationStatus | null };
    statuses: GenerationStatus[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generation history',
                href: index(),
            },
        ],
    },
});

// Keep refreshing while anything on this page can still change: in progress, or its image is being stored.
const hasActiveGenerations = computed(() =>
    props.generations.data.some(
        (generation) =>
            generation.status === 'queued' ||
            generation.status === 'processing' ||
            generation.image_pending,
    ),
);

const { start, stop } = usePoll(
    5000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasActiveGenerations, (active) => (active ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="Generation history" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title="Generation history"
                    description="Every image you've created, with what it cost."
                />
                <Button :as="Link" :href="create()" class="rounded-full">
                    <Wand2 />
                    New image
                </Button>
            </div>
            <GenerationStatusFilter
                :statuses="statuses"
                :active="filters.status"
            />
        </div>

        <div
            v-if="generations.data.length"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.uuid"
                :generation="generation"
            />
        </div>

        <div
            v-else
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-10 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-2xl bg-brand-gradient text-white shadow-lg shadow-primary/30"
            >
                <Images class="size-7" aria-hidden="true" />
            </div>
            <template v-if="filters.status">
                <p class="font-medium">No {{ filters.status }} generations</p>
                <Button variant="outline" size="sm" :as="Link" :href="index()">
                    Show all generations
                </Button>
            </template>
            <template v-else>
                <p class="font-medium">No generations yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Images you create will show up here with their status and
                    cost.
                </p>
                <Button :as="Link" :href="create()" size="sm">
                    <Wand2 />
                    Generate your first image
                </Button>
            </template>
        </div>

        <Pagination
            :links="generations.meta.links"
            :from="generations.meta.from"
            :to="generations.meta.to"
            :total="generations.meta.total"
        />
    </div>
</template>
