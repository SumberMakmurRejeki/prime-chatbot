<script setup lang="ts">
withDefaults(defineProps<{
    title: string;
    description?: string;
    eyebrow?: string;
}>(), {
    description: undefined,
    eyebrow: undefined,
});
</script>

<template>
    <header class="space-y-2">
        <p v-if="eyebrow" class="text-xs font-semibold uppercase tracking-[0.16em] text-prime-muted">{{ eyebrow }}</p>
        <h1 class="text-2xl font-bold tracking-heading text-prime-ink sm:text-3xl">{{ title }}</h1>
        <p v-if="description" class="max-w-2xl text-sm leading-6 text-prime-muted sm:text-base">{{ description }}</p>
    </header>
</template>
