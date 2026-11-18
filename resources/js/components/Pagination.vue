<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { PaginationLink } from '@/types';

const props = defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();

// Laravel's first and last links are "previous" and "next"; the rest are pages and "..." gaps.
const previous = computed(() => props.links[0]);
const next = computed(() => props.links[props.links.length - 1]);
const pages = computed(() => props.links.slice(1, -1));
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-col items-center justify-between gap-3 sm:flex-row"
        aria-label="Pagination"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ from }}–{{ to }} of {{ total }}
        </p>

        <div class="flex items-center gap-1">
            <Button
                variant="ghost"
                size="icon-sm"
                :as="previous.url ? Link : 'span'"
                :href="previous.url ?? undefined"
                :disabled="!previous.url"
                :class="{ 'pointer-events-none opacity-40': !previous.url }"
                preserve-scroll
                aria-label="Previous page"
            >
                <ChevronLeft />
            </Button>

            <template v-for="(page, index) in pages" :key="index">
                <span
                    v-if="!page.url"
                    class="px-2 text-sm text-muted-foreground"
                    aria-hidden="true"
                >
                    {{ page.label }}
                </span>
                <Button
                    v-else
                    :variant="page.active ? 'default' : 'ghost'"
                    size="icon-sm"
                    :as="Link"
                    :href="page.url"
                    preserve-scroll
                    :aria-current="page.active ? 'page' : undefined"
                    class="rounded-full tabular-nums"
                >
                    {{ page.label }}
                </Button>
            </template>

            <Button
                variant="ghost"
                size="icon-sm"
                :as="next.url ? Link : 'span'"
                :href="next.url ?? undefined"
                :disabled="!next.url"
                :class="{ 'pointer-events-none opacity-40': !next.url }"
                preserve-scroll
                aria-label="Next page"
            >
                <ChevronRight />
            </Button>
        </div>
    </nav>
</template>
