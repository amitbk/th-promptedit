<script setup lang="ts">
import { Users } from '@lucide/vue';
import { formatUsd } from '@/lib/money';
import type { UsageReport } from '@/types';

defineProps<{
    users: UsageReport['users'];
}>();

const isNegative = (amount: string) => amount.startsWith('-');
</script>

<template>
    <section
        class="rounded-2xl border border-sidebar-border/70 bg-card shadow-xs dark:border-sidebar-border"
    >
        <div class="flex items-center justify-between gap-3 p-5 pb-3">
            <div>
                <h2 class="text-base font-semibold">By user</h2>
                <p class="text-xs text-muted-foreground">
                    Users with finished generations in this range, highest
                    charges first.
                </p>
            </div>
            <Users class="size-4 text-muted-foreground" aria-hidden="true" />
        </div>

        <p
            v-if="users.length === 0"
            class="m-5 mt-2 rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            No finished generations in this range.
        </p>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead
                    class="border-y border-sidebar-border/70 bg-muted/40 text-left text-xs text-muted-foreground dark:border-sidebar-border"
                >
                    <tr>
                        <th scope="col" class="px-5 py-2.5 font-medium">
                            User
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Billed
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Failed
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Charges
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Provider costs
                        </th>
                        <th
                            scope="col"
                            class="px-5 py-2.5 text-right font-medium"
                        >
                            Gross profit
                        </th>
                    </tr>
                </thead>
                <tbody
                    class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                >
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="transition-colors hover:bg-muted/30"
                    >
                        <th scope="row" class="px-5 py-3 text-left font-normal">
                            <span class="block font-medium">{{
                                user.name
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ user.email }}
                            </span>
                        </th>
                        <td class="px-3 py-3 text-right tabular-nums">
                            {{ user.billed_generations }}
                        </td>
                        <td
                            class="px-3 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ user.failed_generations }}
                        </td>
                        <td
                            class="px-3 py-3 text-right tabular-nums"
                            :title="user.customer_charges"
                        >
                            {{ formatUsd(user.customer_charges) }}
                        </td>
                        <td
                            class="px-3 py-3 text-right tabular-nums"
                            :title="user.provider_costs"
                        >
                            {{ formatUsd(user.provider_costs) }}
                        </td>
                        <td
                            class="px-5 py-3 text-right font-medium tabular-nums"
                            :class="
                                isNegative(user.gross_profit)
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-emerald-600 dark:text-emerald-400'
                            "
                            :title="user.gross_profit"
                        >
                            {{ formatUsd(user.gross_profit) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
