<script setup lang="ts">
import { ref, watch } from 'vue';
import type { ChartConfiguration } from 'chart.js';
import { usePrimeChart, prefersReducedMotion } from '../../composables/usePrimeChart';
import type { PmRatePoint } from '../../types';

const props = defineProps<{ series: PmRatePoint[] }>();
const canvas = ref<HTMLCanvasElement | null>(null);

const { render } = usePrimeChart(canvas, (): ChartConfiguration<'bar' | 'line'> => ({

    type: 'bar',
    data: {
        labels: props.series.map((point) => point.month_label),
        datasets: [
            {
                type: 'bar',
                label: 'PM Aktual (%)',
                data: props.series.map((point) => point.actual_percentage),
                backgroundColor: '#1267c5',
                borderRadius: 1,
                maxBarThickness: 42,
                categoryPercentage: 0.62,
                barPercentage: 0.72,
            },
            {
                type: 'line',
                label: 'Target (%)',
                data: props.series.map((point) => point.target_percentage),
                borderColor: '#1267c5',
                backgroundColor: '#1267c5',
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#1267c5',
                pointBorderWidth: 1.5,
                pointRadius: 4,
                borderWidth: 2,
                borderDash: [6, 5],
                tension: 0,
            },
        ],
    },
    options: {
        maintainAspectRatio: false,
        animation: prefersReducedMotion() ? false : { duration: 350 },
        layout: { padding: { top: 24 } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#5b6673' } },
            y: {
                min: 0,
                max: 105,
                ticks: { stepSize: 20, color: '#5b6673', callback: (value) => Number(value) > 100 ? '' : `${value}%` },
                grid: { color: 'rgba(15, 23, 42, 0.08)' },
            },
        },
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${context.parsed.y}%` } },
        },
    },
}));

// Data filter Inertia memperbarui chart yang sudah ada, bukan membuat canvas kedua.
watch(() => props.series, () => render());
</script>

<template>
    <div class="h-72 w-full sm:h-80" role="img" aria-label="Grafik pencapaian PM aktual dan target per bulan">
        <canvas ref="canvas" aria-hidden="true" />
    </div>
</template>
