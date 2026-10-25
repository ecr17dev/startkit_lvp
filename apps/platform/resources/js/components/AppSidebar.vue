<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Blocks,
    BookOpenText,
    LayoutGrid,
    Library,
    Mail,
    MessageSquareText,
    Settings2,
    Sparkles,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@starter/ui/components/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { chat } from '@/routes/ai';
import { recommender, usage } from '@/routes/admin/ai';
import { index as budgets } from '@/routes/admin/ai/budgets';
import { index as connections } from '@/routes/admin/ai/connections';
import { index as models } from '@/routes/admin/ai/models';
import { index as prices } from '@/routes/admin/ai/prices';
import { index as agents } from '@/routes/admin/ai/agents';
import { index as labIndex } from '@/routes/admin/ai/lab';
import {
    index as knowledgeIndex,
    dashboard as knowledgeDashboard,
    lab as knowledgeLab,
} from '@/routes/admin/knowledge';
import { index as knowledgeConnections } from '@/routes/admin/knowledge/connections';
import { index as knowledgeEvaluations } from '@/routes/admin/knowledge/evaluations';
import { index as audit } from '@/routes/admin/audit';
import { index as mailAutomations } from '@/routes/admin/mail/automations';
import { index as mailDeliveries } from '@/routes/admin/mail/deliveries';
import { index as mailTemplates } from '@/routes/admin/mail/templates';
import { index as roles } from '@/routes/admin/roles';
import { index as sessions } from '@/routes/admin/sessions';
import integrations from '@/routes/admin/integrations';
import authSettings from '@/routes/admin/settings/auth';
import branding from '@/routes/admin/settings/branding';
import general from '@/routes/admin/settings/general';
import mailSettings from '@/routes/admin/settings/mail';
import { index as users } from '@/routes/admin/users';
import { ui as apiDocs } from '@/routes/scramble/docs';
import { index as uiKit } from '@/routes/ui-kit';
import type { ModuleNavItem, NavEntry, NavGroup, NavItem } from '@/types';

// Entries of the modules generated with starter:make-module...
const moduleItems = Object.values(
    import.meta.glob<{ default: ModuleNavItem }>('../modules/*/navigation.ts', {
        eager: true,
    }),
).map((module) => module.default);

const { can } = usePermissions();
const page = usePage();
const { currentUrl } = useCurrentUrl();

type NavSection = {
    label: string;
    items: NavEntry[];
};

type Permitted<T> = T & { can?: string | string[] };

const features = computed(
    () =>
        (page.props.features ?? {}) as {
            postgrest?: boolean;
            rag?: boolean;
            uiKit?: boolean;
        },
);

