<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PrimeIcon from './PrimeIcon.vue';
import type { AuthProps, NotificationEndpoints } from '../types';

interface NotificationItem {
    id: number;
    title: string;
    message: string;
    target_url: string | null;
    created_at_human: string;
    is_read: boolean;
}

const props = defineProps<{
    auth: AuthProps;
    notificationEndpoints?: NotificationEndpoints | null;
}>();

const emit = defineEmits<{ 'toggle-sidebar': [] }>();
const page = usePage();
const profileOpen = ref(false);
const notificationOpen = ref(false);
const notifications = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
const busy = ref(false);
const notice = ref('');
const currentPath = computed(() => String(page.url).split('?')[0]);
// Permukaan pembanding cutover /internal/dashboard-vue masih dihitung
// Dashboard selama fase verifikasi; dihapus bersama rute kandidat.
const dashboardVerificationPath = '/internal/dashboard-vue';
const isDashboard = computed(() => currentPath.value === '/dashboard' || currentPath.value === dashboardVerificationPath);

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function loadNotifications(): Promise<void> {
    if (!props.notificationEndpoints || props.auth.role !== 'admin') return;

    try {
        const response = await fetch(props.notificationEndpoints.index, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('Gagal mengambil notifikasi');
        const payload = await response.json() as { notifications?: NotificationItem[]; unread_count?: number };
        notifications.value = payload.notifications ?? [];
        unreadCount.value = payload.unread_count ?? 0;
    } catch {
        notice.value = 'Notifikasi tidak dapat dimuat saat ini.';
    }
}

async function markAllRead(): Promise<void> {
    if (!props.notificationEndpoints || busy.value) return;
    busy.value = true;
    try {
        const response = await fetch(props.notificationEndpoints.readAll, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: '{}',
        });
        if (!response.ok) throw new Error('Gagal memperbarui notifikasi');
        await loadNotifications();
        notice.value = 'Semua notifikasi telah dibaca.';
    } catch {
        notice.value = 'Status notifikasi belum dapat diperbarui.';
    } finally {
        busy.value = false;
    }
}

async function openNotification(item: NotificationItem): Promise<void> {
    if (!props.notificationEndpoints || busy.value) return;
    busy.value = true;
    try {
        const response = await fetch(props.notificationEndpoints.readTemplate.replace('__ID__', String(item.id)), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: '{}',
        });
        if (!response.ok) throw new Error('Gagal membuka notifikasi');
        const payload = await response.json() as { redirect_url?: string };
        window.location.assign(payload.redirect_url || props.notificationEndpoints.fallback);
    } catch {
        notice.value = 'Notifikasi tidak dapat dibuka saat ini.';
    } finally {
        busy.value = false;
    }
}

function closeMenus(event: MouseEvent): void {
    const target = event.target;
    if (!(target instanceof Element) || !target.closest('[data-topbar-menu]')) {
        profileOpen.value = false;
        notificationOpen.value = false;
    }
}

function handleEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        profileOpen.value = false;
        notificationOpen.value = false;
    }
}

onMounted(() => {
    if (props.auth.role === 'admin') void loadNotifications();
    document.addEventListener('click', closeMenus);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeMenus);
    document.removeEventListener('keydown', handleEscape);
});
</script>

<template>
    <header class="sticky top-0 z-20 flex min-h-[4.25rem] items-center justify-between gap-4 border-b border-prime-border bg-white px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-prime-border text-prime-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary"
                :aria-label="isDashboard ? 'Ubah tampilan navigasi' : 'Buka navigasi utama'"
                @click="emit('toggle-sidebar')"
            >
                <PrimeIcon name="menu" class="h-5 w-5" />
            </button>
            <div class="min-w-0">
                <p class="truncate text-xs font-semibold uppercase tracking-[0.12em] text-prime-muted">PRIME · Maintenance System</p>
                <p class="truncate text-sm font-semibold text-prime-text">{{ isDashboard ? 'Dashboard' : 'Workspace' }}</p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            <div v-if="auth.role === 'admin' && notificationEndpoints" class="relative" data-topbar-menu>
                <button
                    type="button"
                    class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-prime-muted hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary"
                    aria-label="Notifikasi"
                    :aria-expanded="notificationOpen"
                    @click="notificationOpen = !notificationOpen; profileOpen = false"
                >
                    <PrimeIcon name="bell" class="h-5 w-5" />
                    <span v-if="unreadCount > 0" class="absolute right-1 top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-prime-error px-1 text-[10px] font-bold leading-4 text-white">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
                </button>
                <section v-if="notificationOpen" class="absolute right-0 top-12 z-50 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-prime-border bg-white shadow-lg" aria-label="Panel notifikasi">
                    <header class="flex items-center justify-between gap-3 border-b border-prime-border px-4 py-3">
                        <h2 class="text-sm font-semibold text-prime-text">Notifikasi</h2>
                        <button type="button" class="text-xs font-semibold text-prime-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-50" :disabled="busy || unreadCount === 0" @click="markAllRead">Tandai semua dibaca</button>
                    </header>
                    <ul v-if="notifications.length" class="max-h-80 divide-y divide-prime-border overflow-y-auto">
                        <li v-for="item in notifications" :key="item.id">
                            <button type="button" class="block w-full px-4 py-3 text-left hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-prime-primary" :class="{ 'bg-blue-50/50': !item.is_read }" :disabled="busy" @click="openNotification(item)">
                                <span class="block text-sm font-semibold text-prime-text">{{ item.title }}</span>
                                <span class="mt-1 block text-xs leading-5 text-prime-muted">{{ item.message }}</span>
                                <span class="mt-2 block text-[11px] text-prime-muted">{{ item.created_at_human }}</span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-8 text-center text-sm text-prime-muted">Belum ada notifikasi.</p>
                </section>
            </div>

            <div class="relative" data-topbar-menu>
                <button
                    type="button"
                    class="flex max-w-[12rem] items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary sm:max-w-[16rem]"
                    :aria-expanded="profileOpen"
                    aria-label="Menu akun"
                    @click="profileOpen = !profileOpen; notificationOpen = false"
                >
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-prime-primary"><PrimeIcon name="user" class="h-5 w-5" /></span>
                    <span class="hidden min-w-0 text-left sm:block">
                        <span class="block truncate text-sm font-semibold text-prime-text">{{ auth.name || 'Pengguna' }}</span>
                        <span class="block text-xs capitalize text-prime-muted">{{ auth.role || 'user' }}</span>
                    </span>
                    <PrimeIcon name="chevron" class="hidden h-4 w-4 text-prime-muted sm:block" />
                </button>
                <div v-if="profileOpen" class="absolute right-0 top-12 z-50 min-w-48 rounded-lg border border-prime-border bg-white p-1 shadow-lg">
                    <!-- Tautan akun hanya untuk admin; guest/operator tidak punya halaman ini. -->
                    <Link v-if="auth.role === 'admin'" href="/settings/users" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-prime-text hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-prime-primary" @click="profileOpen = false">
                        <PrimeIcon name="user" class="h-4 w-4" /> Akun & preferensi
                    </Link>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" :value="csrfToken()">
                        <button type="submit" class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-prime-error hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-prime-error">
                            <PrimeIcon name="logout" class="h-4 w-4" /> Keluar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>
    <p v-if="notice" class="sr-only" role="status" aria-live="polite">{{ notice }}</p>
</template>
