import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { retireLegacyPushWorker } from './legacy-push-worker-cleanup';

retireLegacyPushWorker();

const pages = import.meta.glob<{ default: DefineComponent }>('./pages/**/*.vue');

void createInertiaApp({
    resolve: async (name) => {
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }

        return (await page()).default;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
