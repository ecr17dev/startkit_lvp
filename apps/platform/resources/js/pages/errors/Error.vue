<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Clock,
    FileQuestion,
    ShieldX,
    TimerReset,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';

/**
 * Error page rendered by the exception handler (bootstrap/app.php) for
 * access denied, not found, expired session, throttling and server errors.
 */
const props = defineProps<{ status: number }>();

const page = usePage();
const appName = computed(() => (page.props.name as string | undefined) ?? '');
const authenticated = computed(() => Boolean(page.props.auth?.user));

const content = computed(() => {
    switch (props.status) {
        case 403:
            return {
                icon: ShieldX,
                title: t('Access denied'),
                description: t(
                    'You do not have permission to access this page. If you think this is a mistake, contact an administrator.',
                ),
            };
        case 404:
            return {
                icon: FileQuestion,
                title: t('Page not found'),
                description: t(
                    'The page you are looking for does not exist or was moved.',
                ),
            };
        case 419:
            return {
                icon: TimerReset,
                title: t('Your session expired'),
                description: t(
                    'For your security the page expired. Reload it and try again.',
                ),
            };
        case 429:
            return {
                icon: Clock,
                title: t('Too many requests'),
                description: t('Please wait a moment before trying again.'),
            };
        case 503:
            return {
                icon: TriangleAlert,
                title: t('Under maintenance'),
                description: t('We will be back shortly.'),
            };
        default:
            return {
                icon: TriangleAlert,
                title: t('Something went wrong'),
                description: t(
                    'An unexpected error occurred. The team has been notified.',
                ),
            };
    }
});

const reload = () => window.location.reload();
</script>

<template>
    <Head :title="content.title" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 text-center"
        :data-test="`error-${status}`"
    >
        <component
            :is="content.icon"
            class="size-12 text-muted-foreground"
            aria-hidden="true"
        />
        <div class="max-w-md space-y-2">
            <p class="text-sm font-medium text-muted-foreground">
                {{ status }}
            </p>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ content.title }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ content.description }}
            </p>
        </div>
        <div class="flex flex-wrap justify-center gap-2">
            <Button v-if="status === 419" @click="reload">{{
                t('Reload page')
            }}</Button>
            <Button :variant="status === 419 ? 'outline' : 'default'" as-child>
                <Link :href="authenticated ? '/dashboard' : '/login'">{{
                    authenticated ? t('Go to dashboard') : t('Go to log in')
                }}</Link>
            </Button>
        </div>
        <p v-if="appName" class="text-xs text-muted-foreground">
            {{ appName }}
        </p>
    </main>
</template>
