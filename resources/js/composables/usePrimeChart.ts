import Chart from 'chart.js/auto';
import type { ChartConfiguration, ChartType } from 'chart.js';
import { onBeforeUnmount, onMounted, shallowRef, watch, type Ref, type ShallowRef } from 'vue';
let defaultsApplied = false;

function applyChartDefaults(): void {
    if (defaultsApplied) return;

    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    defaultsApplied = true;
}

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Siklus hidup Chart.js untuk Vue: satu instance per canvas, dataset baru
 * menggantikan data lama tanpa membuat instance kedua, dan chart dihancurkan
 * saat komponen dilepas agar tidak ada kebocoran canvas/render loop.
 */
export function usePrimeChart<TChart extends ChartType>(
    canvasRef: Ref<HTMLCanvasElement | null>,
    buildConfiguration: () => ChartConfiguration<TChart>,
): { chartInstance: ShallowRef<Chart<TChart> | undefined>; render: () => void } {
    applyChartDefaults();

    const chartInstance = shallowRef<Chart<TChart> | undefined>(undefined);
    let chartCanvas: HTMLCanvasElement | null = null;
    let renderedCanvas: HTMLCanvasElement | null = null;

    function render(): void {
        const canvas = canvasRef.value;

        if (! canvas) {
            return;
        }

        const configuration = buildConfiguration();

        if (chartInstance.value && chartCanvas === canvas) {
            chartInstance.value.data = configuration.data;
            chartInstance.value.update();
            return;
        }

        if (renderedCanvas === canvas) {
            return;
        }

        chartInstance.value?.destroy();
        renderedCanvas = canvas;

        try {
            chartInstance.value = new Chart(canvas, configuration);
            chartCanvas = canvas;
        } catch (error) {
            Chart.getChart(canvas)?.destroy();
            renderedCanvas = null;
            throw error;
        }
    }

    onMounted(() => render());

    watch(canvasRef, () => render());

    onBeforeUnmount(() => {
        chartInstance.value?.destroy();
        chartInstance.value = undefined;
        chartCanvas = null;
        renderedCanvas = null;
    });

    return { chartInstance, render };
}
