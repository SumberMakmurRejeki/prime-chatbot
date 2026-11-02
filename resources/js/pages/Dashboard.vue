<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppShell from '../layouts/AppShell.vue';
import PmRateChart from '../components/charts/PmRateChart.vue';
import BreakdownDurationCard from '../components/charts/BreakdownDurationCard.vue';
import PrimeIcon from '../components/PrimeIcon.vue';
import type { DashboardProps } from '../types';

const page = usePage<DashboardProps>();

const props = computed(() => page.props as DashboardProps);
const startDate = ref(props.value.dateRange.start);
const endDate = ref(props.value.dateRange.end);
// Filter dikirim ke rute Dashboard yang sedang dibuka, sehingga /dashboard dan
// permukaan pembanding /internal/dashboard-vue tetap memakai URL-nya masing-masing.
const currentDashboardPath = computed(() => String(page.url).split('?')[0]);
const filterError = ref('');
const loading = ref(false);
const pmDonePercentage = computed(() => props.value.pmStatus.total > 0
    ? Math.round((props.value.pmStatus.completed / props.value.pmStatus.total) * 100)
    : 0);

const hasMeaningfulPmData = computed(() => props.value.pmRateSeries.some((point) => point.target_percentage > 0));

// Back/forward dan perubahan props Inertia selalu menyinkronkan kontrol filter.
watch(() => props.value.dateRange, (range) => {
    startDate.value = range.start;
    endDate.value = range.end;
}, { deep: true });

function applyFilter(): void {
    if (startDate.value && endDate.value && endDate.value < startDate.value) {
        filterError.value = 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.';
        return;
    }

    filterError.value = '';
    loading.value = true;
    router.get(currentDashboardPath.value, {
        start_date: startDate.value,
        end_date: endDate.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: false,
        onFinish: () => { loading.value = false; },
    });
}

function resetFilter(): void {
    startDate.value = props.value.defaultDateRange.start;
    endDate.value = props.value.defaultDateRange.end;
    applyFilter();
}

function handlePopState(): void {
    const query = new URLSearchParams(window.location.search);
    if (query.has('start_date') && query.has('end_date')) {
        startDate.value = query.get('start_date') ?? props.value.dateRange.start;
        endDate.value = query.get('end_date') ?? props.value.dateRange.end;
    } else {
        startDate.value = props.value.dateRange.start;
        endDate.value = props.value.dateRange.end;
    }
}

onMounted(() => window.addEventListener('popstate', handlePopState));
onBeforeUnmount(() => window.removeEventListener('popstate', handlePopState));
</script>

