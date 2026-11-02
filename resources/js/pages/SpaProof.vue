<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import BaseButton from '../components/BaseButton.vue';
import BaseCard from '../components/BaseCard.vue';
import PageHeader from '../components/PageHeader.vue';
import AppShell from '../layouts/AppShell.vue';
import type { SpaProofProps } from '../types';

const props = defineProps<SpaProofProps>();

function visitClientState(): void {
    router.get(
        props.clientVisitUrl,
        {},
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <AppShell :auth="props.auth">
        <div class="space-y-6">
            <PageHeader
                eyebrow="SPA-03"
                title="Inertia 3 + Vue 3 Foundation Proof"
                description="Halaman baca-saja terisolasi untuk membuktikan stack Inertia, Vue, dan TypeScript tanpa menyentuh modul Blade operasional."
            />

            <BaseCard>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-prime-muted">Kondisi render</p>
                        <p class="mt-1 text-base font-semibold text-prime-ink" data-proof-state :data-state="props.state">
                            {{ props.state === 'client' ? 'State kunjungan klien (query tervalidasi)' : 'State kunjungan server awal' }}
                        </p>
                    </div>
                    <div>
                        <p class="mt-1 text-base font-semibold text-prime-ink">{{ props.proofStage }}</p>
                    </div>
                </div>
            </BaseCard>

            <BaseCard>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <BaseButton variant="secondary" @click="visitClientState">
                        Jalankan kunjungan klien Inertia
                    </BaseButton>
                    <BaseButton href="/dashboard" variant="primary">
                        Kembali ke Dashboard (Blade)
                    </BaseButton>
                </div>
                <p class="mt-4 text-sm leading-6 text-prime-muted">
                    Tombol pertama memicu transisi klien ke state <code class="rounded-micro bg-prime-soft px-1.5 py-0.5">visit=client</code>
                    pada rute yang sama. Tombol kedua adalah navigasi dokumen penuh ke aplikasi Blade.
                </p>
            </BaseCard>
        </div>
    </AppShell>
</template>
