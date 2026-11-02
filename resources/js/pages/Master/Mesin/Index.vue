<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, shallowRef, useTemplateRef, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppShell from '../../../layouts/AppShell.vue';
import BaseButton from '../../../components/BaseButton.vue';
import MachineDialog from '../../../components/MachineDialog.vue';
import PageHeader from '../../../components/PageHeader.vue';
import PrimeIcon from '../../../components/PrimeIcon.vue';
import type { MasterMachine, MasterMachineProps } from '../../../types';

const page = usePage<MasterMachineProps>();
const props = computed(() => page.props as MasterMachineProps);
const basePath = computed(() => String(page.url).split('?')[0]);
const search = shallowRef(props.value.filters.search);
const showForm = shallowRef(false);
const showDetail = shallowRef(false);
const showQr = shallowRef(false);
const showLifecycle = shallowRef(false);
const showDelete = shallowRef(false);
const qrProcessing = shallowRef(false);
const selected = shallowRef<MasterMachine | null>(null);
// Wilayah daftar mesin: fokus cadangan terakhir agar penutupan dialog tidak pernah
// mendarat di BODY maupun memilih Aksi mesin lain saat baris mesin tidak ada lagi.
const listRegion = useTemplateRef<HTMLElement>('listRegion');
const lifecycleAction = shallowRef<'activate' | 'deactivate' | 'retire'>('deactivate');
const formMode = shallowRef<'create' | 'edit'>('create');
let searchTimer: ReturnType<typeof setTimeout> | undefined;
// Nilai search yang sedang kita minta ke server. Dipakai untuk membedakan
// balikan visit milik kita (jangan menimpa ketikan pengguna) dari perubahan
// server lain / navigasi Back-Forward yang harus selalu diikuti input.
let pendingSearch: string | null = null;

const form = useForm({
    machine_code: '',
    machine_name: '',
    location_id: '',
    description: '',
    is_active: '1',
});
const formTitle = computed(() => (formMode.value === 'edit' ? 'Edit Mesin' : 'Tambah Mesin'));
const isFilterActive = computed(() =>
    Boolean(props.value.filters.search || props.value.filters.status || props.value.filters.location_id));
const sortIndicator = (key: 'machine_code' | 'machine_name'): string => {
    if (props.value.filters.sort !== key) {
        return '↕';
    }

    return props.value.filters.direction === 'asc' ? '↑' : '↓';
};
const statusLabel: Record<string, string> = { active: 'Aktif', inactive: 'Nonaktif', retired: 'Pensiun' };

// Props server selalu memulihkan input pada Back/Forward. Abaikan hanya
// balikan visit search milik komponen ini, yang bisa tiba saat pengguna
// masih mengetik.
watch(() => props.value.filters.search, (value) => {
    if (value === pendingSearch) {
        pendingSearch = null;
        return;
    }

    clearTimeout(searchTimer);
    pendingSearch = null;
    search.value = value;
});

// Debounce 300 ms: server tetap satu-satunya search authority, bukan filter baris lokal.
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        // Lewati bila server sudah berada pada nilai ini (mis. hasil visit
        // resetFilters), agar tidak ada entri histori duplikat.
        if (value !== props.value.filters.search) {
            pendingSearch = value;
            visit({ search: value }, true);
        }
    }, 300);
});


// Menu Aksi memakai <details>; tutup saat klik di luar atau Escape agar tidak
// ada menu yang tertinggal terbuka di belakang dialog.
function closeMenus(): void {
    document.querySelectorAll<HTMLDetailsElement>('details.machine-menu[open]').forEach((menu) => {
        menu.open = false;
    });
}

function onDocumentPointerDown(event: Event): void {
    const target = event.target;
    if (target instanceof Element && target.closest('details.machine-menu')) {
        return;
    }

    closeMenus();
}

function onDocumentKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeMenus();
    }
}


onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    document.addEventListener('keydown', onDocumentKeyDown, true);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
    document.removeEventListener('keydown', onDocumentKeyDown, true);
});

