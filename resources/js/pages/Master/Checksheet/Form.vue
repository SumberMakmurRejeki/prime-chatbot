<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppShell from '../../../layouts/AppShell.vue';
import PageHeader from '../../../components/PageHeader.vue';
import type { ChecksheetCandidateProps, WizardPayload } from '../../../types';
import { buildCandidateApplyRequest, normalizeCandidateStandards } from './candidate-apply-request.js';

type CandidateAssignmentPreview = {
    machine_id: number;
    machine_code: string;
    machine_name: string;
};

const page = usePage<ChecksheetCandidateProps>();
const props = computed(() => page.props as ChecksheetCandidateProps);
const labels = ['Informasi', 'Mesin', 'Part', 'Standard', 'Jadwal', 'Review'];
const step = shallowRef(errorStep());
const search = shallowRef('');
const location = shallowRef('');
const preview = shallowRef<Record<string, unknown> | null>(null);
const previewPayloadSnapshot = shallowRef<string | null>(null);
const previewBusy = shallowRef(false);
const applyBusy = shallowRef(false);
const status = shallowRef('');
const applyStatus = shallowRef('');
const weeklyDays = [{ id: 0, label: 'Minggu' }, { id: 1, label: 'Senin' }, { id: 2, label: 'Selasa' }, { id: 3, label: 'Rabu' }, { id: 4, label: 'Kamis' }, { id: 5, label: 'Jumat' }, { id: 6, label: 'Sabtu' }];
const form = useForm({
    checksheet_code: props.value.checksheet.checksheet_code,
    checksheet_name: props.value.checksheet.checksheet_name,
    description: props.value.checksheet.description,
    is_active: props.value.checksheet.is_active ? '1' : '0',
    wizard_payload: '',
});
watch(() => form.errors.selected_machine_ids, (error) => {
    if (error) setStep(2);
});
const payload = ref<WizardPayload>(normalizePayload(props.value.payload));
const originalSchedule = props.value.originalSchedule ?? payload.value.schedule;
const originalMachineIds = computed(() => new Set(props.value.originalMachineIds.map(Number)));
const scheduleChanged = computed(() => JSON.stringify(scheduleSignature(payload.value.schedule)) !== JSON.stringify(scheduleSignature(originalSchedule)));
const newMachineSelected = computed(() => payload.value.selected_machine_ids.some((id) => !originalMachineIds.value.has(Number(id))));
const scheduleApprovalRequired = computed(() => scheduleChanged.value || newMachineSelected.value);
const selectedActionIds = (row: unknown): number[] => {
    const ids = (row as Record<string, unknown>).action_ids;
    return Array.isArray(ids) ? ids.map(Number).filter((id) => Number.isInteger(id) && id > 0) : [];
};
function scheduleSignature(schedule: WizardPayload['schedule']): Record<string, unknown> {
    return {
        frequency_type: schedule.frequency_type ?? '',
        weekly_days: (schedule.weekly_days ?? []).map(Number),
        monthly_day: schedule.monthly_day === null ? null : Number(schedule.monthly_day),
        operational_from: schedule.operational_from ?? '',
    };
}

function toggleAction(row: unknown, actionId: number, checked: boolean): void {
    if (applyBusy.value) return;
    const standard = row as Record<string, unknown>;
    const actionIds = selectedActionIds(row);
    standard.action_ids = checked
        ? [...new Set([...actionIds, Number(actionId)])]
        : actionIds.filter((id) => id !== Number(actionId));
}
const errors = computed(() => form.errors as unknown as Record<string, string>);
const filteredMachines = computed(() => props.value.machines.filter((machine) => {
    const query = search.value.trim().toLowerCase();
    return (!query || `${machine.code} ${machine.name} ${machine.location ?? ''}`.toLowerCase().includes(query))
        && (!location.value || machine.location === location.value);
}));
const locations = computed(() => [...new Set(props.value.machines.map((machine) => machine.location).filter((value): value is string => Boolean(value)))]);
const machineNames = computed(() => props.value.machines.filter((machine) => payload.value.selected_machine_ids.includes(machine.id)).map((machine) => machine.code).join(', '));
const candidateAssignments = computed<CandidateAssignmentPreview[]>(() => {
    const candidates = preview.value?.candidate_assignments;
    return Array.isArray(candidates) ? candidates as CandidateAssignmentPreview[] : [];
});
const parts = computed(() => payload.value.selected_machine_ids.flatMap((machineId) => (payload.value.parts[String(machineId)] ?? []).map((part) => ({ machineId, part }))));
const standardCount = computed(() => Object.values(payload.value.standards).reduce((sum, rows) => sum + rows.length, 0));
const dates = shallowRef<string[]>([]);
const summary = computed(() => `${payload.value.selected_machine_ids.length} mesin · ${parts.value.length} part · ${standardCount.value} standard`);

