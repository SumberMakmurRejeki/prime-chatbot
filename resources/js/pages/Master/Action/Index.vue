<script setup lang="ts">
import { computed, onBeforeUnmount, shallowRef, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppShell from '../../../layouts/AppShell.vue';
import BaseButton from '../../../components/BaseButton.vue';
import MachineDialog from '../../../components/MachineDialog.vue';
import PageHeader from '../../../components/PageHeader.vue';
import PrimeIcon from '../../../components/PrimeIcon.vue';
import type { MasterAction, MasterActionProps } from '../../../types';

const page = usePage<MasterActionProps>();
const props = computed(() => page.props as MasterActionProps);
const basePath = computed(() => String(page.url).split('?')[0]);
const search = shallowRef(props.value.filters.search);
const showForm = shallowRef(false);
const selected = shallowRef<MasterAction | null>(null);
const formMode = shallowRef<'create' | 'edit'>('create');
const form = useForm({ action_label: '' });
const formTitle = computed(() => formMode.value === 'edit' ? 'Edit Master Action' : 'Tambah Master Action');
const commaGuidance = computed(() => form.action_label.includes(','));
const isFilterActive = computed(() => Boolean(props.value.filters.search));
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let pendingSearch: string | null = null;

const labelDescribedBy = computed(() => [
    'master-action-help',
    commaGuidance.value ? 'master-action-comma-guidance' : null,
    form.errors.action_label ? 'master-action-error' : null,
].filter(Boolean).join(' '));

watch(() => props.value.filters.search, (value) => {
    if (value === pendingSearch) {
        pendingSearch = null;
        return;
    }

    clearTimeout(searchTimer);
    pendingSearch = null;
    search.value = value;
});

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (value !== props.value.filters.search) {
            pendingSearch = value;
            visit({ search: value }, true);
        }
    }, 300);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function buildQuery(changes: Record<string, string | number | undefined>, resetPage: boolean): Record<string, string | number> {
    const merged: Record<string, string | number | undefined> = {
        search: props.value.filters.search,
        ...changes,
    };
    if (resetPage) {
        merged.page = 1;
    }
    return Object.fromEntries(Object.entries(merged).filter((entry): entry is [string, string | number] =>
        entry[1] !== undefined && entry[1] !== ''));
}

function visit(changes: Record<string, string | number | undefined>, resetPage = false): void {
    router.get(basePath.value, buildQuery(changes, resetPage), {
        preserveState: true,
        preserveScroll: true,
    });
}

function resetSearch(): void {
    clearTimeout(searchTimer);
    pendingSearch = '';
    search.value = '';
    visit({ search: undefined }, true);
}

function startCreate(): void {
    selected.value = null;
    formMode.value = 'create';
    // Default useForm() Inertia bergeser ke data submit terakhir yang sukses, sehingga reset()
    // berisiko memuat ulang label lama; dialog Tambah harus selalu dibuka dengan input kosong.
    form.action_label = '';
    form.clearErrors();
    showForm.value = true;
}

function startEdit(action: MasterAction): void {
    selected.value = action;
    formMode.value = 'edit';
    form.clearErrors();
    form.action_label = action.action_label;
    showForm.value = true;
}

function pageLinkText(link: { label: string }): string {
    if (link.label.includes('Previous')) {
        return '‹';
    }
    if (link.label.includes('Next')) {
        return '›';
    }

    return link.label;
}

function pageLinkAriaLabel(link: { label: string }): string {
    if (link.label.includes('Previous')) {
        return 'Halaman sebelumnya';
    }
    if (link.label.includes('Next')) {
        return 'Halaman berikutnya';
    }
    if (link.label === '...') {
        return 'Halaman lainnya';
    }

    return `Halaman ${link.label}`;
}

function submitAction(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (formMode.value === 'create') {
                form.action_label = '';
                form.clearErrors();
            }
            showForm.value = false;
        },
    };

    if (formMode.value === 'edit' && selected.value) {
        form.patch(selected.value.update_url, options);
        return;
    }

    form.post(props.value.storeUrl, options);
}

function goPage(url: string | null): void {
    if (url) {
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    }
}