const sections = computed<NavSection[]>(() => {
    const filter = (items: Permitted<NavItem>[]) =>
        items.filter((item) => !item.can || can(item.can));

    // A group shows only the pages the user may open (none: no group)...
    const group = (
        id: string,
        title: string,
        icon: NavGroup['icon'],
        items: Permitted<NavItem>[],
    ): NavGroup[] => {
        const allowed = filter(items);

        return allowed.length ? [{ id, title, icon, items: allowed }] : [];
    };

    const url = currentUrl.value;

    return [
        {
            label: t('Platform'),
            items: filter([
                { title: t('Dashboard'), href: dashboard(), icon: LayoutGrid },
                {
                    title: t('AI chat'),
                    href: chat(),
                    icon: MessageSquareText,
                    can: 'ai.chat',
                },
                ...moduleItems.map((item) => ({
                    ...item,
                    title: t(item.title),
                })),
            ]),
        },
        {
            label: t('Administration'),
            items: [
                ...group('access', t('Users & access'), Users, [
                    { title: t('Users'), href: users(), can: 'users.read' },
                    {
                        title: t('Roles & permissions'),
                        href: roles(),
                        can: 'roles.read',
                    },
                    {
                        title: t('Sessions'),
                        href: sessions(),
                        can: 'users.read',
                    },
                    {
                        title: t('Audit trail'),
                        href: audit(),
                        can: 'audit.read',
                    },
                ]),
                ...group('mail', t('Mail'), Mail, [
                    {
                        title: t('Templates'),
                        href: mailTemplates(),
                        can: 'mail.read',
                    },
                    {
                        title: t('Automations'),
                        href: mailAutomations(),
                        can: 'mail.read',
                    },
                    {
                        title: t('Sends'),
                        href: mailDeliveries(),
                        can: 'mail.read',
                    },
                    {
                        title: t('Mail server'),
                        href: mailSettings.edit(),
                        can: 'settings.read',
                    },
                ]),
                ...group('settings', t('Settings'), Settings2, [
                    {
                        title: t('General'),
                        href: general.edit(),
                        can: 'settings.read',
                    },
                    {
                        title: t('Branding'),
                        href: branding.edit(),
                        can: 'settings.read',
                    },
                    {
                        title: t('Authentication'),
                        href: authSettings.edit(),
                        can: 'settings.read',
                    },
                    ...(features.value.postgrest
                        ? [
                              {
                                  title: 'PostgREST',
                                  href: integrations.postgrest(),
                                  can: 'settings.update',
                              },
                          ]
                        : []),
                ]),
                ...group('ai', t('Artificial intelligence'), Sparkles, [
                    {
                        title: t('Agents'),
                        href: agents(),
                        can: 'ai.agents.manage',
                    },
                    {
                        title: t('Usage & costs'),
                        href: usage(),
                        can: 'ai.usage.read',
                    },
                    {
                        title: t('Budgets'),
                        href: budgets(),
                        can: 'ai.budgets.manage',
                    },
                    {
                        title: t('Connections'),
                        href: connections(),
                        can: 'ai.providers.manage',
                    },
                    {
                        title: t('Model catalog'),
                        href: models(),
                        can: 'ai.models.manage',
                    },
                    {
                        title: t('Prices'),
                        href: prices(),
                        can: 'ai.models.manage',
                    },
                    {
                        title: t('Model recommender'),
                        href: recommender(),
                        can: 'ai.models.manage',
                    },
                    {
                        title: t('Lab'),
                        href: labIndex(),
                        can: 'ai.models.manage',
                    },
                ]),
                ...(features.value.rag
                    ? group('knowledge', t('Knowledge'), Library, [
                          {
                              title: t('Collections'),
                              href: knowledgeIndex(),
                              can: 'rag.collections.read',
                              // The other knowledge pages live under the same prefix...
                              isActive:
                                  url.startsWith('/admin/knowledge') &&
                                  ![
                                      '/admin/knowledge/lab',
                                      '/admin/knowledge/dashboard',
                                      '/admin/knowledge/evaluations',
                                      '/admin/knowledge/connections',
                                  ].some((prefix) => url.startsWith(prefix)),
                          },
                          {
                              title: t('Connections'),
                              href: knowledgeConnections(),
                              can: 'settings.update',
                          },
                          {
                              title: t('Dashboard'),
                              href: knowledgeDashboard(),
                              can: 'rag.analytics.read',
                          },
                          {
                              title: t('Retrieval lab'),
                              href: knowledgeLab(),
                              can: 'rag.playground.use',
                          },
                          {
                              title: t('Evaluations'),
                              href: knowledgeEvaluations(),
                              can: 'rag.playground.use',
                          },
                      ])
                    : []),
            ],
        },
        {
            label: t('Development'),
            items: features.value.uiKit
                ? [
                      {
                          title: t('UI kit'),
                          href: uiKit(),
                          icon: Blocks,
                          isActive: url.startsWith('/ui-kit'),
                      },
                  ]
                : [],
        },
    ].filter((section) => section.items.length > 0);
});

const footerNavItems = computed<NavItem[]>(() =>
    can('api.docs.read')
        ? [
              {
                  title: t('API documentation'),
                  href: apiDocs(),
                  icon: BookOpenText,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="section in sections"
                :key="section.label"
                :label="section.label"
                :items="section.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
