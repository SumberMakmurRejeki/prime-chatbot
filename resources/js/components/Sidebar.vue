<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, shallowRef, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PrimeIcon from './PrimeIcon.vue';
import type { AuthProps } from '../types';

interface NavigationItem {
    label: string;
    icon: string;
    href: string;
}

interface NavigationGroup {
    label: string;
    icon: string | null;
    items: NavigationItem[];
}

const props = defineProps<{
    auth: AuthProps;
    manageAi: boolean;
    mobileOpen: boolean;
    collapsed: boolean;
}>();

const emit = defineEmits<{ close: [] }>();
const dashboardPath = '/dashboard';
// Permukaan pembanding cutover masih dianggap halaman Dashboard yang sama
// selama fase verifikasi; dihapus bersama rute kandidat.
const dashboardVerificationPath = '/internal/dashboard-vue';
const page = usePage();
const currentPath = computed(() => String(page.url).split('?')[0]);
const openGroup = shallowRef<string | null>('PM Management');

const navigationGroups = computed<NavigationGroup[]>(() => {
    const dashboard = { label: 'Dashboard', icon: 'dashboard', href: dashboardPath };

    if (props.auth.role === 'guest') {
        return [{ label: 'Monitoring', icon: null, items: [dashboard] }];
    }

    return [
        { label: 'Monitoring', icon: null, items: [dashboard] },
        {
            label: 'PM Management', icon: 'shield', items: [
                { label: 'Master Mesin', icon: 'machine', href: '/pm/master-mesin' },
                { label: 'Master Lokasi', icon: 'pin', href: '/pm/master-lokasi' },
                { label: 'Master PM Checksheet', icon: 'clipboard', href: '/pm/master-checksheet' },
                { label: 'PM Review', icon: 'document', href: '/pm/review' },
            ],
        },
        {
            label: 'Breakdown Management', icon: 'alert', items: [
                { label: 'Input Breakdown', icon: 'alert', href: '/breakdown/input' },
                { label: 'Review Breakdown', icon: 'tools', href: '/breakdown/review' },
            ],
        },
        { label: 'Kalender', icon: null, items: [{ label: 'Kalender', icon: 'calendar', href: '/calendar' }] },
        {
            label: 'Report', icon: 'report', items: [
                { label: 'Report PM', icon: 'report', href: '/report/pm' },
                { label: 'Report Breakdown', icon: 'report', href: '/report/breakdown' },
            ],
        },
        { label: 'Pengaturan User', icon: null, items: [{ label: 'Pengaturan User', icon: 'user', href: '/settings/users' }] },
        { label: 'Assistant', icon: null, items: [{ label: 'Assistant', icon: 'support', href: '/assistant' }] },
        ...(props.manageAi ? [{
            label: 'AI Settings', icon: 'shield', items: [
                { label: 'Pengelola AI', icon: 'user', href: '/settings/ai/managers' },
                { label: 'Provider Configuration', icon: 'cpu', href: '/settings/ai/providers' },
                { label: 'Knowledge Documents', icon: 'document', href: '/settings/ai/knowledge-documents' },
            ],
        }] : []),
    ];
});

const railItems = computed(() => navigationGroups.value.flatMap((group) => group.items));

function isActive(href: string): boolean {
    if (href === dashboardPath) {
        return currentPath.value === dashboardPath || currentPath.value === dashboardVerificationPath;
    }

    return currentPath.value.startsWith(href);
}

function toggleGroup(label: string): void {
    openGroup.value = openGroup.value === label ? null : label;
}

const railTooltip = shallowRef<{ label: string; y: number } | null>(null);
const desktopRail = shallowRef(false);
let railMedia: MediaQueryList | null = null;

const syncRailMedia = (): void => {
    desktopRail.value = railMedia?.matches ?? false;
};

// Tooltip pseudo CSS terpotong oleh nav yang bisa di-scroll, jadi rail terlipat memakai
// satu elemen tooltip fixed (posisinya dari kotak kontrol) tanpa pustaka tambahan.
function showRailTooltip(label: string, event: Event): void {
    if (! props.collapsed || ! desktopRail.value) {
        return;
    }

    const box = (event.currentTarget as HTMLElement | null)?.getBoundingClientRect();
    // Pusat vertikal kontrol dijaga tetap di dalam viewport agar tooltip tidak terpotong.
    railTooltip.value = box
        ? { label, y: Math.max(20, Math.min(window.innerHeight - 20, box.top + box.height / 2)) }
        : null;
}

function hideRailTooltip(): void {
    railTooltip.value = null;
}

watch(() => props.collapsed, hideRailTooltip);

onMounted(() => {
    railMedia = window.matchMedia('(min-width: 1024px)');
    syncRailMedia();
    railMedia.addEventListener('change', syncRailMedia);
});

onBeforeUnmount(() => railMedia?.removeEventListener('change', syncRailMedia));

/**
 * Status aktif rail dibuat lebih tenang (lapisan putih tipis, bukan blok biru penuh);
 * aria-label tetap menjadi nama aksesibel, tooltip hanya penguat visual.
 */
function navLinkClasses(href: string): Record<string, boolean> {
    return {
        'bg-white/10 font-semibold text-white ring-1 ring-inset ring-white/20': isActive(href),
    };
}
</script>

