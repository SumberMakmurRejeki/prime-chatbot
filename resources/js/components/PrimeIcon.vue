<script setup lang="ts">
const props = defineProps<{
    name: string;
    class?: string | Record<string, boolean>;
}>();

const paths: Record<string, string[]> = {
    dashboard: ['<rect x="3" y="3" width="7" height="7" rx="1.5"/>', '<rect x="14" y="3" width="7" height="4" rx="1.5"/>', '<rect x="14" y="10" width="7" height="11" rx="1.5"/>', '<rect x="3" y="14" width="7" height="7" rx="1.5"/>'],
    menu: ['<path d="M4 6h16M4 12h16M4 18h16"/>'],
    close: ['<path d="m6 6 12 12M18 6 6 18"/>'],
    chevron: ['<path d="m6 9 6 6 6-6"/>'],
    bell: ['<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>', '<path d="M10 21h4"/>'],
    machine: ['<rect x="3" y="4" width="18" height="6" rx="1.5"/>', '<rect x="3" y="14" width="18" height="6" rx="1.5"/>', '<path d="M7 7h.01M7 17h.01M11 7h6M11 17h6"/>'],
    clock: ['<circle cx="12" cy="12" r="9"/>', '<path d="M12 7v5l3 3"/>'],
    wrench: ['<path d="M14 6a5 5 0 0 0 4 7l-8 8a2 2 0 1 1-3-3l8-8a5 5 0 0 0-4-7l3 3-3 3-3-3a5 5 0 0 0 6 0"/>'],
    chart: ['<path d="M3 3v18h18"/>', '<path d="m7 14 4-4 4 3 5-7"/>'],
    shield: ['<path d="M12 3 5 6v5c0 5 3.5 8.7 7 10 3.5-1.3 7-5 7-10V6l-7-3Z"/>', '<path d="m9.5 12 1.7 1.7 3.3-3.7"/>'],
    pin: ['<path d="M12 21s6-4.8 6-11a6 6 0 1 0-12 0c0 6.2 6 11 6 11Z"/>', '<circle cx="12" cy="10" r="2.4"/>'],
    clipboard: ['<rect x="5" y="4" width="14" height="17" rx="2"/>', '<path d="M9 4.5h6v3H9zM9 13l2 2 4-4"/>'],
    alert: ['<path d="M12 4 3.5 19h17L12 4Z"/>', '<path d="M12 9v4"/>', '<circle cx="12" cy="16.5" r=".6" fill="currentColor" stroke="none"/>'],
    tools: ['<path d="m4 20 7-7m3-3 6-6m-7 7 1 1M3 7l4-4 6 6-4 4zM11 13l6 6m0 0 3-3"/>'],
    user: ['<circle cx="12" cy="8" r="4"/>', '<path d="M4 21a8 8 0 0 1 16 0"/>'],
    report: ['<path d="M4 19V5M10 19V9M16 19v-6M22 19V3"/>'],
    support: ['<circle cx="12" cy="12" r="9"/>', '<path d="M9.5 9a2.5 2.5 0 1 1 4.4 1.6c-1.1 1.2-1.9 1.4-1.9 3.4M12 17.5h.01"/>'],
    cpu: ['<rect x="6" y="6" width="12" height="12" rx="2"/>', '<path d="M9 9h6v6H9zM9 2v4m6-4v4M9 18v4m6-4v4M2 9h4m-4 6h4m12-6h4m-4 6h4"/>'],
    document: ['<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>', '<path d="M14 3v6h6M8 13h8m-8 4h8"/>'],
    calendar: ['<rect x="3" y="5" width="18" height="16" rx="2"/>', '<path d="M8 2v6m8-6v6M3 10h18"/>'],
    filter: ['<path d="M4 6h16M7 12h10m-7 6h4"/>'],
    logout: ['<path d="M10 17l5-5-5-5m5 5H3"/>', '<path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>'],
    chevronRight: ['<path d="m9 18 6-6-6-6"/>'],
    search: ['<circle cx="11" cy="11" r="7"/>', '<path d="m20 20-3.5-3.5"/>'],
    download: ['<path d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5"/>', '<path d="M5 20h14"/>'],
    power: ['<path d="M12 3v8"/>', '<path d="M7.8 5.5a8 8 0 1 0 8.4 0"/>'],
    trash: ['<path d="M4 7h16"/>', '<path d="M10 11v6m4-6v6"/>', '<path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>', '<path d="M9 7V4h6v3"/>'],
};
</script>

<template>
    <svg
        :class="props.class"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        v-html="paths[props.name] ?? []"
    />
</template>