function normalizePayload(input: unknown): WizardPayload {
    const value = (input && typeof input === 'object' ? input : {}) as Partial<WizardPayload>;
    const schedule = value.schedule ?? {} as WizardPayload['schedule'];
    return {
        selected_machine_ids: Array.isArray(value.selected_machine_ids) ? value.selected_machine_ids.map(Number) : [],
        parts: value.parts && !Array.isArray(value.parts) ? value.parts : {},
        standards: normalizeCandidateStandards(value.standards),
        schedule: {
            frequency_type: schedule.frequency_type ?? '',
            weekly_days: schedule.weekly_days ?? [],
            monthly_day: schedule.monthly_day ?? null,
            operational_from: schedule.operational_from ?? '',
            start_date: schedule.start_date ?? null,
            generate_until: schedule.generate_until ?? null,
        },
    };
}

function errorStep(): number {
    const errors = page.props.errors ?? {};
    for (const key of Object.keys(errors)) {
        if (key.startsWith('schedule') || key.startsWith('wizard_payload.schedule')) return 5;
        if (key.includes('standards')) return 4;
        if (key.includes('parts')) return 3;
        if (key.includes('selected_machine_ids')) return 2;
        if (['checksheet_code', 'checksheet_name', 'description', 'is_active'].includes(key)) return 1;
    }
    return 1;
}

function setStep(next: number): void {
    if (applyBusy.value) return;
    step.value = Math.max(1, Math.min(6, next));
    if (step.value === 6 && props.value.isEdit) void loadPreview();
}

function next(): void {
    if (applyBusy.value) return;
    if (step.value === 2 && payload.value.selected_machine_ids.length === 0) {
        form.setError('wizard_payload', 'Pilih minimal satu mesin.');
        return;
    }
    if (step.value === 3 && payload.value.selected_machine_ids.some((id) => !(payload.value.parts[String(id)] ?? []).length || payload.value.parts[String(id)].some((part) => !part.name.trim()))) {
        form.setError('wizard_payload', 'Setiap mesin harus memiliki minimal satu part dengan nama.');
        return;
    }
    if (step.value === 5 && (!payload.value.schedule.frequency_type || (!payload.value.schedule.operational_from && !props.value.isEdit))) {
        form.setError('wizard_payload', 'Lengkapi konfigurasi jadwal PM.');
        return;
    }
    form.clearErrors();
    setStep(step.value + 1);
}

function toggleMachine(id: number, checked: boolean): void {
    if (applyBusy.value) return;
    const key = String(id);
    payload.value.selected_machine_ids = checked
        ? [...new Set([...payload.value.selected_machine_ids, id])]
        : payload.value.selected_machine_ids.filter((value) => value !== id);
    if (checked && !payload.value.parts[key]) {
        const partId = `${id}-1`;
        payload.value.parts[key] = [{ id: partId, name: '', description: '' }];
        payload.value.standards[partId] = [newStandard()];
    }
    if (!checked) {
        for (const part of payload.value.parts[key] ?? []) {
            delete payload.value.standards[part.id];
        }
        delete payload.value.parts[key];
    }
}

function addPart(machineId: number): void {
    if (applyBusy.value) return;
    const key = String(machineId);
    payload.value.parts[key] ??= [];
    const id = `${machineId}-${Date.now()}`;
    payload.value.parts[key].push({ id, name: '', description: '' });
    payload.value.standards[id] = [newStandard()];
}

