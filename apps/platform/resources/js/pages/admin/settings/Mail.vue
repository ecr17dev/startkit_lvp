<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    FileCog,
    Mail as MailIcon,
    Mailbox,
    ScrollText,
    Send,
    Server,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { FormField, StatusAlert, SubmitButton } from '@/components/kit';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Checkbox } from '@starter/ui/components/checkbox';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { RadioGroup, RadioGroupItem } from '@starter/ui/components/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { t } from '@/lib/i18n';
import mail from '@/routes/admin/settings/mail';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: mail.edit() }],
    },
});

type Mailer = 'env' | 'resend' | 'smtp' | 'postmark' | 'mailgun' | 'log';
type SecretMailer = 'resend' | 'smtp' | 'postmark' | 'mailgun';

type MailSettings = {
    mailer: Mailer;
    host: string | null;
    port: number | null;
    username: string | null;
    scheme: 'smtp' | 'smtps' | null;
    mailgun_domain: string | null;
    mailgun_region: 'us' | 'eu' | null;
    from_address: string;
    from_name: string;
    last_test?: {
        at: string;
        ok: boolean;
        mailer: string;
        to: string;
        error: string | null;
    } | null;
};

type Credential = {
    source: 'stored' | 'reference' | 'environment' | 'missing';
    hint: string | null;
};

const props = defineProps<{
    settings: MailSettings;
    credentials: Record<SecretMailer, Credential>;
    envMailer: string | null;
}>();

const page = usePage();

const form = useForm({
    mailer: props.settings.mailer,
    host: props.settings.host ?? '',
    port: props.settings.port ?? 587,
    username: props.settings.username ?? '',
    password: '',
    scheme: props.settings.scheme ?? 'auto',
    resend_key: '',
    postmark_token: '',
    mailgun_secret: '',
    mailgun_domain: props.settings.mailgun_domain ?? '',
    mailgun_region: props.settings.mailgun_region ?? 'us',
    remove: [] as string[],
    from_address: props.settings.from_address,
    from_name: props.settings.from_name,
});

const testForm = useForm({ email: page.props.auth.user.email });

const submit = () => {
    form.transform((data) => ({
        ...data,
        scheme: data.scheme === 'auto' ? null : data.scheme,
    })).put(mail.update().url, {
        preserveScroll: true,
        onSuccess: () =>
            form.reset(
                'password',
                'resend_key',
                'postmark_token',
                'mailgun_secret',
                'remove',
            ),
    });
};

/** The test uses the values of the form, saved or not. */
const sendTest = () =>
    testForm
        .transform((data) => ({
            email: data.email,
            settings: {
                ...form.data(),
                scheme: form.scheme === 'auto' ? null : form.scheme,
            },
        }))
        .post(mail.test().url, { preserveScroll: true });

const testErrors = computed(() =>
    Object.entries(testForm.errors)
        .filter(([field]) => field !== 'email')
        .map(([, message]) => message),
);

const lastTest = computed(() => props.settings.last_test ?? null);

const mailers: {
    value: Mailer;
    label: string;
    description: string;
    icon: Component;
    recommended?: boolean;
}[] = [
    {
        value: 'resend',
        label: 'Resend (API)',
        description: 'An API key and a verified domain. Fast to set up.',
        icon: Send,
        recommended: true,
    },
    {
        value: 'smtp',
        label: 'SMTP server',
        description:
            'Your own server or any provider: Gmail, Amazon SES, Brevo, SendGrid, Zoho…',
        icon: Server,
    },
    {
        value: 'postmark',
        label: 'Postmark (API)',
        description: 'The server API token.',
        icon: Mailbox,
    },
    {
        value: 'mailgun',
        label: 'Mailgun (API)',
        description: 'Private API key, sending domain and region.',
        icon: MailIcon,
    },
    {
        value: 'env',
        label: 'Use the .env configuration',
        description: 'Keeps the MAIL_* variables of the environment file.',
        icon: FileCog,
    },
    {
        value: 'log',
        label: 'Log only (development)',
        description:
            'Emails are written to storage/logs instead of being sent.',
        icon: ScrollText,
    },
];