function buildQuery(changes: Record<string, string | number | undefined>, resetPage: boolean): Record<string, string | number> {
    const merged: Record<string, string | number | undefined> = {
        search: props.value.filters.search,
        status: props.value.filters.status,
        location_id: props.value.filters.location_id ?? '',
        sort: props.value.filters.sort,
        direction: props.value.filters.direction,
        ...changes,
    };
    if (resetPage) {
        merged.page = 1;
    }

    const query: Record<string, string | number> = {};
    Object.entries(merged).forEach(([key, value]) => {
        if (value !== undefined && value !== '') {
            query[key] = value;
        }
    });

    return query;
}

function visit(changes: Record<string, string | number | undefined>, resetPage = false): void {
    // Push (bukan replace) agar Back/Forward memulihkan keadaan list sebelumnya;
    // debounce + dedup di atas mencegah satu entri per ketikan.
    router.get(basePath.value, buildQuery(changes, resetPage), {
        preserveState: true,
        preserveScroll: true,
    });
}

function changeSort(key: 'machine_code' | 'machine_name'): void {
    const direction = props.value.filters.sort === key && props.value.filters.direction === 'asc' ? 'desc' : 'asc';
    visit({ sort: key, direction }, true);
}

function resetFilters(): void {
    clearTimeout(searchTimer);
    pendingSearch = '';
    search.value = '';
    visit({ search: undefined, status: undefined, location_id: undefined }, true);
}

function startCreate(): void {
    selected.value = null;
    formMode.value = 'create';
    form.reset();
    form.clearErrors();
    form.is_active = '1';
    showForm.value = true;
}

function startEdit(machine: MasterMachine): void {
    selected.value = machine;
    formMode.value = 'edit';
    form.clearErrors();
    form.machine_code = machine.machine_code;
    form.machine_name = machine.machine_name;
    form.location_id = String(machine.location_id);
    form.description = machine.description ?? '';
    showForm.value = true;
}

function submitMachine(): void {
    // Edit memakai URL per-mesin dari prop; server tetap otoritas validasi dan otorisasi mutasi.
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
        },
    };

    if (formMode.value === 'edit' && selected.value) {
        form.put(selected.value.update_url, options);

        return;
    }

    form.post(props.value.storeUrl, options);
}

