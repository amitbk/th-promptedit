<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumnBig, Images, Wand2 } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usageReport } from '@/routes/admin';
import {
    create as generationsCreate,
    index as generationsIndex,
} from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Generate image',
        href: generationsCreate(),
        icon: Wand2,
    },
    {
        title: 'Generation history',
        href: generationsIndex(),
        icon: Images,
    },
    // Hidden from non-admins; the route itself is also protected on the server.
    ...(page.props.auth.can.viewAdminReports
        ? [
              {
                  title: 'Usage & profit',
                  href: usageReport(),
                  icon: ChartColumnBig,
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="generationsIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