<template>
    <AppShell :auth="props.auth" :manage-ai="props.manageAi" :notification-endpoints="props.notificationEndpoints">
        <Head title="Dashboard PRIME" />

        <section class="mx-auto max-w-[1440px] space-y-6" aria-labelledby="dashboard-title">
            <header class="flex flex-col gap-1">
                <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-prime-primary">Maintenance overview</p>
                <h1 id="dashboard-title" class="text-[1.75rem] font-semibold leading-tight tracking-normal text-prime-text sm:text-[2rem]">Dashboard PRIME</h1>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-prime-muted">Pantau performa preventive maintenance dan breakdown pada periode yang dipilih.</p>
            </header>

            <div v-if="props.loadErrorMessage" class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <PrimeIcon name="alert" class="mt-0.5 h-5 w-5 shrink-0" />
                <p>{{ props.loadErrorMessage }}</p>
            </div>

            <form class="grid grid-cols-1 items-end gap-4 rounded-lg border border-prime-border bg-white p-4 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,14rem)_minmax(12rem,14rem)_auto]" @submit.prevent="applyFilter">
                <div>
                    <label for="dashboard-start-date" class="mb-1.5 block text-sm font-semibold text-prime-text">Tanggal Mulai</label>
                    <input id="dashboard-start-date" v-model="startDate" name="start_date" type="date" required class="h-11 w-full rounded-md border border-prime-border px-3 text-sm text-prime-text focus:border-prime-primary focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary">
                </div>
                <div>
                    <label for="dashboard-end-date" class="mb-1.5 block text-sm font-semibold text-prime-text">Tanggal Selesai</label>
                    <input id="dashboard-end-date" v-model="endDate" name="end_date" type="date" required class="h-11 w-full rounded-md border border-prime-border px-3 text-sm text-prime-text focus:border-prime-primary focus:outline-2 focus:outline-offset-1 focus:outline-prime-primary">
                </div>
                <div class="flex flex-wrap gap-2 xl:justify-end">
                    <button type="submit" class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-prime-primary px-4 text-sm font-semibold text-white transition-colors hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-60" :disabled="loading">
                        <PrimeIcon name="filter" class="h-4 w-4" /> Terapkan Filter
                    </button>
                    <button type="button" class="h-11 rounded-md border border-prime-border px-4 text-sm font-semibold text-prime-text hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:opacity-60" :disabled="loading" @click="resetFilter">Reset</button>
                </div>
                <p v-if="filterError" class="text-sm font-medium text-prime-error sm:col-span-2 xl:col-span-3" role="alert">{{ filterError }}</p>
                <p v-else-if="Object.keys(props.errors ?? {}).length" class="text-sm font-medium text-prime-error sm:col-span-2 xl:col-span-3" role="alert">
                    {{ Object.values(props.errors).join(' ') }}
                </p>
            </form>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 2xl:grid-cols-4" aria-label="Indikator utama">
                <article class="rounded-lg border border-prime-border bg-prime-primary-soft p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">Total Mesin</h2>
                            <p class="mt-3 text-4xl font-bold leading-none tracking-tight text-prime-text">{{ props.machineSummary.total }}</p>
                            <p class="mt-3 text-sm"><span class="font-semibold text-prime-primary">{{ props.machineSummary.active }} Aktif</span><span class="mx-2 text-prime-muted">·</span><span class="text-prime-muted">{{ props.machineSummary.inactive }} Nonaktif</span></p>
                        </div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-prime-primary ring-1 ring-inset ring-prime-primary/20"><PrimeIcon name="machine" class="h-5 w-5" /></span>
                    </div>
                </article>

                <article class="rounded-lg border border-prime-border bg-prime-success-soft p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div><h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">MTBF</h2><p class="mt-3 text-4xl font-bold leading-none tracking-tight text-prime-text">{{ props.mtbfHours }} <span class="text-lg font-semibold">Jam</span></p><p class="mt-3 text-xs text-prime-muted">Rata-rata waktu antar kerusakan</p></div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-prime-success ring-1 ring-inset ring-prime-success/20"><PrimeIcon name="clock" class="h-5 w-5" /></span>
                    </div>
                </article>

                <article class="rounded-lg border border-prime-border bg-prime-warning-soft p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div><h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">MTBR</h2><p class="mt-3 text-4xl font-bold leading-none tracking-tight text-prime-text">{{ props.mtbrHours }} <span class="text-lg font-semibold">Jam</span></p><p class="mt-3 text-xs text-prime-muted">Rata-rata waktu perbaikan</p></div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-prime-warning ring-1 ring-inset ring-prime-warning/20"><PrimeIcon name="wrench" class="h-5 w-5" /></span>
                    </div>
                </article>

                <article class="rounded-lg border border-prime-border bg-slate-50 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">PM Execution</h2>
                            <p class="mt-3 text-4xl font-bold leading-none tracking-tight text-prime-text">{{ props.pmStatus.total }}</p>
                            <p class="mt-3 text-sm text-prime-muted">{{ pmDonePercentage }}% Done <span class="mx-1">·</span>{{ props.pmStatus.overdue }} overdue</p>
                            <p class="mt-1 text-xs text-prime-muted">{{ props.pmStatus.completed }} selesai pada periode ini</p>
                        </div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-slate-600 ring-1 ring-inset ring-prime-border"><PrimeIcon name="clipboard" class="h-5 w-5" /></span>
                    </div>
                </article>
            </section>

            <section class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]" aria-label="Analitik preventive maintenance dan breakdown">
                <article class="rounded-lg border border-prime-border bg-white p-5">
                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">PM Achievement</h2><p class="mt-1 text-xs text-prime-muted">Aktual dan target pelaksanaan per bulan</p></div>
                        <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-prime-muted"><span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-prime-primary" />PM Aktual (%)</span><span class="inline-flex items-center gap-2"><span class="w-5 border-t-2 border-dashed border-prime-primary" />Target (%)</span></div>
                    </div>
                    <div v-if="!hasMeaningfulPmData" class="flex min-h-[16rem] flex-col items-center justify-center text-center"><p class="text-[0.9375rem] font-semibold tracking-normal text-prime-text">Belum ada data PM</p><p class="mt-1 max-w-xs text-xs leading-5 text-prime-muted">Data PM belum tersedia pada periode yang dipilih.</p></div>
                    <PmRateChart v-else :series="props.pmRateSeries" />
                </article>
                <BreakdownDurationCard :breakdown="props.breakdownDurationSeries" />
            </section>

            <section class="overflow-hidden rounded-lg border border-prime-border bg-white" aria-labelledby="breakdown-status-title">
                <header class="border-b border-prime-border px-5 py-4">
                    <h2 id="breakdown-status-title" class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">Breakdown Status</h2>
                    <p class="mt-1 text-xs text-prime-muted">Breakdown selesai pada periode terpilih</p>
                </header>
                <div v-if="props.closedBreakdownLogs.length === 0" class="flex min-h-32 flex-col items-center justify-center px-5 text-center"><p class="text-[0.9375rem] font-semibold tracking-normal text-prime-text">Belum ada data breakdown</p><p class="mt-1 text-xs text-prime-muted">Tidak ada breakdown ditutup pada periode yang dipilih.</p></div>
                <div v-else class="max-h-[22rem] overflow-auto">
                    <table class="w-full min-w-[38rem] border-collapse text-left text-sm">
                        <caption class="sr-only">Daftar breakdown yang ditutup selama periode</caption>
                        <thead class="sticky top-0 bg-prime-surface text-xs text-prime-muted">
                            <tr><th scope="col" class="px-5 py-3 font-semibold">Nama Mesin</th><th scope="col" class="px-5 py-3 font-semibold">Part Mesin</th><th scope="col" class="px-5 py-3 font-semibold">Tanggal Ditutup</th><th scope="col" class="px-5 py-3 font-semibold">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-prime-border">
                            <tr v-for="(item, index) in props.closedBreakdownLogs" :key="`${item.machine_name}-${item.part_name}-${item.closed_date_label}-${index}`" class="hover:bg-slate-50/70">
                                <td class="px-5 py-3.5 font-medium text-prime-text">{{ item.machine_name }}</td><td class="px-5 py-3.5 text-prime-text">{{ item.part_name }}</td><td class="px-5 py-3.5 text-prime-muted">{{ item.closed_date_label }}</td><td class="px-5 py-3.5"><span class="inline-flex items-center rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-800">{{ item.status }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </AppShell>
</template>
