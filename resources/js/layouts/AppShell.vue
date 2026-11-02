<script setup lang="ts">
import { shallowRef } from 'vue';
import Sidebar from '../components/Sidebar.vue';
import Topbar from '../components/Topbar.vue';
import type { AuthProps, NotificationEndpoints } from '../types';

const props = defineProps<{
    auth: AuthProps;
    manageAi?: boolean;
    notificationEndpoints?: NotificationEndpoints | null;
}>();

const mobileOpen = shallowRef(false);
const collapsed = shallowRef(false);
if (typeof window !== 'undefined' && window.innerWidth >= 1024) {
    collapsed.value = window.localStorage.getItem('prime.sidebar.collapsed') === '1';
}

function toggleSidebar(): void {
    if (typeof window !== 'undefined' && window.innerWidth < 1024) {
        mobileOpen.value = !mobileOpen.value;
        return;
    }

    collapsed.value = !collapsed.value;
    window.localStorage.setItem('prime.sidebar.collapsed', collapsed.value ? '1' : '0');
}

</script>

<template>
    <div id="prime-shell" class="min-h-screen bg-prime-warm-white" :class="{ 'sidebar-collapsed': collapsed }">
        <div
            id="prime-sidebar-overlay"
            class="fixed inset-0 z-30 bg-slate-950/35 lg:hidden"
            :class="{ hidden: !mobileOpen }"
            @click="mobileOpen = false"
        />

        <Sidebar :auth="props.auth" :manage-ai="props.manageAi ?? false" :mobile-open="mobileOpen" :collapsed="collapsed" @close="mobileOpen = false" />

        <div id="prime-main-content" class="min-h-screen bg-prime-warm-white lg:pl-[18.5rem]">
            <Topbar
                :auth="props.auth"
                :notification-endpoints="props.notificationEndpoints"
                @toggle-sidebar="toggleSidebar"
            />

            <main class="px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