const secretFields = {
    resend: 'resend_key',
    smtp: 'password',
    postmark: 'postmark_token',
    mailgun: 'mailgun_secret',
} as const;

/** Help text of a secret: where the current value comes from. */
const credentialHelp = (mailer: SecretMailer): string => {
    const credential = props.credentials[mailer];

    return {
        stored: t('Saved (:hint). Leave blank to keep it.', {
            hint: credential.hint ?? '',
        }),
        reference: t('Read from :hint.', { hint: credential.hint ?? '' }),
        environment: t(
            'Using :variable of the .env file. Leave blank to keep using it.',
            { variable: credential.hint ?? '' },
        ),
        missing:
            mailer === 'smtp'
                ? t('Leave blank if the server does not require it.')
                : t('Required. You can also write env::variable.', {
                      variable: credential.hint ?? '',
                  }),
    }[credential.source];
};

const canRemove = (mailer: SecretMailer) =>
    ['stored', 'reference'].includes(props.credentials[mailer].source);

const toggleRemove = (
    mailer: SecretMailer,
    remove: boolean | 'indeterminate',
) => {
    const field = secretFields[mailer];

    form.remove =
        remove === true
            ? [...form.remove, field]
            : form.remove.filter((item) => item !== field);
};

/** Known SMTP servers: choosing one fills host, port and encryption. */
const presets = [
    {
        value: 'custom',
        label: 'Own server',
        host: '',
        port: 587,
        scheme: 'auto',
        username: null,
        hint: '',
    },
    {
        value: 'gmail',
        label: 'Gmail / Google Workspace',
        host: 'smtp.gmail.com',
        port: 587,
        scheme: 'smtp',
        username: null,
        hint: 'Use an app password, not the account password.',
    },
    {
        value: 'ses',
        label: 'Amazon SES',
        host: 'email-smtp.us-east-1.amazonaws.com',
        port: 587,
        scheme: 'smtp',
        username: null,
        hint: 'SES SMTP credentials, not the AWS access keys. Change the region in the host.',
    },
    {
        value: 'brevo',
        label: 'Brevo',
        host: 'smtp-relay.brevo.com',
        port: 587,
        scheme: 'smtp',
        username: null,
        hint: 'SMTP login and SMTP key of the Brevo account.',
    },
    {
        value: 'sendgrid',
        label: 'SendGrid',
        host: 'smtp.sendgrid.net',
        port: 587,
        scheme: 'smtp',
        username: 'apikey',
        hint: 'The username is apikey and the password the API key.',
    },
    {
        value: 'zoho',
        label: 'Zoho Mail',
        host: 'smtp.zoho.com',
        port: 465,
        scheme: 'smtps',
        username: null,
        hint: 'Use an app password if two-factor authentication is on.',
    },
    {
        value: 'resend',
        label: 'Resend (SMTP)',
        host: 'smtp.resend.com',
        port: 465,
        scheme: 'smtps',
        username: 'resend',
        hint: 'The username is resend and the password the API key.',
    },
] as const;

const preset = ref<string>(
    presets.find(
        (item) => item.host !== '' && item.host === props.settings.host,
    )?.value ?? 'custom',
);

const presetHint = computed(
    () => presets.find((item) => item.value === preset.value)?.hint ?? '',
);

const applyPreset = (value: unknown) => {
    const selected = presets.find((item) => item.value === value);

    if (!selected || selected.value === 'custom') {
        return;
    }

    form.host = selected.host;
    form.port = selected.port;
    form.scheme = selected.scheme;

    if (selected.username !== null) {
        form.username = selected.username;
    }
};

const useResendTestSender = () => {
    form.from_address = 'onboarding@resend.dev';
};
</script>

