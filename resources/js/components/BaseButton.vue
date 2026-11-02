<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    /** Bila diisi, komponen dirender sebagai anchor dokumen penuh (navigasi Blade aman). */
    href?: string;
    variant?: 'primary' | 'secondary';
}>(), {
    href: undefined,
    variant: 'primary',
});

const emit = defineEmits<{ click: [] }>();

const tag = computed(() => (props.href === undefined ? 'button' : 'a'));

const classes = computed(() => [
    'inline-flex min-h-11 items-center justify-center rounded-micro px-4 py-2 text-sm font-semibold transition-colors',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-prime-primary',
    props.variant === 'primary'
        ? 'bg-prime-primary text-white hover:bg-prime-primary-active'
        : 'border border-prime-border bg-white text-prime-ink hover:border-prime-primary hover:text-prime-primary',
]);
</script>

<template>
    <component :is="tag" :href="props.href" :type="tag === 'button' ? 'button' : undefined" :class="classes" @click="emit('click')">
        <slot />
    </component>
</template>
