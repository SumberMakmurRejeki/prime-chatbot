<script setup lang="ts">
import { ref, watch } from 'vue';
import type { ChartConfiguration, Tick } from 'chart.js';
import { usePrimeChart, prefersReducedMotion } from '../../composables/usePrimeChart';
import type { BreakdownDurationPoint } from '../../types';

const props = defineProps<{ breakdown: BreakdownDurationPoint[] }>();
const breakdownCanvas = ref<HTMLCanvasElement | null>(null);

const MAX_AXIS_LABEL_LINES = 2;
// Perkiraan lebar karakter label 12px (Chart.defaults.font.size) agar jumlah
// karakter per baris dapat diturunkan dari lebar kategori pada kanvas.
const AXIS_LABEL_CHAR_WIDTH_PX = 6.2;

/**
 * Anggaran karakter per baris label mengikuti lebar nyata tiap kategori pada
 * kanvas, sehingga pembungkusan menyesuaikan kontainer tanpa listener resize.
 */
function axisLabelCharsPerLine(chartWidth: number, tickCount: number): number {
    const perCategoryPx = chartWidth / Math.max(tickCount, 1);
    return Math.max(8, Math.floor((perCategoryPx * 0.9) / AXIS_LABEL_CHAR_WIDTH_PX));
}

/**
 * Membungkus label sumbu-x pada batas kata dan memotongnya dengan elipsis bila
 * melebihi dua baris, supaya kategori panjang tidak saling menimpa di kontainer
 * sempit. Label penuh tetap dipakai pada tooltip dan ringkasan aksesibel.
 */
function wrapAxisLabel(label: string, maxCharsPerLine: number, maxLines: number = MAX_AXIS_LABEL_LINES): string[] {
    const lines: string[] = [];
    let current = '';
    let truncated = false;

    for (const word of label.split(/\s+/)) {
        const candidate = current ? `${current} ${word}` : word;

        if (candidate.length <= maxCharsPerLine || current === '') {
            current = candidate;
            continue;
        }

        if (lines.length === maxLines - 1) {
            truncated = true;
            break;
        }

        lines.push(current);
        current = word;
    }

    if (current) {
        lines.push(truncated ? `${current}…` : current);
    }

    return lines;
}

const { render } = usePrimeChart(breakdownCanvas, (): ChartConfiguration<'bar'> => ({
    type: 'bar',
    data: {
        labels: props.breakdown.map((point) => point.part_name),
        datasets: [{ label: 'Durasi (Jam)', data: props.breakdown.map((point) => point.total_duration_hours), backgroundColor: '#1267c5', maxBarThickness: 42, categoryPercentage: 0.62, barPercentage: 0.72 }],
    },
    options: {
        maintainAspectRatio: false,
        animation: prefersReducedMotion() ? false : { duration: 350 },
        scales: {
            x: {
                grid: { display: false },
                ticks: {
                    autoSkip: false,
                    color: '#5b6673',
                    maxRotation: 0,
                    minRotation: 0,
                    callback(tickValue: string | number, _index: number, ticks: Tick[]) {
                        const partName = props.breakdown[Number(tickValue)]?.part_name ?? String(tickValue);
                        return wrapAxisLabel(partName, axisLabelCharsPerLine(this.chart.width, ticks.length));
                    },
                },
            },
            y: { beginAtZero: true, ticks: { color: '#5b6673' }, title: { display: true, text: 'Jam', color: '#5b6673' }, grid: { color: 'rgba(15, 23, 42, 0.08)' } },
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title(items) {
                        const maxCharsPerLine = Math.max(8, Math.floor((this.chart.width - 10) / AXIS_LABEL_CHAR_WIDTH_PX));
                        return items.flatMap((item) => {
                            const partName = props.breakdown[item.dataIndex]?.part_name;
                            return partName ? wrapAxisLabel(partName, maxCharsPerLine, Number.POSITIVE_INFINITY) : [];
                        });
                    },
                    label(context) {
                        const point = props.breakdown[context.dataIndex];
                        return [`Total Jam: ${point?.total_duration_hours ?? 0}`, `Jumlah Breakdown: ${point?.total_breakdown_count ?? 0}`];
                    },
                },
            },
        },
    },
}));

const breakdownSummary = () => props.breakdown.length
    ? `Durasi breakdown: ${props.breakdown.map((point) => `${point.part_name}, ${point.total_duration_hours} jam dari ${point.total_breakdown_count} kejadian`).join('; ')}.`
    : 'Belum ada data breakdown pada periode yang dipilih.';

watch(() => props.breakdown, () => render());
</script>

<template>
    <article class="rounded-lg border border-prime-border bg-white p-5">
        <h2 class="text-[0.9375rem] font-semibold leading-snug tracking-normal text-prime-text">Breakdown Duration</h2>
        <p class="mt-1 text-xs text-prime-muted">Durasi downtime rata-rata per part, dalam jam</p>
        <div v-if="breakdown.length === 0" class="flex min-h-[10rem] flex-col items-center justify-center px-2 text-center">
            <p class="text-[0.9375rem] font-semibold tracking-normal text-prime-text">Belum ada data breakdown</p>
            <p class="mt-1 max-w-xs text-xs leading-5 text-prime-muted">Tidak ada breakdown yang ditutup pada periode yang dipilih.</p>
        </div>
        <div v-else class="mt-5 h-64 sm:h-72" role="img" :aria-label="breakdownSummary()"><canvas ref="breakdownCanvas" aria-hidden="true" /></div>
        <p class="sr-only">{{ breakdownSummary() }}</p>
    </article>
</template>
