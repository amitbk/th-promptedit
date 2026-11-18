<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Gauge,
    Images,
    ReceiptText,
    ShieldCheck,
    Sparkles,
    Wand2,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

const name = usePage().props.name;

const features = [
    {
        icon: ReceiptText,
        title: 'Know the price first',
        text: 'A live estimate updates as you change size, quality and references. No surprises on your invoice.',
    },
    {
        icon: ShieldCheck,
        title: 'Pay for results only',
        text: 'Failed generations are never charged. You are billed the final cost once an image is delivered.',
    },
    {
        icon: Images,
        title: 'A history you can trust',
        text: 'Every image, prompt and charge in one private library, kept in sync as jobs finish.',
    },
    {
        icon: Gauge,
        title: 'Built for speed',
        text: 'Submit, close the tab, come back. Images are stored durably the moment they are ready.',
    },
];

const steps = [
    {
        n: '01',
        title: 'Describe it',
        text: 'Write a prompt and add optional reference images.',
    },
    {
        n: '02',
        title: 'Review the cost',
        text: 'Pick a size and quality and see the maximum price up front.',
    },
    {
        n: '03',
        title: 'Get your image',
        text: 'We generate it, store it, and charge the final amount.',
    },
];
</script>

<template>
    <Head title="AI image generation with transparent pricing" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="sticky top-0 z-30 border-b border-border/60 bg-background/70 backdrop-blur-lg"
        >
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6"
            >
                <Link href="/" class="flex items-center gap-2">
                    <AppLogo />
                </Link>
                <nav class="flex items-center gap-2">
                    <Button variant="ghost" :as="Link" :href="login()">
                        Log in
                    </Button>
                    <Button :as="Link" :href="register()">Get started</Button>
                </nav>
            </div>
        </header>

        <main>
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-0 bg-grid opacity-60"
                />
                <div
                    class="pointer-events-none absolute -top-40 left-1/2 size-[40rem] -translate-x-1/2 rounded-full bg-brand-gradient opacity-20 blur-3xl"
                />
                <div
                    class="relative mx-auto max-w-4xl animate-page-in px-6 pt-24 pb-20 text-center md:pt-32"
                >
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-1 text-xs font-medium text-muted-foreground shadow-xs backdrop-blur"
                    >
                        <Sparkles class="size-3.5 text-primary" />
                        Transparent, pay-per-image pricing
                    </span>
                    <h1
                        class="mt-6 text-5xl leading-[1.05] font-semibold md:text-7xl"
                    >
                        Turn words into
                        <span class="text-brand-gradient">stunning images</span>
                    </h1>
                    <p
                        class="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground"
                    >
                        {{ name }} generates images from a prompt and tells you
                        exactly what each one costs before you create it.
                    </p>
                    <div
                        class="mt-10 flex flex-wrap items-center justify-center gap-3"
                    >
                        <Button
                            size="lg"
                            class="h-12 px-7 text-base"
                            :as="Link"
                            :href="register()"
                        >
                            Start creating
                            <ArrowRight />
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            class="h-12 px-7 text-base"
                            :as="Link"
                            :href="login()"
                        >
                            Log in
                        </Button>
                    </div>
                </div>

                <div class="relative mx-auto max-w-4xl px-6 pb-24">
                    <div
                        class="rounded-3xl border border-border bg-card p-3 shadow-2xl shadow-primary/10"
                    >
                        <div class="grid gap-3 md:grid-cols-[1fr_16rem]">
                            <div
                                class="rounded-2xl border border-border bg-background p-5 text-left"
                            >
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Prompt
                                </p>
                                <p class="mt-2 text-sm leading-relaxed">
                                    A lighthouse on a cliff at dawn, oil
                                    painting, warm light and crashing waves
                                </p>
                                <div
                                    class="mt-4 flex gap-2 text-xs text-muted-foreground"
                                >
                                    <span
                                        class="rounded-full bg-muted px-2.5 py-1"
                                        >1024 × 1024</span
                                    >
                                    <span
                                        class="rounded-full bg-muted px-2.5 py-1"
                                        >Medium quality</span
                                    >
                                </div>
                                <div
                                    class="mt-5 aspect-[16/9] rounded-xl bg-brand-gradient opacity-90"
                                />
                            </div>
                            <div
                                class="flex flex-col justify-between rounded-2xl bg-secondary/60 p-5 text-left"
                            >
                                <div>
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        Estimated cost
                                    </p>
                                    <p
                                        class="mt-1 text-3xl font-semibold tabular-nums"
                                    >
                                        $0.07
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Final cost billed on delivery
                                    </p>
                                </div>
                                <div
                                    class="mt-6 flex h-10 items-center justify-center gap-2 rounded-lg bg-primary text-sm font-medium text-primary-foreground"
                                >
                                    <Wand2 class="size-4" />
                                    Generate image
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-6 py-20">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-semibold md:text-4xl">
                        Everything you need, nothing hidden
                    </h2>
                    <p class="mt-3 text-muted-foreground">
                        Made for teams that care about cost control as much as
                        image quality.
                    </p>
                </div>
                <div class="mt-12 grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="group rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-primary/10"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-accent text-primary transition-colors group-hover:bg-brand-gradient group-hover:text-white"
                        >
                            <component :is="feature.icon" class="size-5" />
                        </span>
                        <h3 class="mt-4 text-lg font-semibold">
                            {{ feature.title }}
                        </h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ feature.text }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="border-y border-border bg-card/50">
                <div class="mx-auto max-w-6xl px-6 py-20">
                    <h2 class="text-center text-3xl font-semibold md:text-4xl">
                        How it works
                    </h2>
                    <div class="mt-12 grid gap-8 md:grid-cols-3">
                        <div v-for="step in steps" :key="step.n">
                            <span
                                class="text-brand-gradient text-4xl font-semibold"
                                >{{ step.n }}</span
                            >
                            <h3 class="mt-3 text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ step.text }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-4xl px-6 py-24">
                <div
                    class="relative overflow-hidden rounded-3xl bg-brand-gradient p-10 text-center text-white md:p-16"
                >
                    <div
                        class="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-white/20 blur-3xl"
                    />
                    <h2 class="relative text-3xl font-semibold md:text-4xl">
                        Create your first image today
                    </h2>
                    <p class="relative mt-3 text-white/80">
                        Sign up in seconds and see your cost before you commit.
                    </p>
                    <Button
                        size="lg"
                        variant="secondary"
                        class="relative mt-8 h-12 px-7 text-base text-primary"
                        :as="Link"
                        :href="register()"
                    >
                        Get started free
                        <ArrowRight />
                    </Button>
                </div>
            </section>
        </main>

        <footer
            class="border-t border-border py-8 text-center text-sm text-muted-foreground"
        >
            © {{ new Date().getFullYear() }} {{ name }}. All rights reserved.
        </footer>
    </div>
</template>
