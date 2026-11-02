<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: boolean;
    title: string;
    destructive?: boolean;
    /** Fokus cadangan bila pemicu asli sudah tidak bisa difokuskan saat dialog ditutup. */
    fallbackFocus?: () => HTMLElement | null;
}>(), {
    destructive: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = computed(() => `machine-dialog-${props.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);
let opener: HTMLElement | null = null;

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function close(): void {
    emit('update:modelValue', false);
}

/** Abaikan kontrol yang tidak dijadikan tab stop oleh browser: disabled, tersembunyi, inert, atau tabindex -1. */
function isTabStop(control: HTMLElement): boolean {
    return control.tabIndex >= 0
        && !control.matches(':disabled')
        && !control.closest('[inert]')
        && window.getComputedStyle(control).visibility !== 'hidden'
        && control.getClientRects().length > 0;
}

function focusableControls(element: HTMLDialogElement): HTMLElement[] {
    return Array.from(element.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(isTabStop);
}

/**
 * Chromium menyisipkan BODY dan elemen <dialog>-nya sendiri (scroll container
 * yang bisa difokuskan) di antara kontrol terakhir dan kontrol pertama saat Tab
 * menembus batas dialog modal. Fokus sementara itu menghilangkan jejak kontrol
 * dan memicu lompatan scroll ke atas, jadi perpindahan pada batas tersebut
 * diputar ulang secara eksplisit di dalam dialog. Hanya aktif saat dialog buka;
 * urutan kontrol di tengah tetap ditangani native browser.
 */
function handleTabKeydown(event: KeyboardEvent): void {
    const element = dialog.value;
    if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey || !element?.open) {
        return;
    }

    const controls = focusableControls(element);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (!first || !last) {
        return;
    }

    const active = document.activeElement;
    if (event.shiftKey) {
        if (active !== first && active !== element) {
            return;
        }

        event.preventDefault();
        last.focus();
        return;
    }

    if (active !== last && active !== element) {
        return;
    }

    event.preventDefault();
    first.focus();
}

function syncDialog(isOpen: boolean): void {
    const element = dialog.value;
    if (!element) {
        return;
    }

    if (isOpen && !element.open) {
        opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        element.showModal();
        // `[autofocus]` harus didahulukan karena querySelector gabungan memilih elemen pertama
        // dalam urutan DOM, sehingga tombol tutup di header selalu menang atas target autofocus.
        const preferredFocus = element.querySelector<HTMLElement>('[autofocus]');
        const fallbackFocus = element.querySelector<HTMLElement>('input, select, textarea, button');
        (preferredFocus ?? fallbackFocus)?.focus();
        return;
    }

    if (!isOpen && element.open) {
        element.close();
        if (opener) {
            opener.focus();
        }
        // Pemicu asli bisa tersembunyi (menu Aksi ditutup) atau terhapus (daftar
        // dirender ulang) selama dialog terbuka; focus() yang gagal membuat fokus
        // terdampar di BODY, jadi pindahkan ke fallback eksplisit pemanggil.
        if (document.activeElement !== opener) {
            props.fallbackFocus?.()?.focus();
        }
        opener = null;
    }
}

watch(() => props.modelValue, syncDialog, { flush: 'post' });
onBeforeUnmount(() => {
    if (dialog.value?.open) {
        dialog.value.close();
    }
});
</script>

<template>
    <dialog
        ref="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        class="m-auto max-h-[calc(100dvh-2rem)] w-[min(100%-2rem,36rem)] overflow-y-auto rounded-modal border border-prime-border bg-white p-0 text-prime-text shadow-level-3 backdrop:bg-slate-950/40"
        @cancel.prevent="close"
        @click.self="close"
        @keydown="handleTabKeydown"
    >
        <section class="p-5 sm:p-6">
            <header class="flex items-start justify-between gap-4 border-b border-prime-border pb-4">
                <h2 :id="titleId" class="text-xl font-semibold leading-tight tracking-normal" :class="destructive ? 'text-prime-danger' : 'text-prime-ink'">{{ title }}</h2>
                <button type="button" class="grid size-10 shrink-0 place-items-center rounded-micro text-prime-muted hover:bg-prime-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary" aria-label="Tutup dialog" @click="close">×</button>
            </header>
            <div class="pt-5">
                <slot />
            </div>
        </section>
    </dialog>
</template>