<template>
    <aside
        id="prime-sidebar"
        class="fixed inset-y-0 left-0 z-40 flex w-[18.5rem] -translate-x-full flex-col border-r border-prime-sidebar-border bg-[#061A33] px-4 py-6 text-white transition-transform duration-200 motion-reduce:transition-none lg:translate-x-0"
        :class="{ 'translate-x-0!': mobileOpen }"
        aria-label="Navigasi utama"
    >
        <div class="flex items-start justify-between gap-3 px-3">
            <Link :href="dashboardPath" class="block min-w-0 flex-1" aria-label="Beranda Dashboard" @click="emit('close')">
                <span class="prime-sidebar-logo-compact hidden h-8 w-8 items-center justify-center rounded-lg bg-white text-[10px] font-bold text-[#061A33]">PM</span>
                <img data-sidebar-label :src="'/images/prime-logo.png'" alt="PRIME logo" class="h-auto w-full max-w-[11.5rem]">
                <p data-sidebar-label class="mt-3 text-xs font-medium uppercase tracking-[0.12em] text-white/80">Maintenance System</p>
            </Link>
            <button
                type="button"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-white/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:hidden"
                aria-label="Tutup navigasi"
                @click="emit('close')"
            ><PrimeIcon name="close" class="h-5 w-5" /></button>
        </div>

        <nav v-if="collapsed" class="prime-collapsed-navigation mt-8 flex-1 flex-col gap-2 overflow-y-auto" aria-label="Navigasi ringkas">
            <a
                v-for="item in railItems"
                :key="item.href"
                :href="item.href"
                class="prime-rail-link relative mx-auto inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-white/85 transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                :class="navLinkClasses(item.href)"
                :aria-label="item.label"
                :aria-current="isActive(item.href) ? 'page' : undefined"
                @mouseenter="showRailTooltip(item.label, $event)"
                @focus="showRailTooltip(item.label, $event)"
                @mouseleave="hideRailTooltip"
                @blur="hideRailTooltip"
                @click="emit('close')"
            ><PrimeIcon :name="item.icon" class="h-5 w-5" /></a>
        </nav>

        <nav class="mt-8 flex-1 space-y-2 overflow-y-auto pr-1">
            <template v-for="group in navigationGroups" :key="group.label">
                <Link
                    v-if="group.items.length === 1 && !group.icon"
                    :href="group.items[0].href"
                    :aria-label="group.items[0].label"
                    class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium text-white/85 transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    :class="navLinkClasses(group.items[0].href)"
                    :aria-current="isActive(group.items[0].href) ? 'page' : undefined"
                    @mouseenter="showRailTooltip(group.items[0].label, $event)"
                    @focus="showRailTooltip(group.items[0].label, $event)"
                    @mouseleave="hideRailTooltip"
                    @blur="hideRailTooltip"
                    @click="emit('close')"
                >
                    <span class="inline-flex h-8 w-8 items-center justify-center"><PrimeIcon :name="group.items[0].icon" class="h-5 w-5" /></span>
                    <span data-sidebar-label>{{ group.items[0].label }}</span>
                </Link>
                <section v-else class="border-t border-white/10 pt-3">
                    <button
                        type="button"
                        class="flex min-h-10 w-full items-center justify-between rounded-md px-3 text-xs font-semibold uppercase tracking-[0.08em] text-white/65 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                        :aria-label="group.label"
                        @click="toggleGroup(group.label)"
                        @mouseenter="showRailTooltip(group.label, $event)"
                        @focus="showRailTooltip(group.label, $event)"
                        @mouseleave="hideRailTooltip"
                        @blur="hideRailTooltip"
                    >
                        <span class="inline-flex items-center gap-2"><PrimeIcon v-if="group.icon" :name="group.icon" class="h-4 w-4" /><span data-sidebar-label>{{ group.label }}</span></span>
                        <PrimeIcon name="chevron" class="h-4 w-4 transition-transform" :class="{ 'rotate-180': openGroup === group.label }" />
                    </button>
                    <div v-if="openGroup === group.label" class="mt-1 space-y-1 px-1">
                        <a
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            :aria-label="item.label"
                            class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm text-white/85 transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="navLinkClasses(item.href)"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @mouseenter="showRailTooltip(item.label, $event)"
                            @focus="showRailTooltip(item.label, $event)"
                            @mouseleave="hideRailTooltip"
                            @blur="hideRailTooltip"
                            @click="emit('close')"
                        ><span class="inline-flex h-8 w-8 items-center justify-center"><PrimeIcon :name="item.icon" class="h-5 w-5" /></span><span data-sidebar-label>{{ item.label }}</span></a>
                    </div>
                </section>
            </template>
        </nav>

        <!-- Tooltip rail terlipat: fixed viewport sehingga tidak terpotong area nav yang bisa discroll. -->
        <div
            v-if="collapsed && desktopRail && railTooltip"
            class="prime-rail-tooltip"
            :style="{ top: `${railTooltip.y}px` }"
            aria-hidden="true"
        >{{ railTooltip.label }}</div>
    </aside>
</template>
