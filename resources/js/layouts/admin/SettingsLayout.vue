<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { KeyRound, Mail, Palette, SlidersHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import authSettings from '@/routes/admin/settings/auth';
import branding from '@/routes/admin/settings/branding';
import general from '@/routes/admin/settings/general';
import mail from '@/routes/admin/settings/mail';
import type { NavItem } from '@/types';

const { can } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();

const items = computed<NavItem[]>(() =>
    [
        {
            title: t('General'),
            href: general.edit(),
            icon: SlidersHorizontal,
            permission: 'settings.general',
        },
        {
            title: t('Branding'),
            href: branding.edit(),
            icon: Palette,
            permission: 'settings.branding',
        },
        {
            title: t('Mail server'),
            href: mail.edit(),
            icon: Mail,
            permission: 'settings.mail',
        },
        {
            title: t('Authentication'),
            href: authSettings.edit(),
            icon: KeyRound,
            permission: 'settings.auth',
        },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            :title="t('System settings')"
            :description="
                t('Configure the application for every user of the platform')
            "
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-52">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="t('System settings')"
                >
                    <Button
                        v-for="item in items"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-3xl">
                <section class="max-w-2xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
