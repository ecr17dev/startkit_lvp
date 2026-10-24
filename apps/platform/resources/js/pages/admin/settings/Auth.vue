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
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Label } from '@starter/ui/components/label';
import { RadioGroup, RadioGroupItem } from '@starter/ui/components/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import authSettings from '@/routes/admin/settings/auth';
import mail from '@/routes/admin/settings/mail';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: authSettings.edit() }],
    },
});

const props = defineProps<{
    settings: { registration: string; default_role: string | null };
    roles: string[];
    registrationModes: string[];
    environment: {
        method: string;
        passwords_enabled: boolean;
        otp_length: number;
        otp_ttl: number;
        otp_max_attempts: number;
        otp_resend_cooldown: number;
        invitation_ttl_days: number;
    };
    mailConfigured: boolean;
}>();

const { can } = usePermissions();

const form = useForm({
    registration: props.settings.registration,
    default_role: props.settings.default_role ?? props.roles[0] ?? 'reader',
});

const modes: Record<string, { label: string; description: string }> = {
    open: {
        label: 'Open',
        description:
            'Anyone can sign up with their email. Invitations also work.',
    },
    'invite-only': {
        label: 'Invite only',
        description:
            'Accounts are only created from invitations sent by an administrator.',
    },
    closed: {
        label: 'Closed',
        description:
            'No sign ups nor invitations. Administrators create the users.',
    },
};

const submit = () =>
    form.put(authSettings.update().url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Authentication')" />

    <form class="space-y-8" @submit.prevent="submit">
        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('Login method')"
                :description="
                    t(
                        'Defined by the deployment (STARTER_AUTH_METHOD); it cannot be changed from the panel.',
                    )
                "
            />

            <Alert v-if="!mailConfigured" variant="destructive">
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

            <dl class="grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">{{ t('Method') }}</dt>
                    <dd class="mt-1 flex flex-wrap gap-1">
                        <Badge>{{ t('Email code') }}</Badge>
                        <Badge variant="secondary">{{ t('Passkeys') }}</Badge>
                        <Badge
                            v-if="environment.passwords_enabled"
                            variant="outline"
                            >{{ t('Password') }}</Badge
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        {{ t('One-time codes') }}
                    </dt>
                    <dd class="mt-1">
                        {{
                            t(
                                ':digits digits · valid :minutes min · :attempts attempts · resend every :seconds s',
                                {
                                    digits: environment.otp_length,
                                    minutes: environment.otp_ttl,
                                    attempts: environment.otp_max_attempts,
                                    seconds: environment.otp_resend_cooldown,
                                },
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        {{ t('Invitations expire after') }}
                    </dt>
                    <dd class="mt-1">
                        {{
                            t(':count days', {
                                count: environment.invitation_ttl_days,
                            })
                        }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('Registration')"
                :description="t('Who can create an account')"
            />

            <RadioGroup v-model="form.registration" class="gap-2">
                <Label
                    v-for="mode in registrationModes"
                    :key="mode"
                    :for="`mode-${mode}`"
                    class="flex items-start gap-3 rounded-lg border p-4 font-normal has-data-[state=checked]:border-primary"
                >
                    <RadioGroupItem
                        :id="`mode-${mode}`"
                        :value="mode"
                        class="mt-0.5"
                    />
                    <span class="grid gap-1">
                        <span class="font-medium">{{
                            t(modes[mode]?.label ?? mode)
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t(modes[mode]?.description ?? '')
                        }}</span>
                    </span>
                </Label>
            </RadioGroup>
            <InputError :message="form.errors.registration" />

            <div class="grid max-w-sm gap-2">
                <Label for="default_role">{{
                    t('Role of new sign ups')
                }}</Label>
                <Select v-model="form.default_role">
                    <SelectTrigger id="default_role" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ role }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Privileged roles (owner, admin) are never given automatically.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.default_role" />
            </div>
        </div>

        <Button
            v-if="can('settings.update')"
            type="submit"
            :disabled="form.processing"
        >
            {{ t('Save') }}
        </Button>
    </form>
</template>
