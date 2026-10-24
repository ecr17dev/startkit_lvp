<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Bot, Code2, KeyRound, Mail, Palette, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const branding = computed(() => page.props.branding);

const features = [
    {
        icon: KeyRound,
        title: 'Passwordless login',
        description:
            'Log in with a code sent by email, a passkey or a password with 2FA.',
    },
    {
        icon: ShieldCheck,
        title: 'Roles & permissions',
        description: 'Fine grained access control for every module.',
    },
    {
        icon: Bot,
        title: 'AI ready',
        description:
            'OpenAI, Anthropic, DeepSeek, OpenRouter, Bedrock or custom, with prompts and cost tracking.',
    },
    {
        icon: Palette,
        title: 'Your brand',
        description:
            'Logo, colors, typography and login layout from the admin panel.',
    },
    {
        icon: Mail,
        title: 'Mail server',
        description: 'Configure SMTP from the browser and send test emails.',
    },
    {
        icon: Code2,
        title: 'REST API',
        description:
            'Sanctum tokens and OpenAPI documentation generated automatically.',
    },
];
</script>

<template>
    <Head :title="t('Welcome')" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 p-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <BrandMark size="size-8" />
                <span>{{ branding.appName }}</span>
            </div>
            <nav class="flex items-center gap-2">
                <LanguageSwitcher />
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">{{ t('Dashboard') }}</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">{{ t('Log in') }}</Link>
                    </Button>
                    <Button v-if="$page.props.features.registration" as-child>
                        <Link :href="register()">{{ t('Register') }}</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center gap-16 px-6 py-12"
        >
            <section class="max-w-3xl space-y-6">
                <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                    {{
                        branding.tagline || t('Build your next project faster')
                    }}
                </h1>
                <p class="text-lg text-muted-foreground">
                    {{
                        t(
                            'A Laravel + Vue starter kit with authentication, roles, branding, mail and artificial intelligence ready to use.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button size="lg" as-child>
                        <Link
                            :href="
                                $page.props.auth.user ? dashboard() : login()
                            "
                        >
                            {{
                                $page.props.auth.user
                                    ? t('Go to dashboard')
                                    : t('Get started')
                            }}
                        </Link>
                    </Button>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border p-5"
                >
                    <component
                        :is="feature.icon"
                        class="mb-3 size-5 text-primary"
                    />
                    <h2 class="font-medium">{{ t(feature.title) }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t(feature.description) }}
                    </p>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto w-full max-w-6xl p-6 text-sm text-muted-foreground"
        >
            © {{ new Date().getFullYear() }} {{ branding.appName }}
        </footer>
    </div>
</template>
