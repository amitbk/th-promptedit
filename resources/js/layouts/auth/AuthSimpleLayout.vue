<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const name = usePage().props.name;

const highlights = [
    'See the exact cost before every image',
    'Pay only for what finishes successfully',
    'Your history, always private to you',
];
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <aside
            class="relative hidden overflow-hidden bg-brand-gradient p-12 text-white lg:flex lg:flex-col lg:justify-between"
        >
            <div
                class="pointer-events-none absolute -top-32 -right-32 size-[28rem] rounded-full bg-white/15 blur-3xl"
            />
            <div
                class="pointer-events-none absolute -bottom-40 -left-24 size-[26rem] rounded-full bg-black/20 blur-3xl"
            />
            <Link
                :href="home()"
                class="relative flex items-center gap-2 text-lg font-semibold tracking-tight"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-lg bg-white/20 ring-1 ring-white/30 backdrop-blur"
                >
                    <AppLogoIcon class="size-4.5 fill-current" />
                </span>
                {{ name }}
            </Link>
            <div class="relative max-w-md space-y-6">
                <h2 class="text-4xl leading-tight font-semibold">
                    Turn a sentence into a finished image.
                </h2>
                <ul class="space-y-3 text-white/90">
                    <li
                        v-for="item in highlights"
                        :key="item"
                        class="flex items-center gap-3 text-sm"
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-full bg-white/20"
                        >
                            <Check class="size-3" />
                        </span>
                        {{ item }}
                    </li>
                </ul>
            </div>
            <p class="relative text-xs text-white/70">
                © {{ new Date().getFullYear() }} {{ name }}
            </p>
        </aside>
        <main
            class="flex flex-col items-center justify-center bg-background p-6 md:p-10"
        >
            <div class="w-full max-w-sm animate-page-in">
                <div class="flex flex-col gap-8">
                    <Link
                        :href="home()"
                        class="flex items-center gap-2 lg:hidden"
                    >
                        <AppLogo />
                    </Link>
                    <div class="space-y-2">
                        <h1 class="text-2xl font-semibold">{{ title }}</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