function removePart(machineId: number, partId: string): void {
    if (applyBusy.value) return;
    const rows = payload.value.parts[String(machineId)] ?? [];
    if (rows.length < 2) return;
    payload.value.parts[String(machineId)] = rows.filter((part) => part.id !== partId);
    delete payload.value.standards[partId];
}

function newStandard(): Record<string, unknown> {
    return { name: '', input_type: '', action_options: [], is_required: true, is_active: true };
}

function addStandard(partId: string): void {
    if (applyBusy.value) return;
    payload.value.standards[partId] ??= [];
    payload.value.standards[partId].push({ ...newStandard(), input_type: 'number', target_value: '', unit: '' });
}

function removeStandard(partId: string, index: number): void {
    if (applyBusy.value) return;
    const rows = payload.value.standards[partId] ?? [];
    if (rows.length <= 1) return;
    rows.splice(index, 1);
}

function setStandardType(partId: string, index: number, type: string): void {
    if (applyBusy.value) return;
    const row = payload.value.standards[partId][index] as Record<string, unknown>;
    Object.assign(row, { input_type: type, action_options: [] });
    delete row.action_ids;
    delete row.selection_mode;
    if (type === 'action') {
        row.action_ids = [];
        row.selection_mode = 'single';
    }
    delete row.target_value;
    delete row.min_value;
    delete row.max_value;
}

function serialize(): string {
    return JSON.stringify({
        ...payload.value,
        standards: Object.fromEntries(Object.entries(payload.value.standards).map(([partId, rows]) => [partId, rows.map((row) => {
            const standard = { ...(row as Record<string, unknown>) };
            if (standard.input_type !== 'action') {
                delete standard.action_ids;
                delete standard.selection_mode;
            } else if (Array.isArray(standard.action_ids)) {
                standard.action_ids = standard.action_ids.map(Number);
            }
            return standard;
        })])),
    });
}

let previewRequestId = 0;

function invalidatePreview(): void {
    previewRequestId++;
    previewPayloadSnapshot.value = null;
    preview.value = null;
    previewBusy.value = false;
    status.value = '';
}
watch(() => serialize(), invalidatePreview, { flush: 'sync' });

function refreshSchedulePreview(): void {
    if (applyBusy.value) return;
    if (props.value.isEdit) {
        void loadPreview();
        return;
    }
    void refreshDates();
    status.value = 'Memuat preview tanggal terbaru…';
}
function submit(payloadSnapshot = serialize()): void {
    if (applyBusy.value) return;
    if (props.value.isEdit && scheduleApprovalRequired.value) {
        status.value = newMachineSelected.value
            ? 'Mesin baru dan jadwal harus ditinjau serta diterapkan sebelum checksheet disimpan.'
            : 'Perubahan jadwal harus ditinjau dan diterapkan sebelum checksheet disimpan.';
        setStep(6);
        return;
    }
    form.wizard_payload = payloadSnapshot;
    const options = { preserveScroll: true };
    if (props.value.isEdit) {
        form.put(props.value.submitUrl, options);
        return;
    }
    form.post(props.value.submitUrl, options);
}

function handleEditSubmit(): void {
    submit();
}

let datesRequestId = 0;
async function refreshDates(): Promise<void> {
    const requestId = ++datesRequestId;
    dates.value = [];
    const schedule = payload.value.schedule;
    if (!schedule.frequency_type || !schedule.operational_from) return;

    try {
        const response = await fetch(props.value.scheduleDatesUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ wizard_payload: schedule }),
        });
        const result = await responseJson(response);
        if (requestId !== datesRequestId) return;
        if (!response.ok || !result || !Array.isArray(result.dates)) {
            status.value = 'Preview tanggal belum dapat dimuat; coba refresh kembali.';
            return;
        }
        dates.value = result.dates.slice(0, 10).map((date) => new Date(`${String(date)}T00:00:00Z`).toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }));
    } catch {
        if (requestId === datesRequestId) status.value = 'Preview tanggal belum dapat dimuat; coba refresh kembali.';
    }
}

watch(() => JSON.stringify(payload.value.schedule), () => void refreshDates(), { immediate: true });

