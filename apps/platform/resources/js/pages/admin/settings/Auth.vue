<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import { t } from '@/lib/i18n';
import authSettings from '@/routes/admin/settings/auth';
import mail from '@/routes/admin/settings/mail';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: authSettings.edit() }],
    },
});

type AuthSettings = {
    password_login: boolean;
    code_login: boolean;
    registration: boolean;
    default_role: string | null;
    code_length: number;
    code_ttl: number;
    code_max_attempts: number;
};

const props = defineProps<{
    settings: AuthSettings;
    roles: string[];
    mailConfigured: boolean;
}>();

const form = useForm({
    password_login: !!props.settings.password_login,
    code_login: !!props.settings.code_login,
    registration: !!props.settings.registration,
    default_role: props.settings.default_role ?? 'none',
    code_length: Number(props.settings.code_length),
    code_ttl: Number(props.settings.code_ttl),
    code_max_attempts: Number(props.settings.code_max_attempts),
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            default_role:
                data.default_role === 'none' ? null : data.default_role,
        }))
        .put(authSettings.update().url, { preserveScroll: true });

const toggles = [
    {
        key: 'code_login',
        label: 'Login with email code',
        description:
            'Users receive a one-time code by email and log in without a password.',
    },
    {
        key: 'password_login',
        label: 'Login with password',
        description:
            'Classic email and password login, including password resets.',
    },
    {
        key: 'registration',
        label: 'Public registration',
        description: 'Visitors can create their own account.',
    },
] as const;
</script>

<template>
    <Head :title="t('Authentication')" />

    <form class="space-y-8" @submit.prevent="submit">
        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('Authentication')"
                :description="t('Choose how users log in and register')"
            />

            <Alert
                v-if="form.code_login && !mailConfigured"
                variant="destructive"
            >
                <TriangleAlert class="size-4" />
                <AlertTitle>{{ t('Mail is not configured') }}</AlertTitle>
                <AlertDescription>
                    <p>
                        {{
                            t(
                                'Login codes are sent by email. Configure a mail server so users can receive them.',
                            )
                        }}
                        <Link :href="mail.edit()" class="underline">{{
                            t('Configure mail')
                        }}</Link>
                    </p>
                </AlertDescription>
            </Alert>

            <div class="divide-y rounded-lg border">
                <div
                    v-for="toggle in toggles"
                    :key="toggle.key"
                    class="flex items-center justify-between gap-4 p-4"
                >
                    <div>
                        <Label :for="toggle.key">{{ t(toggle.label) }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{ t(toggle.description) }}
                        </p>
                    </div>
                    <Switch :id="toggle.key" v-model="form[toggle.key]" />
                </div>
            </div>
            <InputError :message="form.errors.code_login" />
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('Login codes')"
                :description="t('Security of the codes sent by email')"
            />
            <div class="grid gap-6 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="code_length">{{ t('Digits') }}</Label>
                    <Input
                        id="code_length"
                        v-model="form.code_length"
                        type="number"
                        min="4"
                        max="10"
                    />
                    <InputError :message="form.errors.code_length" />
                </div>
                <div class="grid gap-2">
                    <Label for="code_ttl">{{ t('Valid for (minutes)') }}</Label>
                    <Input
                        id="code_ttl"
                        v-model="form.code_ttl"
                        type="number"
                        min="1"
                        max="60"
                    />
                    <InputError :message="form.errors.code_ttl" />
                </div>
                <div class="grid gap-2">
                    <Label for="code_max_attempts">{{
                        t('Max attempts')
                    }}</Label>
                    <Input
                        id="code_max_attempts"
                        v-model="form.code_max_attempts"
                        type="number"
                        min="1"
                        max="10"
                    />
                    <InputError :message="form.errors.code_max_attempts" />
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('New users')"
                :description="
                    t('Role assigned automatically to users who register')
                "
            />
            <div class="grid gap-2 sm:w-64">
                <Select v-model="form.default_role">
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">{{ t('No role') }}</SelectItem>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ role }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.default_role" />
            </div>
        </div>

        <Button type="submit" :disabled="form.processing">{{
            t('Save')
        }}</Button>
    </form>
</template>