function goPage(url: string | null): void {
    if (!url) {
        return;
    }

    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function openLifecycle(machine: MasterMachine, action: 'activate' | 'deactivate' | 'retire'): void {
    selected.value = machine;
    lifecycleAction.value = action;
    showLifecycle.value = true;
}

function findMachineAction(machineId: number | undefined): HTMLElement | null {
    if (machineId === undefined) {
        return null;
    }

    // Pemicu Detail, QR, Lifecycle, dan Hapus ada di menu Aksi yang bisa ditutup
    // (Escape/klik di luar) atau terhapus (daftar dirender ulang setelah status
    // mesin berubah) selama dialog terbuka, sehingga fokus tak bisa kembali ke
    // pemicunya. Ambil kontrol Aksi milik mesin yang sama — bukan baris mesin lain —
    // dan hanya presentasi yang terlihat.
    const menu = Array.from(document.querySelectorAll<HTMLElement>('[data-machine-menu]'))
        .find((element) => element.dataset.machineMenu === String(machineId)
            && element.getClientRects().length > 0);
    return menu?.querySelector<HTMLElement>('summary') ?? listRegion.value;
}

/**
 * Menu Aksi ikut tertutup saat salah satu dialog dibuka, jadi pemicu aslinya tidak
 * lagi bisa difokuskan saat dialog ditutup. Satu pembuka terpusat menjaga target
 * dialog dan identitas mesin tetap konsisten untuk Detail, QR, dan Hapus.
 */
function openMachineDialog(machine: MasterMachine, dialog: 'detail' | 'qr' | 'delete'): void {
    selected.value = machine;
    if (dialog === 'detail') {
        showDetail.value = true;
    } else if (dialog === 'qr') {
        showQr.value = true;
    } else {
        showDelete.value = true;
    }
}

const lifecycleContent = computed(() => {
    const machine = selected.value;
    const identity = `${machine?.machine_code} · ${machine?.machine_name}`;

    if (lifecycleAction.value === 'activate') {
        return {
            title: 'Aktifkan Mesin?',
            text: `${identity} akan kembali aktif.`,
            submit: 'Ya, Aktifkan',
            url: machine?.activate_url ?? null,
        };
    }

    if (lifecycleAction.value === 'deactivate') {
        return {
            title: 'Nonaktifkan Mesin?',
            text: `${identity} akan dinonaktifkan. PM baru tidak akan dibuat selama mesin nonaktif. Data dan riwayat tetap tersimpan.`,
            submit: 'Ya, Nonaktifkan',
            url: machine?.deactivate_url ?? null,
        };
    }

    return {
        title: 'Pensiunkan Mesin?',
        text: `${identity} akan dipensiunkan. Jadwal PM aktif yang terkait akan diakhiri.`,
        submit: 'Pensiunkan Mesin',
        url: machine?.retire_url ?? null,
    };
});

function submitLifecycle(): void {
    const url = lifecycleContent.value.url;
    if (!url) {
        return;
    }

    router.patch(url, {}, {
        preserveScroll: true,
        onSuccess: () => {
            showLifecycle.value = false;
        },
    });
}

function submitDelete(): void {
    const machine = selected.value;
    if (!machine) {
        return;
    }

    router.delete(machine.delete_url, {
        preserveScroll: true,
        onSuccess: () => {
            showDelete.value = false;
        },
    });
}

// Unduhan PNG digambar ulang di canvas agar QR tetap satu file unduhan tanpa route download baru.
function downloadQr(): void {
    const machine = selected.value;
    if (!machine?.qr_image_url) {
        return;
    }

    const image = new Image();
    image.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = 900;
        canvas.height = 1080;
        const context = canvas.getContext('2d');

        if (!context) {
            return;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#191c22';
        context.font = '700 42px sans-serif';
        context.fillText('QR CODE MESIN', 52, 72);
        context.font = '700 34px sans-serif';
        context.fillText(`${machine.machine_code} — ${machine.machine_name}`, 52, 168);
        context.fillStyle = '#5c6070';
        context.font = '600 27px sans-serif';
        context.fillText(machine.location_name, 52, 220);
        context.fillStyle = '#191c22';
        context.drawImage(image, 190, 300, 520, 520);

        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = `${machine.machine_code.toLowerCase()}-qr.png`;
        link.click();
    };
    image.src = machine.qr_image_url;
}

function regenerateQr(): void {
    if (!selected.value) {
        return;
    }

    router.post(selected.value.qr_generate_url, {}, {
        preserveScroll: true,
        onStart: () => {
            qrProcessing.value = true;
        },
        onFinish: () => {
            qrProcessing.value = false;
        },
    });
}
</script>

<template>
    <AppShell :auth="props.auth" :manage-ai="props.manageAi" :notification-endpoints="props.notificationEndpoints">
        <Head title="Master Mesin PRIME" />

        <section class="mx-auto max-w-[1440px] space-y-6 text-sm" aria-labelledby="machine-title">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="machine-page-header">
                    <PageHeader id="machine-title" title="Master Mesin" description="Kelola data mesin, lokasi, status, dan QR code." eyebrow="PM Management" />
                </div>
                <BaseButton @click="startCreate">
                    <PrimeIcon name="machine" class="mr-2 h-4 w-4" /> Tambah Mesin
                </BaseButton>
            </header>

            <p v-if="props.flash.success" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ props.flash.success }}</p>
            <p v-else-if="props.flash.error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ props.flash.error }}</p>

            <section ref="listRegion" tabindex="-1" class="space-y-4" aria-label="Daftar mesin">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_minmax(11rem,1fr)_minmax(11rem,1fr)_auto]">
                    <label class="relative block">
                        <span class="sr-only">Cari kode atau nama mesin</span>
                        <PrimeIcon name="search" class="pointer-events-none absolute left-3 top-3 h-5 w-5 text-prime-muted" />
                        <input v-model="search" type="search" autocomplete="off" placeholder="Cari kode atau nama mesin" class="h-11 w-full rounded-micro border border-prime-border bg-white pl-10 pr-3 text-sm text-prime-text placeholder:text-prime-muted focus:border-prime-primary focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary">
                    </label>
                    <label>
                        <span class="sr-only">Filter lokasi</span>
                        <select :value="props.filters.location_id ?? ''" class="h-11 w-full rounded-micro border border-prime-border bg-white px-3 text-sm text-prime-text focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary" @change="visit({ location_id: ($event.target as HTMLSelectElement).value || undefined }, true)">
                            <option value="">Semua Lokasi</option>
                            <option v-for="location in props.locations" :key="location.id" :value="location.id">{{ location.location_name }}</option>
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Filter status</span>
                        <select :value="props.filters.status" class="h-11 w-full rounded-micro border border-prime-border bg-white px-3 text-sm text-prime-text focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary" @change="visit({ status: ($event.target as HTMLSelectElement).value || undefined }, true)">
                            <option value="">Semua Status</option>
                            <option v-for="choice in props.statusChoices" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                        </select>
                    </label>
                    <button type="button" class="h-11 rounded-micro border border-prime-border bg-white px-4 text-sm font-medium text-prime-ink hover:bg-prime-surface disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :disabled="!isFilterActive" @click="resetFilters">Reset filter</button>
                </div>

                <div class="rounded-lg border border-prime-border bg-white">
                    <div v-if="props.machines.data.length === 0" class="min-h-40 px-6 py-14 text-center">
                        <h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">{{ isFilterActive ? 'Mesin yang kamu cari tidak ditemukan' : 'Belum ada data mesin' }}</h2>
                        <p class="mt-2 text-sm text-prime-muted">{{ isFilterActive ? 'Coba ubah kata kunci atau reset filter.' : 'Tambahkan mesin untuk mulai menggunakan PM Management.' }}</p>
                        <button v-if="!isFilterActive" type="button" class="mt-4 inline-flex min-h-11 items-center font-semibold text-prime-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startCreate">Tambah mesin pertama</button>
                    </div>

                    <template v-else>
                        <div class="hidden lg:block">
                            <table class="w-full table-fixed border-collapse text-left text-sm">
                                <caption class="sr-only">Daftar mesin beserta lokasi, status, dan aksi</caption>
                                <thead class="bg-prime-surface text-xs text-prime-muted">
                                    <tr>
                                        <th scope="col" class="w-10 px-2 py-3 font-semibold">No</th>
                                        <th scope="col" class="w-32 px-3 py-3 font-semibold">
                                            <button type="button" class="inline-flex items-center gap-1 font-semibold hover:text-prime-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :aria-label="`Urutkan Kode Mesin ${sortIndicator('machine_code')}`" @click="changeSort('machine_code')">Kode Mesin {{ sortIndicator('machine_code') }}</button>
                                        </th>
                                        <th scope="col" class="px-3 py-3 font-semibold">
                                            <button type="button" class="inline-flex items-center gap-1 font-semibold hover:text-prime-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :aria-label="`Urutkan Nama Mesin ${sortIndicator('machine_name')}`" @click="changeSort('machine_name')">Nama Mesin {{ sortIndicator('machine_name') }}</button>
                                        </th>
                                        <th scope="col" class="px-3 py-3 font-semibold">Lokasi</th>
                                        <th scope="col" class="w-28 px-3 py-3 font-semibold">Status</th>
                                        <th scope="col" class="w-40 px-3 py-3 text-right font-semibold">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-prime-border">
                                    <tr v-for="(machine, index) in props.machines.data" :key="machine.id" class="bg-white transition-colors hover:bg-slate-50/70">
                                        <td class="px-2 py-3.5 text-prime-muted">{{ (props.machines.current_page - 1) * props.machines.per_page + index + 1 }}</td>
                                        <td class="truncate px-3 py-3.5 font-semibold text-prime-ink" :title="machine.machine_code">{{ machine.machine_code }}</td>
                                        <td class="truncate px-3 py-3.5 text-prime-text" :title="machine.machine_name">{{ machine.machine_name }}</td>
                                        <td class="truncate px-3 py-3.5 text-prime-text" :title="machine.location_name">{{ machine.location_name }}</td>
                                        <td class="px-3 py-3.5">
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold" :class="machine.lifecycle_status === 'active' ? 'border-green-200 bg-green-50 text-green-800' : machine.lifecycle_status === 'retired' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'">{{ statusLabel[machine.lifecycle_status] }}</span>
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button type="button" class="inline-flex h-10 items-center rounded-micro px-3 text-sm font-semibold text-prime-primary hover:bg-prime-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startEdit(machine)">Edit</button>
                                                <details class="machine-menu" :data-machine-menu="machine.id">
                                                    <summary class="menu-summary" :aria-label="`Aksi untuk ${machine.machine_code}`">Aksi <PrimeIcon name="chevron" class="h-4 w-4" /></summary>
                                                    <div class="menu-list" :aria-label="`Aksi ${machine.machine_code}`">
                                                        <button type="button" class="menu-action" @click="openMachineDialog(machine, 'detail')">Detail</button>
                                                        <button type="button" class="menu-action" @click="openMachineDialog(machine, 'qr')">Lihat QR</button>
                                                        <button v-if="machine.deactivate_url" type="button" class="menu-action" @click="openLifecycle(machine, 'deactivate')">Nonaktifkan</button>
                                                        <button v-if="machine.activate_url" type="button" class="menu-action" @click="openLifecycle(machine, 'activate')">Aktifkan</button>
                                                        <button v-if="machine.retire_url" type="button" class="menu-action text-amber-800" @click="openLifecycle(machine, 'retire')">Pensiunkan</button>
                                                        <button type="button" class="menu-action text-prime-danger" @click="openMachineDialog(machine, 'delete')">Hapus</button>

                                                    </div>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid gap-3 p-3 lg:hidden">
                            <article v-for="machine in props.machines.data" :key="machine.id" class="rounded-card border border-prime-border bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-prime-muted">{{ machine.machine_code }}</p>
                                        <h2 class="mt-1 truncate text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-ink">{{ machine.machine_name }}</h2>
                                        <p class="mt-1 text-sm text-prime-muted">{{ machine.location_name }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold" :class="machine.lifecycle_status === 'active' ? 'border-green-200 bg-green-50 text-green-800' : machine.lifecycle_status === 'retired' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'">{{ statusLabel[machine.lifecycle_status] }}</span>
                                </div>
                                <div class="mt-4 flex items-center justify-between gap-2">
                                    <button type="button" class="inline-flex min-h-11 items-center px-3 text-sm font-semibold text-prime-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startEdit(machine)">Edit</button>
                                    <details class="machine-menu" :data-machine-menu="machine.id">
                                        <summary class="menu-summary" :aria-label="`Aksi untuk ${machine.machine_code}`">Aksi <PrimeIcon name="chevron" class="h-4 w-4" /></summary>
                                        <div class="menu-list" :aria-label="`Aksi ${machine.machine_code}`">
                                            <button type="button" class="menu-action" @click="openMachineDialog(machine, 'detail')">Detail</button>
                                            <button type="button" class="menu-action" @click="openMachineDialog(machine, 'qr')">Lihat QR</button>
                                            <button v-if="machine.deactivate_url" type="button" class="menu-action" @click="openLifecycle(machine, 'deactivate')">Nonaktifkan</button>
                                            <button v-if="machine.activate_url" type="button" class="menu-action" @click="openLifecycle(machine, 'activate')">Aktifkan</button>
                                            <button v-if="machine.retire_url" type="button" class="menu-action text-amber-800" @click="openLifecycle(machine, 'retire')">Pensiunkan</button>
                                            <button type="button" class="menu-action text-prime-danger" @click="openMachineDialog(machine, 'delete')">Hapus</button>
                                        </div>
                                    </details>
                                </div>
                            </article>
                        </div>

                        <footer class="flex flex-col gap-3 border-t border-prime-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-prime-muted">Menampilkan {{ props.machines.from ?? 0 }}-{{ props.machines.to ?? 0 }} dari total {{ props.machines.total }} data</p>
                            <nav class="flex flex-wrap items-center gap-1" aria-label="Navigasi halaman mesin">
                                <button v-for="link in props.machines.links" :key="`${link.label}-${link.url}`" type="button" class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-micro border px-2 text-sm" :class="link.active ? 'border-prime-primary bg-prime-primary text-white' : 'border-prime-border bg-white text-prime-text hover:bg-prime-surface'" :disabled="!link.url || link.active" :aria-current="link.active ? 'page' : undefined" @click="goPage(link.url)" v-html="link.label" />
                            </nav>
                        </footer>
                    </template>
                </div>
            </section>
        </section>

        <MachineDialog v-model="showForm" :title="formTitle">
            <form class="space-y-4" novalidate @submit.prevent="submitMachine">
                <div>
                    <label for="machine-code" class="mb-1.5 block text-sm font-semibold text-prime-text">Kode Mesin <span class="text-prime-danger" aria-hidden="true">*</span></label>
                    <input id="machine-code" v-model="form.machine_code" name="machine_code" type="text" :required="formMode === 'create'" :readonly="formMode === 'edit'" autofocus class="machine-input">
                    <p v-if="form.errors.machine_code" class="mt-1 text-sm font-medium text-prime-error" role="alert">{{ form.errors.machine_code }}</p>
                </div>
                <div>
                    <label for="machine-name" class="mb-1.5 block text-sm font-semibold text-prime-text">Nama Mesin <span class="text-prime-danger" aria-hidden="true">*</span></label>
                    <input id="machine-name" v-model="form.machine_name" name="machine_name" type="text" required class="machine-input">
                    <p v-if="form.errors.machine_name" class="mt-1 text-sm font-medium text-prime-error" role="alert">{{ form.errors.machine_name }}</p>
                </div>
                <div>
                    <label for="machine-location" class="mb-1.5 block text-sm font-semibold text-prime-text">Lokasi <span class="text-prime-danger" aria-hidden="true">*</span></label>
                    <select id="machine-location" v-model="form.location_id" name="location_id" required class="machine-input">
                        <option value="">Pilih Lokasi</option>
                        <option v-for="location in props.locations.filter((item) => item.is_active || item.id === selected?.location_id)" :key="location.id" :value="String(location.id)">{{ location.location_name }}{{ location.is_active ? '' : ' (Nonaktif)' }}</option>
                    </select>
                    <p v-if="form.errors.location_id" class="mt-1 text-sm font-medium text-prime-error" role="alert">{{ form.errors.location_id }}</p>
                </div>
                <div v-if="formMode === 'create'">
                    <label for="machine-active" class="mb-1.5 block text-sm font-semibold text-prime-text">Status</label>
                    <select id="machine-active" v-model="form.is_active" name="is_active" class="machine-input">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>
                <div>
                    <label for="machine-description" class="mb-1.5 block text-sm font-semibold text-prime-text">Deskripsi</label>
                    <textarea id="machine-description" v-model="form.description" name="description" rows="4" class="machine-input h-auto py-3"></textarea>
                </div>
                <div class="flex justify-end gap-2 border-t border-prime-border pt-4">
                    <button type="button" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="showForm = false">Batal</button>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-micro bg-prime-primary px-4 text-sm font-semibold text-white hover:bg-prime-primary-active focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-60" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </MachineDialog>

        <MachineDialog v-model="showDetail" title="Detail Mesin" :fallback-focus="() => findMachineAction(selected?.id)">
            <dl v-if="selected" class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="text-sm font-semibold text-prime-muted">Kode Mesin</dt>
                    <dd class="mt-1 text-sm font-semibold text-prime-ink [overflow-wrap:anywhere]">{{ selected.machine_code }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm font-semibold text-prime-muted">Nama Mesin</dt>
                    <dd class="mt-1 text-base font-semibold text-prime-ink [overflow-wrap:anywhere]">{{ selected.machine_name }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm font-semibold text-prime-muted">Lokasi</dt>
                    <dd class="mt-1 text-sm text-prime-text [overflow-wrap:anywhere]">{{ selected.location_name }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm font-semibold text-prime-muted">Status</dt>
                    <dd class="mt-1 text-sm text-prime-text">{{ statusLabel[selected.lifecycle_status] }}</dd>
                </div>
                <div class="min-w-0 sm:col-span-2">
                    <dt class="text-sm font-semibold text-prime-muted">Deskripsi</dt>
                    <dd class="mt-1 text-sm leading-6 text-prime-text [overflow-wrap:anywhere]">{{ selected.description || 'Belum ada deskripsi.' }}</dd>
                </div>
                <div class="min-w-0 border-t border-prime-border pt-4 sm:col-span-2">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="text-sm font-semibold text-prime-muted">Tanggal dibuat</dt>
                            <dd class="mt-1 text-sm text-prime-text [overflow-wrap:anywhere]">{{ selected.created_at || '-' }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-sm font-semibold text-prime-muted">Terakhir diperbarui</dt>
                            <dd class="mt-1 text-sm text-prime-text [overflow-wrap:anywhere]">{{ selected.updated_at || '-' }}</dd>
                        </div>
                    </div>
                </div>
            </dl>
            <div class="mt-6 flex justify-end border-t border-prime-border pt-4">
                <button type="button" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" autofocus @click="showDetail = false">Tutup</button>
            </div>
        </MachineDialog>

        <MachineDialog v-model="showQr" title="QR Code Mesin" :fallback-focus="() => findMachineAction(selected?.id)">
            <div v-if="selected" class="text-center">
                <p class="text-[0.9375rem] font-semibold tracking-normal text-prime-ink">{{ selected.machine_code }}</p>
                <p class="mt-1 text-sm text-prime-muted">{{ selected.machine_name }} · {{ selected.location_name }}</p>
                <div class="mx-auto mt-5 grid h-56 w-56 place-items-center rounded-card border border-prime-border bg-white p-3">
                    <img v-if="selected.qr_image_url" :src="selected.qr_image_url" alt="QR Code Mesin" class="h-full w-full object-contain">
                    <p v-else class="text-sm text-prime-muted">QR belum tersedia. Gunakan generate ulang.</p>
                </div>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-60" :disabled="!selected.qr_image_url || qrProcessing" @click="downloadQr">
                        <PrimeIcon name="download" class="h-4 w-4" /> Download PNG
                    </button>
                    <button type="button" class="inline-flex min-h-11 items-center rounded-micro bg-prime-primary px-4 text-sm font-semibold text-white hover:bg-prime-primary-active focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-60" :disabled="qrProcessing" @click="regenerateQr">
                        {{ qrProcessing ? 'Memproses…' : 'Generate Ulang' }}
                    </button>
                </div>
            </div>
        </MachineDialog>

        <MachineDialog v-model="showLifecycle" :title="lifecycleContent.title" :destructive="lifecycleAction === 'retire'" :fallback-focus="() => findMachineAction(selected?.id)">
            <div class="flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full" :class="lifecycleAction === 'retire' ? 'bg-red-50 text-prime-danger' : lifecycleAction === 'deactivate' ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-prime-success'" aria-hidden="true">
                    <PrimeIcon :name="lifecycleAction === 'retire' ? 'alert' : 'power'" class="h-6 w-6" />
                </span>
                <p class="whitespace-pre-line text-sm leading-6 text-prime-muted">
                    {{ lifecycleContent.text }}
                    <strong v-if="lifecycleAction === 'retire'" class="mt-2 block text-prime-danger">Mesin yang sudah dipensiunkan tidak dapat diaktifkan kembali dari alur Master Mesin.</strong>
                </p>
            </div>
            <div class="mt-6 flex justify-end gap-2 border-t border-prime-border pt-4">
                <button type="button" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" autofocus @click="showLifecycle = false">Batal</button>
                <button type="button" class="inline-flex min-h-11 items-center rounded-micro px-4 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2" :class="lifecycleAction === 'retire' ? 'bg-prime-danger hover:bg-red-700 focus-visible:outline-prime-danger' : lifecycleAction === 'deactivate' ? 'bg-amber-700 hover:bg-amber-800 focus-visible:outline-amber-700' : 'bg-prime-success hover:bg-green-800 focus-visible:outline-prime-success'" @click="submitLifecycle">{{ lifecycleContent.submit }}</button>
            </div>
        </MachineDialog>

        <MachineDialog v-model="showDelete" title="Hapus Mesin?" destructive :fallback-focus="() => findMachineAction(selected?.id)">
            <div class="flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-red-50 text-prime-danger" aria-hidden="true"><PrimeIcon name="trash" class="h-6 w-6" /></span>
                <div class="min-w-0 text-sm leading-6 text-prime-muted">
                    <p class="break-words">Mesin <strong class="font-semibold text-prime-ink">{{ selected?.machine_code }} · {{ selected?.machine_name }}</strong> akan dihapus permanen.</p>
                    <p class="mt-1">Tindakan ini tidak dapat dibatalkan.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2 border-t border-prime-border pt-4">
                <button type="button" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" autofocus @click="showDelete = false">Batal</button>
                <button type="button" class="inline-flex min-h-11 items-center rounded-micro bg-prime-danger px-4 text-sm font-semibold text-white hover:bg-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-danger disabled:opacity-60" :disabled="!selected" @click="submitDelete">Hapus Permanen</button>
            </div>
        </MachineDialog>
    </AppShell>
</template>

<style scoped>
/* Selaras dengan skala Dashboard tanpa mengubah pemakai PageHeader lain. */
.machine-page-header :deep(h1) {
    font-size: 1.75rem;
    font-weight: 600;
    line-height: 1.25;
    letter-spacing: normal;
}
.machine-page-header :deep(header > p:first-child) {
    font-size: 0.6875rem;
    letter-spacing: 0.14em;
}
.machine-page-header :deep(header > p:last-child) {
    font-size: 0.875rem;
    line-height: 1.5rem;
}
@media (min-width: 640px) {
    .machine-page-header :deep(h1) {
        font-size: 2rem;
    }
}
.machine-menu {
    position: relative;
}
.machine-menu[open] {
    z-index: 1;
}
.menu-summary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.5rem;
    cursor: pointer;
    list-style: none;
    border-radius: 0.25rem;
    border: 1px solid var(--color-prime-border);
    background: #ffffff;
    padding: 0 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
}
.menu-summary::-webkit-details-marker {
    display: none;
}
.menu-summary:focus-visible {
    outline: 2px solid var(--color-prime-primary);
    outline-offset: 2px;
}
.menu-list {
    position: absolute;
    right: 0;
    margin-top: 0.25rem;
    width: 12rem;
    border-radius: 0.5rem;
    border: 1px solid var(--color-prime-border);
    background: #ffffff;
    padding: 0.25rem;
    box-shadow: 0 10px 30px rgb(0 0 0 / 0.08);
}
.menu-action {
    display: block;
    width: 100%;
    min-height: 2.75rem;
    padding: 0 0.75rem;
    border-radius: 0.25rem;
    text-align: left;
    font-size: 0.875rem;
    color: var(--color-prime-text);
}
.menu-action:hover {
    background: var(--color-prime-surface);
}
.menu-action:focus-visible {
    outline: 2px solid var(--color-prime-primary);
    outline-offset: -2px;
}
.machine-input {
    min-height: 2.75rem;
    width: 100%;
    border: 1px solid var(--color-prime-border);
    border-radius: 0.25rem;
    background: #ffffff;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: var(--color-prime-text);
}
.machine-input:focus {
    border-color: var(--color-prime-primary);
    outline: 2px solid var(--color-prime-primary);
    outline-offset: 1px;
}

/* State readonly (Kode Mesin saat Edit) butuh latar netral agar terbaca non-editable.
   Utility bg-prime-soft pada elemen kalah karena utilitas Tailwind berada di dalam
   @layer dan deklarasi background .machine-input di sini berada di luar layer, jadi
   state readonly dideklarasikan langsung dengan selektor atribut (spesifisitas cukup,
   tanpa !important) dan tidak menyentuh select/textarea yang tetap editable. */
.machine-input[readonly] {
    background: var(--color-prime-soft);
}
</style>
