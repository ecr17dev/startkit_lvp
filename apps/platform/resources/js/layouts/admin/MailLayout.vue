<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText, ListChecks, Server, Workflow } from '@lucide/vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { index as automations } from '@/routes/admin/mail/automations';
import { index as deliveries } from '@/routes/admin/mail/deliveries';
import { index as templates } from '@/routes/admin/mail/templates';
import mailSettings from '@/routes/admin/settings/mail';
import type { NavItem } from '@/types';

const { can } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();

const items = computed<NavItem[]>(() =>
    [
        {
            title: t('Templates'),
            href: templates(),
            icon: FileText,
            permission: 'mail.read',
        },
        {
            title: t('Automations'),
            href: automations(),
            icon: Workflow,
            permission: 'mail.read',
        },
        {
            title: t('Sends'),
            href: deliveries(),
            icon: ListChecks,
            permission: 'mail.read',
        },
        {
            title: t('Mail server'),
            href: mailSettings.edit(),
            icon: Server,
            permission: 'settings.read',
        },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <nav
            class="-mx-1 flex gap-1 overflow-x-auto border-b px-1"
            :aria-label="t('Mail')"
        >
            <Link
                v-for="item in items"
                :key="toUrl(item.href)"
                :href="item.href"
                :aria-current="
                    isCurrentOrParentUrl(item.href) ? 'page' : undefined
                "
                :class="
                    cn(
                        '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                        isCurrentOrParentUrl(item.href)
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )
                "
            >
                <component :is="item.icon" class="size-4" />
                {{ item.title }}
            </Link>
        </nav>

        <slot />
    </div>
</template>
