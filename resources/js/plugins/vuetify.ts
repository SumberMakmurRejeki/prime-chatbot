import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import { aliases, mdi } from 'vuetify/iconsets/mdi';
import { createVuetify } from 'vuetify';

export default createVuetify({
    theme: {
        defaultTheme: 'primeLight',
        themes: {
            primeLight: {
                dark: false,
                colors: {
                    primary: '#0075de',
                    background: '#f6f5f4',
                    surface: '#ffffff',
                    error: '#d53445',
                    success: '#167c49',
                    warning: '#a05a00',
                    'on-primary': '#ffffff',
                    'on-background': '#171717',
                    'on-surface': '#171717',
                    'on-error': '#ffffff',
                    'on-success': '#ffffff',
                    'on-warning': '#ffffff',
                },
            },
        },
    },
    icons: {
        defaultSet: 'mdi',
        aliases,
        sets: { mdi },
    },
});