<template>
    <Head :title="t('Mail server')" />

    <form class="space-y-6" @submit.prevent="submit">
        <Heading
            variant="small"
            :title="t('Mail server')"
            :description="
                t(
                    'Used for login codes, password resets, verifications and notifications',
                )
            "
        />

        <RadioGroup
            v-model="form.mailer"
            class="grid gap-2 sm:grid-cols-2"
            data-test="mailer"
        >
            <Label
                v-for="option in mailers"
                :key="option.value"
                class="flex items-start gap-3 rounded-lg border p-3 font-normal has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent/40"
            >
                <RadioGroupItem :value="option.value" class="mt-0.5" />
                <component
                    :is="option.icon"
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <span class="grid gap-0.5">
                    <span class="flex flex-wrap items-center gap-2 font-medium">
                        {{ t(option.label) }}
                        <Badge v-if="option.recommended" variant="secondary">
                            {{ t('Recommended') }}
                        </Badge>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ t(option.description) }}
                        <template v-if="option.value === 'env' && envMailer">
                            ({{ t('current') }}: {{ envMailer }})
                        </template>
                    </span>
                </span>
            </Label>
        </RadioGroup>
        <p v-if="form.errors.mailer" class="text-sm text-destructive">
            {{ form.errors.mailer }}
        </p>

        <!-- Resend -->
        <template v-if="form.mailer === 'resend'">
            <FormField
                v-slot="field"
                :label="t('API key')"
                :error="form.errors.resend_key"
                :description="credentialHelp('resend')"
            >
                <PasswordInput
                    v-model="form.resend_key"
                    v-bind="field"
                    autocomplete="off"
                    placeholder="re_…"
                    data-test="resend-key"
                />
            </FormField>
            <StatusAlert :title="t('Verify your domain in Resend')">
                {{
                    t(
                        'Resend only delivers from a domain you verified. To try it without one, send from onboarding@resend.dev: it only reaches the email of your Resend account.',
                    )
                }}
                <Button
                    type="button"
                    variant="link"
                    class="h-auto p-0 underline"
                    @click="useResendTestSender"
                >
                    {{ t('Use onboarding@resend.dev') }}
                </Button>
            </StatusAlert>
        </template>

        <!-- SMTP -->
        <template v-if="form.mailer === 'smtp'">
            <FormField
                v-slot="field"
                :label="t('Provider')"
                :description="presetHint ? t(presetHint) : undefined"
            >
                <Select v-model="preset" @update:model-value="applyPreset">
                    <SelectTrigger class="w-full sm:w-72" v-bind="field">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="item in presets"
                            :key="item.value"
                            :value="item.value"
                        >
                            {{ t(item.label) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </FormField>

            <div class="grid gap-6 sm:grid-cols-3">
                <FormField
                    v-slot="field"
                    :label="t('Host')"
                    :error="form.errors.host"
                    class="sm:col-span-2"
                    required
                >
                    <Input
                        v-model="form.host"
                        v-bind="field"
                        placeholder="smtp.example.com"
                    />
                </FormField>
                <FormField
                    v-slot="field"
                    :label="t('Port')"
                    :error="form.errors.port"
                    required
                >
                    <Input v-model="form.port" v-bind="field" type="number" />
                </FormField>
            </div>

            <FormField
                v-slot="field"
                :label="t('Encryption')"
                :error="form.errors.scheme"
            >
                <Select v-model="form.scheme">
                    <SelectTrigger class="w-full sm:w-72" v-bind="field">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="auto">{{
                            t('Automatic (by port)')
                        }}</SelectItem>
                        <SelectItem value="smtp">{{
                            t('STARTTLS (usually port 587)')
                        }}</SelectItem>
                        <SelectItem value="smtps">{{
                            t('SSL/TLS (usually port 465)')
                        }}</SelectItem>
                    </SelectContent>
                </Select>
            </FormField>

            <div class="grid gap-6 sm:grid-cols-2">
                <FormField
                    v-slot="field"
                    :label="t('Username')"
                    :error="form.errors.username"
                >
                    <Input
                        v-model="form.username"
                        v-bind="field"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    v-slot="field"
                    :label="t('Password')"
                    :error="form.errors.password"
                    :description="credentialHelp('smtp')"
                >
                    <PasswordInput
                        v-model="form.password"
                        v-bind="field"
                        autocomplete="new-password"
                    />
                </FormField>
            </div>
        </template>

        <!-- Postmark -->
        <FormField
            v-if="form.mailer === 'postmark'"
            v-slot="field"
            :label="t('Server API token')"
            :error="form.errors.postmark_token"
            :description="credentialHelp('postmark')"
        >
            <PasswordInput
                v-model="form.postmark_token"
                v-bind="field"
                autocomplete="off"
            />
        </FormField>

        <!-- Mailgun -->
        <template v-if="form.mailer === 'mailgun'">
            <FormField
                v-slot="field"
                :label="t('Private API key')"
                :error="form.errors.mailgun_secret"
                :description="credentialHelp('mailgun')"
            >
                <PasswordInput
                    v-model="form.mailgun_secret"
                    v-bind="field"
                    autocomplete="off"
                />
            </FormField>
            <div class="grid gap-6 sm:grid-cols-3">
                <FormField
                    v-slot="field"
                    :label="t('Sending domain')"
                    :error="form.errors.mailgun_domain"
                    class="sm:col-span-2"
                    required
                >
                    <Input
                        v-model="form.mailgun_domain"
                        v-bind="field"
                        placeholder="mg.example.com"
                    />
                </FormField>
                <FormField
                    v-slot="field"
                    :label="t('Region')"
                    :error="form.errors.mailgun_region"
                >
                    <Select v-model="form.mailgun_region">
                        <SelectTrigger class="w-full" v-bind="field">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="us">{{
                                t('United States')
                            }}</SelectItem>
                            <SelectItem value="eu">{{
                                t('Europe')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
            </div>
        </template>

        <FormField
            v-if="
                form.mailer in secretFields &&
                canRemove(form.mailer as SecretMailer)
            "
            v-slot="field"
            :label="t('Remove the saved credential')"
            horizontal
        >
            <Checkbox
                v-bind="field"
                :model-value="
                    form.remove.includes(
                        secretFields[form.mailer as SecretMailer],
                    )
                "
                @update:model-value="
                    (value) => toggleRemove(form.mailer as SecretMailer, value)
                "
            />
        </FormField>

        <div class="grid gap-6 sm:grid-cols-2">
            <FormField
                v-slot="field"
                :label="t('Sender email')"
                :error="form.errors.from_address"
                required
            >
                <Input
                    v-model="form.from_address"
                    v-bind="field"
                    type="email"
                />
            </FormField>
            <FormField
                v-slot="field"
                :label="t('Sender name')"
                :error="form.errors.from_name"
                required
            >
                <Input v-model="form.from_name" v-bind="field" />
            </FormField>
        </div>

        <SubmitButton :processing="form.processing">{{
            t('Save')
        }}</SubmitButton>
    </form>

    <form class="space-y-4" @submit.prevent="sendTest">
        <Heading
            variant="small"
            :title="t('Send a test email')"
            :description="
                t(
                    'The test uses the values above, even before you save them. Blank keys use the saved ones.',
                )
            "
        />
        <StatusAlert
            v-if="lastTest"
            :tone="lastTest.ok ? 'success' : 'error'"
            :title="
                lastTest.ok ? t('Last test: delivered') : t('Last test: failed')
            "
            data-test="mail-last-test"
        >
            {{
                t(':mailer to :email, :date', {
                    mailer: lastTest.mailer,
                    email: lastTest.to,
                    date: new Date(lastTest.at).toLocaleString(),
                })
            }}
            <template v-if="lastTest.error">
                <br />
                {{ lastTest.error }}
            </template>
        </StatusAlert>
        <div class="flex flex-col gap-2 sm:flex-row">
            <Input
                v-model="testForm.email"
                type="email"
                class="sm:w-72"
                required
                :aria-label="t('Email')"
            />
            <SubmitButton variant="outline" :processing="testForm.processing">
                <Send v-if="!testForm.processing" class="size-4" />
                {{ t('Try this configuration') }}
            </SubmitButton>
        </div>
        <p v-if="testForm.errors.email" class="text-sm text-destructive">
            {{ testForm.errors.email }}
        </p>
        <p
            v-for="message in testErrors"
            :key="message"
            class="text-sm text-destructive"
        >
            {{ message }}
        </p>
    </form>
</template>
