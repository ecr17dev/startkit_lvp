<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Brain,
    KeyRound,
    MessageSquareText,
    Palette,
    ShieldCheck,
    UserCheck,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { chat } from '@/routes/ai';
import { index as providers } from '@/routes/admin/ai/providers';
import { index as roles } from '@/routes/admin/roles';
import branding from '@/routes/admin/settings/branding';
import { index as users } from '@/routes/admin/users';
import { index as apiTokens } from '@/routes/api-tokens';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Stats = {
    users?: { total: number; active: number; new_this_month: number };
    roles?: number;
    ai?: {
        month_cost: number;
        month_calls: number;
        providers: number;
        budget: number | string | null;
    };
    my_ai?: { month_cost: number; month_calls: number };
};

defineProps<{
    stats: Stats;
    lastLoginAt: string | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { can } = usePermissions();

const shortcuts = computed(() =>
    [
        {
            title: 'AI chat',
            description: 'Talk with the assistant',
            href: chat(),
            icon: MessageSquareText,
            permission: 'ai.chat',
        },
        {
            title: 'Users',
            description: 'Manage accounts and access',
            href: users(),
            icon: Users,
            permission: 'users.read',
        },
        {
            title: 'Roles & permissions',
            description: 'Define what each role can do',
            href: roles(),
            icon: ShieldCheck,
            permission: 'roles.read',
        },
        {
            title: 'Providers & models',
            description: 'API keys, models and prices',
            href: providers(),
            icon: Brain,
            permission: 'ai.providers.manage',
        },
        {
            title: 'Branding',
            description: 'Logo, colors and typography',
            href: branding.edit(),
            icon: Palette,
            permission: 'settings.update',
        },
        {
            title: 'API tokens',
            description: 'Connect other applications',
            href: apiTokens(),
            icon: KeyRound,
            permission: 'api.tokens.create',
        },
    ].filter((item) => can(item.permission)),
);
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ t('Welcome back, :name', { name: user.name }) }}
            </h1>
            <p v-if="lastLoginAt" class="text-sm text-muted-foreground">
                {{ t('Last login') }}: {{ formatDate(lastLoginAt) }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                v-if="stats.users"
                :title="t('Users')"
                :value="formatNumber(stats.users.total)"
                :hint="
                    t(':count new this month', {
                        count: stats.users.new_this_month,
                    })
                "
                :icon="Users"
            />
            <StatCard
                v-if="stats.users"
                :title="t('Active users')"
                :value="formatNumber(stats.users.active)"
                :hint="t(':count roles', { count: stats.roles ?? 0 })"
                :icon="UserCheck"
            />
            <StatCard
                v-if="stats.ai"
                :title="t('AI cost this month')"
                :value="formatMoney(stats.ai.month_cost)"
                :hint="
                    stats.ai.budget
                        ? t('Budget: :budget', {
                              budget: formatMoney(Number(stats.ai.budget)),
                          })
                        : t(':count calls', {
                              count: formatNumber(stats.ai.month_calls),
                          })
                "
                :icon="Wallet"
            />
            <StatCard
                v-if="stats.my_ai"
                :title="t('Your AI usage')"
                :value="formatNumber(stats.my_ai.month_calls)"
                :hint="
                    t('Messages this month · :cost', {
                        cost: formatMoney(stats.my_ai.month_cost),
                    })
                "
                :icon="MessageSquareText"
            />
        </div>

        <Card v-if="stats.ai && stats.ai.providers === 0">
            <CardHeader>
                <CardTitle>{{ t('Connect your first AI provider') }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'Add an API key of OpenAI, Anthropic, DeepSeek, OpenRouter, Amazon Bedrock or a custom endpoint.',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button as-child>
                    <Link :href="providers()">
                        {{ t('Add provider') }}
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </CardContent>
        </Card>

        <div
            v-if="shortcuts.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="item in shortcuts"
                :key="item.title"
                :href="item.href"
                class="group flex items-start gap-4 rounded-xl border p-5 transition-colors hover:bg-muted/50"
            >
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <component :is="item.icon" class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ t(item.title) }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ t(item.description) }}
                    </p>
                </div>
                <ArrowRight
                    class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                />
            </Link>
        </div>
    </div>
</template>
