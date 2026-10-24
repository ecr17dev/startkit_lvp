<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import SessionList from '@/components/SessionList.vue';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';
import { destroy, destroyOthers, index } from '@/routes/sessions';
import type { BrowserSession } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sessions', href: index() }],
    },
});

defineProps<{
    sessions: BrowserSession[];
    tokens: number;
}>();

const revoke = (session: BrowserSession) =>
    router.delete(destroy(session.id).url, { preserveScroll: true });

const revokeOthers = () =>
    router.delete(destroyOthers().url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Sessions')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Browser sessions')"
            :description="
                t(
                    'Devices where your account is logged in. Revoke any session you do not recognize.',
                )
            "
        />

        <SessionList :sessions="sessions" can-revoke @revoke="revoke" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted-foreground">
                {{ t('Active API tokens: :count', { count: tokens }) }}
            </p>
            <ConfirmDialog
                v-if="sessions.length > 1"
                :title="t('Log out other sessions')"
                :description="
                    t('Every other device will be logged out immediately.')
                "
                :confirm-text="t('Log out other sessions')"
                @confirm="revokeOthers"
            >
                <Button variant="outline" data-test="logout-other-sessions">{{
                    t('Log out other sessions')
                }}</Button>
            </ConfirmDialog>
        </div>
    </div>
</template>
