import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminSettingsLayout from '@/layouts/admin/SettingsLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import UiKitLayout from '@/layouts/UiKitLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { t } from '@/lib/i18n';

const appName = () =>
    (usePage().props.name as string | undefined) ||
    import.meta.env.VITE_APP_NAME ||
    'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome' || name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/') || name.startsWith('onboarding/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('admin/settings/'):
                return [AppLayout, AdminSettingsLayout];
            case name.startsWith('ui-kit/'):
                return [AppLayout, UiKitLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.config.globalProperties.$t = t;

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
