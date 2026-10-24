<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
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
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import mail from '@/routes/admin/settings/mail';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: mail.edit() }],
    },
});

type MailSettings = {
    mailer: 'env' | 'smtp' | 'log';
    host: string | null;
    port: number | null;
    username: string | null;
    scheme: 'smtp' | 'smtps' | null;
    from_address: string;
    from_name: string;
    has_password: boolean;
};

const props = defineProps<{
    settings: MailSettings;
    envMailer: string | null;
}>();

const page = usePage();

const form = useForm({
    mailer: props.settings.mailer,
    host: props.settings.host ?? '',
    port: props.settings.port ?? 587,
    username: props.settings.username ?? '',
    password: '',
    remove_password: false,
    scheme: props.settings.scheme ?? 'auto',
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
        onSuccess: () => form.reset('password', 'remove_password'),
    });
};

const sendTest = () => testForm.post(mail.test().url, { preserveScroll: true });

const mailers = [
    {
        value: 'env',
        label: 'Use the .env configuration',
        description: 'Keeps the MAIL_* variables of the environment file.',
    },
    {
        value: 'smtp',
        label: 'Custom SMTP server',
        description:
            'Gmail, Microsoft 365, Amazon SES, Mailgun, Postmark, etc.',
    },
    {
        value: 'log',
        label: 'Log only (development)',
        description:
            'Emails are written to storage/logs instead of being sent.',
    },
];
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

        <RadioGroup v-model="form.mailer" class="grid gap-2">
            <Label
                v-for="option in mailers"
                :key="option.value"
                class="flex items-start gap-3 rounded-lg border p-3 font-normal"
            >
                <RadioGroupItem :value="option.value" class="mt-0.5" />
                <span class="grid gap-0.5">
                    <span class="font-medium">{{ t(option.label) }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ t(option.description) }}
                        <template v-if="option.value === 'env' && envMailer">
                            ({{ t('current') }}: {{ envMailer }})
                        </template>
                    </span>
                </span>
            </Label>
        </RadioGroup>

        <template v-if="form.mailer === 'smtp'">
            <div class="grid gap-6 sm:grid-cols-3">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="host">{{ t('Host') }}</Label>
                    <Input
                        id="host"
                        v-model="form.host"
                        placeholder="smtp.gmail.com"
                    />
                    <InputError :message="form.errors.host" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">{{ t('Port') }}</Label>
                    <Input id="port" v-model="form.port" type="number" />
                    <InputError :message="form.errors.port" />
                </div>
            </div>

            <div class="grid gap-2 sm:w-72">
                <Label>{{ t('Encryption') }}</Label>
                <Select v-model="form.scheme">
                    <SelectTrigger class="w-full">
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
                <InputError :message="form.errors.scheme" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="username">{{ t('Username') }}</Label>
                    <Input
                        id="username"
                        v-model="form.username"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.username" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">{{ t('Password') }}</Label>
                    <PasswordInput
                        id="password"
                        v-model="form.password"
                        autocomplete="new-password"
                        :placeholder="
                            settings.has_password
                                ? t('Saved. Leave blank to keep it')
                                : ''
                        "
                    />
                    <InputError :message="form.errors.password" />
                </div>
            </div>
        </template>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="from_address">{{ t('Sender email') }}</Label>
                <Input
                    id="from_address"
                    v-model="form.from_address"
                    type="email"
                />
                <InputError :message="form.errors.from_address" />
            </div>
            <div class="grid gap-2">
                <Label for="from_name">{{ t('Sender name') }}</Label>
                <Input id="from_name" v-model="form.from_name" />
                <InputError :message="form.errors.from_name" />
            </div>
        </div>

        <Button type="submit" :disabled="form.processing">
            <Spinner v-if="form.processing" />
            {{ t('Save') }}
        </Button>
    </form>

    <form class="space-y-4" @submit.prevent="sendTest">
        <Heading
            variant="small"
            :title="t('Send a test email')"
            :description="
                t('Save the configuration first, then send a test email')
            "
        />
        <Alert v-if="form.isDirty">
            <AlertDescription>{{
                t(
                    'You have unsaved changes. The test uses the saved configuration.',
                )
            }}</AlertDescription>
        </Alert>
        <div class="flex flex-col gap-2 sm:flex-row">
            <Input
                v-model="testForm.email"
                type="email"
                class="sm:w-72"
                required
            />
            <Button
                type="submit"
                variant="outline"
                :disabled="testForm.processing"
            >
                <Spinner v-if="testForm.processing" />
                <Send v-else class="size-4" />
                {{ t('Send test') }}
            </Button>
        </div>
        <InputError :message="testForm.errors.email" />
    </form>
</template>