async function responseJson(response: Response): Promise<Record<string, unknown> | null> {
    if (!(response.headers.get('content-type') ?? '').includes('application/json')) return null;
    try { return await response.json() as Record<string, unknown>; } catch { return null; }
}

async function loadPreview(): Promise<void> {
    if (!props.value.previewUrl || !props.value.applyUrl) return;

    const payloadSnapshot = serialize();
    previewPayloadSnapshot.value = payloadSnapshot;
    const requestId = ++previewRequestId;
    preview.value = null;
    previewBusy.value = true;
    status.value = 'Memuat pratinjau perubahan jadwal…';

    const isCurrentRequest = (): boolean => requestId === previewRequestId && payloadSnapshot === serialize();

    try {
        const response = await fetch(props.value.previewUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ wizard_payload: payloadSnapshot }) });
        const result = await responseJson(response);
        if (!isCurrentRequest()) return;
        if (!response.ok || !result) throw new Error('Pratinjau tidak dapat dimuat. Silakan coba kembali.');

        preview.value = result;
        const state = String(result.status ?? '');
        status.value = state === 'conflict' ? 'Ada konflik dengan jadwal atau data histori yang dilindungi; perubahan jadwal tidak dapat diterapkan.' : state === 'unresolved' ? 'Jadwal lama belum memiliki tanggal awal operasional; perubahan jadwal tidak akan disinkronkan.' : state === 'zero' ? 'Tidak ada perubahan jadwal yang perlu diterapkan.' : 'Pratinjau terbaru siap ditinjau.';
        if (applyStatus.value) applyStatus.value = 'Pratinjau diperbarui karena kondisi jadwal berubah. Tinjau kembali sebelum menerapkan.';
    } catch (error) {
        if (isCurrentRequest()) status.value = error instanceof Error ? error.message : 'Pratinjau tidak dapat dimuat.';
    } finally {
        if (isCurrentRequest()) previewBusy.value = false;
    }
}

async function applySchedule(): Promise<void> {
    const payloadSnapshot = previewPayloadSnapshot.value;
    const token = preview.value?.token;
    if (applyBusy.value || previewBusy.value || !props.value.applyUrl || !payloadSnapshot || payloadSnapshot !== serialize() || typeof token !== 'string' || !token || preview.value?.status === 'conflict') return;

    applyBusy.value = true;
    form.clearErrors();
    applyStatus.value = '';
    try {
        const requestBody = buildCandidateApplyRequest(form, payloadSnapshot, token);
        const response = await fetch(props.value.applyUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(requestBody) });
        const result = await responseJson(response);
        if (response.status === 422) {
            const validationErrors = result?.errors;
            const firstMessage = validationErrors && typeof validationErrors === 'object'
                ? Object.values(validationErrors).flat().find((message): message is string => typeof message === 'string')
                : undefined;
            const message = firstMessage ?? 'Data master tidak dapat disimpan. Periksa kembali input checksheet.';
            form.setError('wizard_payload', message);
            applyStatus.value = message;
            return;
        }
        if (response.status === 409) {
            applyStatus.value = 'Jadwal berubah sejak pratinjau dibuat; pratinjau diperbarui. Tinjau kembali sebelum menerapkan.';
            await loadPreview();
            return;
        }
        if (!response.ok || result?.status !== 'applied') throw new Error('Jadwal belum dapat diterapkan. Periksa kembali dampak jadwal.');
        if (payloadSnapshot !== serialize()) {
            applyStatus.value = 'Data berubah selama penerapan jadwal. Preview baru diperlukan sebelum menyimpan.';
            invalidatePreview();
            return;
        }
        if (!props.value.showUrl) throw new Error('Halaman checksheet tidak tersedia setelah penerapan jadwal.');
        router.visit(props.value.showUrl);
    } catch (error) {
        applyStatus.value = error instanceof Error ? error.message : 'Penerapan jadwal gagal.';
    } finally {
        applyBusy.value = false;
    }
}

function preventNavigationDuringApply(event: MouseEvent): void {
    if (applyBusy.value) event.preventDefault();
}

