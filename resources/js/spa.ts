import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { retireLegacyPushWorker } from './legacy-push-worker-cleanup';
import { VApp } from 'vuetify/components';
import vuetify from './plugins/vuetify';

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
        createApp({
            render: () => h(VApp, { class: 'prime-app' }, () => h(App, props)),
        })
            .use(plugin)
            .use(vuetify)
            .mount(el);
    },
});
