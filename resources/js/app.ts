import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, type DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AuthLayout from '@/layouts/AuthLayout.vue';
import KostLayout from '@/layouts/KostLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

const hideAppShell = () => {
    const shell = document.querySelector<HTMLElement>('[data-app-shell]');

    if (!shell) {
        return;
    }

    requestAnimationFrame(() => {
        shell.classList.add('is-hidden');

        window.setTimeout(() => {
            shell.remove();
        }, 220);
    });
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent(`./pages/${name}.vue`, pages);
        const component = page.default;

        switch (true) {
            case name === 'Welcome':
                component.layout = null;
                break;
            case name.startsWith('auth/'):
                component.layout = AuthLayout;
                break;
            case name.startsWith('settings/'):
                component.layout = [KostLayout, SettingsLayout];
                break;
            default:
                component.layout = KostLayout;
                break;
        }

        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);

        hideAppShell();
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