function csrf(): string { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''; }
function impactRows(): [string, unknown][] {
    const impact = (preview.value?.impact ?? {}) as Record<string, unknown>;
    return [['Dibuat', impact.created], ['Dihapus', impact.removed], ['Dipertahankan', impact.retained], ['Dilindungi', impact.protected], ['Konflik', impact.conflict]];
}
</script>

<template>
    <AppShell :auth="props.auth" :manage-ai="props.manageAi" :notification-endpoints="props.notificationEndpoints">
        <Head :title="props.isEdit ? 'Edit Checksheet' : 'Buat Checksheet'" />
        <section class="mx-auto max-w-5xl space-y-6" aria-labelledby="checksheet-title">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <PageHeader id="checksheet-title" :title="props.isEdit ? 'Edit Checksheet' : 'Buat Checksheet Baru'" description="Wizard Master PM Checksheet — kandidat Vue/Inertia" eyebrow="PM Management / Master" />
                <a :href="props.indexUrl" :aria-disabled="applyBusy" @click="preventNavigationDuringApply" class="inline-flex min-h-11 items-center rounded-micro border border-prime-border px-4 text-sm font-semibold text-prime-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary">Kembali ke daftar</a>

            </header>
            <p v-if="form.hasErrors" class="rounded-card border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">{{ Object.values(form.errors)[0] }}</p>
            <form class="space-y-5" :inert="applyBusy" @submit.prevent="submit()">
                <nav class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" aria-label="Langkah checksheet">
                    <button v-for="(label, index) in labels" :key="label" type="button" :disabled="applyBusy" class="min-h-11 rounded-micro border px-2 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary disabled:cursor-not-allowed disabled:opacity-60" :class="step === index + 1 ? 'border-prime-primary bg-prime-primary text-white' : step > index + 1 ? 'border-green-300 bg-green-50 text-green-900' : 'border-prime-border bg-white text-prime-muted'" :aria-current="step === index + 1 ? 'step' : undefined" @click="setStep(index + 1)"><span class="mr-1">{{ step > index + 1 ? '✓' : index + 1 }}</span>{{ label }}</button>
                </nav>
                <div class="rounded-card border border-prime-border bg-white p-4 shadow-level-2 sm:p-6">
                    <section v-if="step === 1" class="space-y-4" aria-labelledby="step-one-title">
                        <h2 id="step-one-title" class="text-xl font-semibold text-prime-ink">Informasi dasar checksheet</h2>
                        <label class="block text-sm font-semibold">Kode checksheet *<input v-model="form.checksheet_code" required maxlength="50" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3 font-normal" :aria-invalid="Boolean(form.errors.checksheet_code)"></label>
                        <p v-if="form.errors.checksheet_code" class="text-sm text-prime-danger" role="alert">{{ form.errors.checksheet_code }}</p>
                        <label class="block text-sm font-semibold">Nama checksheet *<input v-model="form.checksheet_name" required maxlength="150" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3 font-normal" :aria-invalid="Boolean(form.errors.checksheet_name)"></label>
                        <p v-if="form.errors.checksheet_name" class="text-sm text-prime-danger" role="alert">{{ form.errors.checksheet_name }}</p>
                        <label class="block text-sm font-semibold">Deskripsi<textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-micro border border-prime-border px-3 py-2 font-normal" /></label>
                        <label class="block max-w-xs text-sm font-semibold">Status<select v-model="form.is_active" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3 font-normal"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label>
                    </section>
                    <section v-else-if="step === 2" class="space-y-4" aria-labelledby="step-two-title">
                        <h2 id="step-two-title" class="text-xl font-semibold text-prime-ink">Pilih mesin</h2>
                        <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Cari mesin<input v-model="search" type="search" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><label class="text-sm">Lokasi<select v-model="location" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"><option value="">Semua lokasi</option><option v-for="item in locations" :key="item" :value="item">{{ item }}</option></select></label></div>
                        <p v-if="errors.selected_machine_ids || errors.wizard_payload" class="text-sm text-prime-danger" role="alert">{{ errors.selected_machine_ids || errors.wizard_payload }}</p>
                        <fieldset class="divide-y divide-prime-border rounded-card border border-prime-border"><legend class="sr-only">Mesin yang dipilih</legend><label v-for="machine in filteredMachines" :key="machine.id" class="flex min-h-12 cursor-pointer items-center gap-3 px-3 py-2"><input type="checkbox" class="size-5 accent-prime-primary" :checked="payload.selected_machine_ids.includes(machine.id)" @change="toggleMachine(machine.id, ($event.target as HTMLInputElement).checked)"><span class="font-semibold">{{ machine.code }} · {{ machine.name }}</span><span class="ml-auto text-xs text-prime-muted">{{ machine.location || '—' }}</span></label><p v-if="!filteredMachines.length" class="p-4 text-sm text-prime-muted">Tidak ada mesin yang cocok.</p></fieldset>
                    </section>
                    <section v-else-if="step === 3" class="space-y-4" aria-labelledby="step-three-title">
                        <h2 id="step-three-title" class="text-xl font-semibold text-prime-ink">Tentukan part mesin</h2>
                        <p v-if="errors.wizard_payload || errors.parts" class="text-sm text-prime-danger" role="alert">{{ errors.wizard_payload || errors.parts }}</p>
                        <article v-for="machineId in payload.selected_machine_ids" :key="machineId" class="space-y-3 rounded-card border border-prime-border p-4"><header class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold">{{ props.machines.find((machine) => machine.id === machineId)?.code }} · {{ props.machines.find((machine) => machine.id === machineId)?.name }}</h3><button type="button" class="min-h-11 px-3 font-semibold text-prime-primary focus-visible:outline-2 focus-visible:outline-prime-primary" @click="addPart(machineId)">+ Tambah part</button></header><div v-for="(part, index) in payload.parts[String(machineId)]" :key="part.id" class="grid gap-2 sm:grid-cols-[3rem_1fr_1fr_auto]"><span class="rounded-micro bg-prime-soft p-3 text-center">{{ index + 1 }}</span><label class="sr-only" :for="`part-${part.id}`">Nama part</label><input :id="`part-${part.id}`" v-model="part.name" maxlength="100" required placeholder="Nama part" class="min-h-11 rounded-micro border border-prime-border px-3"><input v-model="part.description" placeholder="Deskripsi opsional" class="min-h-11 rounded-micro border border-prime-border px-3"><button type="button" class="min-h-11 px-3 text-prime-danger focus-visible:outline-2 focus-visible:outline-prime-primary" :disabled="payload.parts[String(machineId)].length < 2" :aria-label="`Hapus part ${part.name || index + 1}`" @click="removePart(machineId, part.id)">Hapus</button></div></article>
                    </section>
                    <section v-else-if="step === 4" class="space-y-4" aria-labelledby="step-four-title">
                        <h2 id="step-four-title" class="text-xl font-semibold text-prime-ink">Standard pengecekan</h2>
                        <p v-if="errors.wizard_payload || errors.standards" class="text-sm text-prime-danger" role="alert">{{ errors.wizard_payload || errors.standards }}</p>
                        <article v-for="{ part, machineId } in parts" :key="part.id" class="space-y-3 rounded-card border border-prime-border p-4"><header class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-xs text-prime-muted">{{ props.machines.find((machine) => machine.id === machineId)?.code }}</p><h3 class="font-semibold">{{ part.name || '(Part belum diisi)' }}</h3></div><button type="button" class="min-h-11 px-3 font-semibold text-prime-primary" @click="addStandard(part.id)">+ Tambah standard</button></header><div v-for="(row, index) in payload.standards[part.id]" :key="`${part.id}-${index}`" class="space-y-3 rounded-card border border-prime-border p-3"><div class="grid gap-2 sm:grid-cols-2"><label class="text-sm">Nama standard<input v-model="(row as Record<string, unknown>).name" required maxlength="150" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><label class="text-sm">Tipe<select :value="(row as Record<string, unknown>).input_type as string" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3" @change="setStandardType(part.id, index, ($event.target as HTMLSelectElement).value)"><option value="">Pilih tipe…</option><option value="action">Action (pilihan)</option><option value="number">Number (target)</option><option value="range">Range (min-max)</option></select></label></div><div class="flex flex-wrap items-center gap-4 text-sm"><label><input v-model="(row as Record<string, unknown>).is_required" type="checkbox"> Wajib diisi</label><label><input v-model="(row as Record<string, unknown>).is_active" type="checkbox"> Aktif</label><button type="button" class="ml-auto min-h-11 text-prime-danger" :disabled="payload.standards[part.id].length < 2" @click="removeStandard(part.id, index)">Hapus standard</button></div><div v-if="(row as Record<string, unknown>).input_type === 'action'" class="space-y-3"><label class="block text-sm">Mode pilihan<select v-model="(row as Record<string, unknown>).selection_mode" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"><option value="single">Satu pilihan</option><option value="multiple">Beberapa pilihan</option></select></label><p v-if="!Array.isArray((row as Record<string, unknown>).action_ids) && ((row as Record<string, unknown>).action_options as string[] | undefined)?.length" class="text-sm text-prime-muted">Opsi legacy (tetap tersimpan): {{ ((row as Record<string, unknown>).action_options as string[]).join(', ') }}</p><fieldset class="space-y-2 rounded-micro border border-prime-border p-3"><legend class="px-1 text-sm font-semibold">Master Action</legend><p v-if="!props.actions.length" class="text-sm text-prime-muted">Belum ada Master Action yang tersedia.</p><label v-for="action in props.actions" :key="action.id" class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" :checked="selectedActionIds(row).includes(Number(action.id))" :disabled="!action.is_active && !selectedActionIds(row).includes(Number(action.id))" @change="toggleAction(row, Number(action.id), ($event.target as HTMLInputElement).checked)"><span>{{ action.action_label }}<span v-if="!action.is_active" class="text-prime-muted"> — Tidak aktif</span></span></label></fieldset></div><div v-else-if="(row as Record<string, unknown>).input_type === 'number'" class="grid gap-2 sm:grid-cols-2"><label class="text-sm">Target<input v-model="(row as Record<string, unknown>).target_value" type="number" step="0.01" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><label class="text-sm">Satuan<input v-model="(row as Record<string, unknown>).unit" maxlength="30" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label></div><div v-else-if="(row as Record<string, unknown>).input_type === 'range'" class="grid gap-2 sm:grid-cols-3"><label class="text-sm">Minimum<input v-model="(row as Record<string, unknown>).min_value" type="number" step="0.01" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><label class="text-sm">Maksimum<input v-model="(row as Record<string, unknown>).max_value" type="number" step="0.01" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><label class="text-sm">Satuan<input v-model="(row as Record<string, unknown>).unit" maxlength="30" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label></div></div></article>
                    </section>
                    <section v-else-if="step === 5" class="space-y-4" aria-labelledby="step-five-title">
                        <h2 id="step-five-title" class="text-xl font-semibold text-prime-ink">Pengaturan jadwal PM</h2><p v-if="errors.wizard_payload || errors.schedule" class="text-sm text-prime-danger" role="alert">{{ errors.wizard_payload || errors.schedule }}</p>
                        <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold">Frekuensi<select v-model="payload.schedule.frequency_type" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"><option value="">Pilih frekuensi…</option><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select></label><label v-if="payload.schedule.frequency_type === 'monthly'" class="text-sm font-semibold">Tanggal (1–31)<input v-model.number="payload.schedule.monthly_day" type="number" min="1" max="31" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label><fieldset v-if="payload.schedule.frequency_type === 'weekly'" class="space-y-2"><legend class="text-sm font-semibold">Pilih hari</legend><label v-for="day in weeklyDays" :key="day.id" class="flex min-h-10 items-center gap-2"><input v-model="payload.schedule.weekly_days" type="checkbox" :value="day.id">{{ day.label }}</label></fieldset><label class="text-sm font-semibold">Mulai Jadwal PRIME<input v-model="payload.schedule.operational_from" type="date" class="mt-1 min-h-11 w-full rounded-micro border border-prime-border px-3"></label></div>
                        <section class="space-y-3 rounded-card border border-prime-border bg-prime-soft p-4" aria-labelledby="schedule-preview-title"><header class="flex flex-wrap items-center justify-between gap-2"><h3 id="schedule-preview-title" class="font-semibold">Preview jadwal</h3><button type="button" class="min-h-11 px-3 font-semibold text-prime-primary" @click="refreshSchedulePreview">Refresh preview</button></header><p v-if="!dates.length" class="text-sm text-prime-muted">Isi konfigurasi jadwal untuk melihat tanggal yang akan datang.</p><div v-else class="flex flex-wrap gap-2"><span v-for="date in dates" :key="date" class="rounded-full border border-prime-border bg-white px-3 py-1 text-sm">{{ date }}</span></div></section>
                    </section>
                    <section v-else class="space-y-4" aria-labelledby="step-six-title">
                        <h2 id="step-six-title" class="text-xl font-semibold text-prime-ink">Review checksheet</h2>
                        <p class="rounded-card bg-prime-soft p-4 font-semibold">{{ form.checksheet_code || '—' }} · {{ form.checksheet_name || '—' }} · {{ form.is_active === '1' ? 'Aktif' : 'Nonaktif' }}</p>
                        <p>{{ summary }}</p>
                        <p>Mesin: {{ machineNames || '—' }}</p>
                        <ul v-if="candidateAssignments.length" class="rounded-card border border-prime-border p-3 text-sm" aria-label="Mesin baru yang akan ditambahkan">
                            <li class="mb-1 font-semibold">Mesin baru akan memperoleh assignment dan jadwal setelah Apply:</li>
                            <li v-for="assignment in candidateAssignments" :key="assignment.machine_id">{{ assignment.machine_code }} · {{ assignment.machine_name }}</li>
                        </ul>
                        <p>Frekuensi: {{ payload.schedule.frequency_type || '—' }} · Mulai: {{ payload.schedule.operational_from || 'Belum diset' }}</p>
                        <p v-if="status" class="rounded-card border border-prime-border p-3 text-sm" role="status">{{ previewBusy ? '⏳ ' : '' }}{{ status }}</p>
                        <p v-if="applyStatus" class="rounded-card border border-amber-300 bg-amber-50 p-3 text-sm" role="alert">{{ applyStatus }}</p>
                        <section v-if="props.isEdit" class="space-y-3">
                            <div class="overflow-x-auto rounded-card border border-prime-border">
                                <table class="w-full text-left text-sm">
                                    <caption class="sr-only">Dampak perubahan jadwal dan riwayat</caption>
                                    <thead><tr><th class="p-3">Dampak</th><th class="p-3">Tanggal</th></tr></thead>
                                    <tbody>
                                        <tr v-for="[label, values] in impactRows()" :key="label" class="border-t border-prime-border">
                                            <th class="p-3">{{ label }}</th>
                                            <td class="p-3">{{ Array.isArray(values) ? values.join(', ') || '—' : '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button v-if="preview?.token && preview.status !== 'conflict' && preview.status !== 'unresolved'" type="button" class="min-h-11 rounded-micro bg-prime-primary px-4 font-semibold text-white disabled:opacity-60" :disabled="applyBusy || previewBusy" @click="applySchedule">{{ applyBusy ? 'Menerapkan…' : 'Terapkan jadwal & simpan' }}</button>
                        </section>
                    </section>
                </div>
                <footer class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="min-h-11 rounded-micro border border-prime-border px-4 font-semibold disabled:opacity-50" :disabled="step === 1 || applyBusy" @click="setStep(step - 1)">Kembali</button>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="step < 6" type="button" class="min-h-11 rounded-micro bg-prime-primary px-5 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60" :disabled="applyBusy" @click="next">Selanjutnya</button>
                        <button v-else-if="!props.isEdit" type="submit" class="min-h-11 rounded-micro bg-prime-primary px-5 font-semibold text-white disabled:opacity-60" :disabled="form.processing || applyBusy">{{ form.processing ? 'Menyimpan…' : 'Simpan checksheet' }}</button>
                        <button v-else-if="!scheduleApprovalRequired" type="button" class="min-h-11 rounded-micro bg-prime-primary px-5 font-semibold text-white disabled:opacity-60" :disabled="form.processing || applyBusy" @click="handleEditSubmit">{{ form.processing ? 'Menyimpan…' : 'Simpan perubahan master' }}</button>
                    </div>
                </footer>
            </form>
        </section>
    </AppShell>
</template>