function toggleStatus(action: MasterAction): void {
    const url = action.is_active ? action.deactivate_url : action.activate_url;
    if (action.is_active && !window.confirm('Nonaktifkan Master Action ini?')) {
        return;
    }

    router.patch(url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppShell :auth="props.auth" :manage-ai="props.manageAi" :notification-endpoints="props.notificationEndpoints">
        <Head title="Master Action PRIME" />

        <section class="mx-auto max-w-[1440px] space-y-6 text-sm" aria-labelledby="action-title">
            <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="action-page-header">
                    <PageHeader id="action-title" title="Master Action" description="Kelola pilihan action reusable untuk standard pengecekan PM." eyebrow="PM Management / Master PM Checksheet" />
                </div>
                <BaseButton @click="startCreate">
                    <PrimeIcon name="clipboard" class="mr-2 h-4 w-4" /> Tambah
                </BaseButton>
            </header>

            <p v-if="props.flash.success" class="rounded-card border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ props.flash.success }}</p>
            <p v-else-if="props.flash.error" class="rounded-card border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ props.flash.error }}</p>

            <section class="space-y-4" aria-label="Daftar Master Action">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="relative block w-full sm:max-w-md">
                        <span class="sr-only">Cari label action</span>
                        <PrimeIcon name="search" class="pointer-events-none absolute left-3 top-3 h-5 w-5 text-prime-muted" />
                        <input v-model="search" type="search" autocomplete="off" placeholder="Cari label action..." class="h-11 w-full rounded-micro border border-prime-border bg-white pl-10 pr-3 text-sm text-prime-text placeholder:text-prime-muted focus:border-prime-primary focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary">
                    </label>
                    <button v-if="isFilterActive" type="button" class="h-11 self-start rounded-micro border border-prime-border bg-white px-4 text-sm font-medium text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary sm:self-auto" @click="resetSearch">Reset pencarian</button>
                </div>

                <div class="overflow-hidden rounded-card border border-prime-border bg-white">
                    <div v-if="props.actions.data.length === 0" class="px-6 py-14 text-center">
                        <h2 class="text-base font-semibold text-prime-ink">{{ isFilterActive ? 'Master Action tidak ditemukan' : 'Belum ada Master Action' }}</h2>
                        <p class="mt-2 text-sm text-prime-muted">{{ isFilterActive ? 'Coba ubah kata kunci pencarian.' : 'Tambahkan pilihan action untuk standard pengecekan PM.' }}</p>
                        <button v-if="!isFilterActive" type="button" class="mt-4 inline-flex min-h-11 items-center font-semibold text-prime-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startCreate">Tambah Master Action</button>
                    </div>

                    <template v-else>
                        <div class="hidden md:block">
                            <table class="w-full table-fixed border-collapse text-left text-sm">
                                <caption class="sr-only">Daftar Master Action dan status</caption>
                                <thead class="bg-prime-surface text-xs text-prime-muted">
                                    <tr>
                                        <th scope="col" class="w-16 px-4 py-3 font-semibold">No</th>
                                        <th scope="col" class="px-4 py-3 font-semibold">Label Action</th>
                                        <th scope="col" class="w-32 px-4 py-3 font-semibold">Status</th>
                                        <th scope="col" class="w-48 px-4 py-3 text-right font-semibold">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-prime-border">
                                    <tr v-for="(action, index) in props.actions.data" :key="action.id" class="bg-white hover:bg-slate-50/70">
                                        <td class="px-4 py-3.5 text-prime-muted">{{ (props.actions.current_page - 1) * props.actions.per_page + index + 1 }}</td>
                                        <td class="break-words px-4 py-3.5 font-medium text-prime-ink">{{ action.action_label }}</td>
                                        <td class="px-4 py-3.5">
                                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="action.is_active ? 'border-green-200 bg-green-50 text-green-800' : 'border-prime-border bg-prime-soft text-prime-muted'">{{ action.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex flex-wrap items-center justify-end gap-1">
                                                <button type="button" class="inline-flex min-h-10 items-center rounded-micro px-3 text-sm font-semibold text-prime-primary hover:bg-prime-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startEdit(action)">Edit</button>
                                                <button type="button" class="inline-flex min-h-10 items-center rounded-micro px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :class="action.is_active ? 'text-prime-warning hover:bg-amber-50' : 'text-prime-success hover:bg-green-50'" @click="toggleStatus(action)">{{ action.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid gap-3 p-3 md:hidden">
                            <article v-for="(action, index) in props.actions.data" :key="action.id" class="min-w-0 rounded-card border border-prime-border p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-medium text-prime-muted">{{ (props.actions.current_page - 1) * props.actions.per_page + index + 1 }}</p>
                                        <h2 class="mt-1 break-words text-base font-semibold text-prime-ink">{{ action.action_label }}</h2>
                                    </div>
                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-semibold" :class="action.is_active ? 'border-green-200 bg-green-50 text-green-800' : 'border-prime-border bg-prime-soft text-prime-muted'">{{ action.is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                </div>
                                <div class="mt-3 flex flex-wrap justify-end gap-1 border-t border-prime-border pt-2">
                                    <button type="button" class="inline-flex min-h-10 items-center rounded-micro px-3 text-sm font-semibold text-prime-primary hover:bg-prime-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" @click="startEdit(action)">Edit</button>
                                    <button type="button" class="inline-flex min-h-10 items-center rounded-micro px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :class="action.is_active ? 'text-prime-warning hover:bg-amber-50' : 'text-prime-success hover:bg-green-50'" @click="toggleStatus(action)">{{ action.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                </div>
                            </article>
                        </div>

                        <footer class="flex flex-col gap-3 border-t border-prime-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-prime-muted">Menampilkan {{ props.actions.from ?? 0 }}-{{ props.actions.to ?? 0 }} dari total {{ props.actions.total }} data</p>
                            <nav class="flex flex-wrap items-center gap-1" aria-label="Navigasi halaman Master Action">
                                <button v-for="link in props.actions.links" :key="`${link.label}-${link.url}`" type="button" class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-micro border px-2 text-sm" :class="link.active ? 'border-prime-primary bg-prime-primary text-white' : 'border-prime-border bg-white text-prime-text hover:bg-prime-surface'" :disabled="!link.url || link.active || link.label === '...'" :aria-current="link.active ? 'page' : undefined" :aria-label="pageLinkAriaLabel(link)" @click="goPage(link.url)">{{ pageLinkText(link) }}</button>
                            </nav>
                        </footer>
                    </template>
                </div>
            </section>
        </section>

        <MachineDialog v-model="showForm" :title="formTitle">
            <form class="space-y-4" novalidate @submit.prevent="submitAction">
                <div>
                    <label for="master-action-label" class="mb-1.5 block text-sm font-semibold text-prime-text">Label Action <span class="text-prime-danger" aria-hidden="true">*</span></label>
                    <input id="master-action-label" v-model="form.action_label" name="action_label" type="text" maxlength="50" required autofocus class="action-input" :aria-invalid="Boolean(form.errors.action_label)" :aria-describedby="labelDescribedBy">
                    <p id="master-action-help" class="mt-1.5 text-xs leading-5 text-prime-muted">1 Master Action = 1 pilihan di PM Executor. Buat pilihan satu per satu, misalnya: OK / NG / TAMBAH OLI.</p>
                    <!-- Live region tetap tersedia agar perubahan panduan koma diumumkan pembaca layar. -->
                    <div role="status" aria-live="polite">
                        <p v-if="commaGuidance" id="master-action-comma-guidance" class="mt-1 text-xs leading-5 text-amber-800">Label ini mengandung koma. Jika Anda bermaksud membuat beberapa pilihan, buat setiap pilihan sebagai Master Action terpisah.</p>
                    </div>
                    <p v-if="form.errors.action_label" id="master-action-error" class="mt-1 text-sm font-medium text-prime-error" role="alert">{{ form.errors.action_label }}</p>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-prime-border pt-4">
                    <button type="button" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border bg-white px-4 text-sm font-semibold text-prime-ink hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" :disabled="form.processing" @click="showForm = false">Batal</button>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-micro bg-prime-primary px-4 text-sm font-semibold text-white hover:bg-prime-primary-active focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:cursor-not-allowed disabled:opacity-60" :disabled="form.processing">{{ form.processing ? 'Menyimpan…' : 'Simpan' }}</button>
                </div>
            </form>
        </MachineDialog>
    </AppShell>
</template>

<style scoped>
.action-page-header :deep(h1) {
    font-size: 1.75rem;
    font-weight: 600;
    line-height: 1.25;
    letter-spacing: normal;
}
.action-page-header :deep(header > p:first-child) {
    font-size: 0.6875rem;
    letter-spacing: 0.14em;
}
.action-page-header :deep(header > p:last-child) {
    font-size: 0.875rem;
    line-height: 1.5rem;
}
@media (min-width: 640px) {
    .action-page-header :deep(h1) {
        font-size: 2rem;
    }
}
.action-input {
    min-height: 2.75rem;
    width: 100%;
    border: 1px solid var(--color-prime-border);
    border-radius: var(--radius-micro);
    background: #ffffff;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: var(--color-prime-text);
}
.action-input:focus {
    border-color: var(--color-prime-primary);
    outline: 2px solid var(--color-prime-primary);
    outline-offset: 1px;
}
.action-input[aria-invalid='true'] {
    border-color: var(--color-prime-danger);
}
</style>
