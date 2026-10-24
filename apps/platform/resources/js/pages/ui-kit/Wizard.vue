<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ClipboardCheck, CreditCard, Settings2, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import DatePicker from '@/components/kit/DatePicker.vue';
import FormField from '@/components/kit/FormField.vue';
import StatusAlert from '@/components/kit/StatusAlert.vue';
import type { WizardStep } from '@/components/kit/WizardForm.vue';
import WizardForm from '@/components/kit/WizardForm.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Checkbox } from '@starter/ui/components/checkbox';
import { Input } from '@starter/ui/components/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { formatDate, t } from '@/lib/i18n';
import { index, show, wizard } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Wizard form', href: show('wizard') },
        ],
    },
});

const steps: WizardStep[] = [
    {
        key: 'account',
        title: t('Account'),
        description: t('Who you are'),
        icon: UserRound,
        fields: ['name', 'email', 'company'],
    },
    {
        key: 'plan',
        title: t('Plan'),
        description: t('What you need'),
        icon: CreditCard,
        fields: ['plan', 'seats', 'starts_on'],
    },
    {
        key: 'preferences',
        title: t('Preferences'),
        description: t('How to reach you'),
        icon: Settings2,
        fields: ['channels', 'terms'],
    },
    {
        key: 'review',
        title: t('Review'),
        description: t('Confirm and send'),
        icon: ClipboardCheck,
    },
];

const step = ref(0);

const form = useForm({
    name: '',
    email: '',
    company: '',
    plan: '',
    seats: '5',
    starts_on: null as string | null,
    channels: ['email'] as string[],
    terms: false,
});

const plans: Record<string, string> = {
    starter: 'Starter',
    team: 'Team',
    enterprise: 'Enterprise',
};

// Browser checks per step; the server validates everything again...
function validate(current: WizardStep): boolean {
    form.clearErrors(...((current.fields ?? []) as (keyof typeof form.data)[]));

    const required = (field: 'name' | 'email' | 'plan' | 'starts_on') => {
        if (!form[field]) {
            form.setError(field, t('This field is required.'));
        }
    };

    if (current.key === 'account') {
        required('name');
        required('email');
    }

    if (current.key === 'plan') {
        required('plan');
        required('starts_on');

        if (!(Number(form.seats) >= 1 && Number(form.seats) <= 500)) {
            form.setError('seats', t('Between 1 and 500.'));
        }
    }

    if (current.key === 'preferences' && !form.terms) {
        form.setError('terms', t('You have to accept the terms.'));
    }

    return !(current.fields ?? []).some(
        (field) => form.errors[field as keyof typeof form.errors],
    );
}

function toggleChannel(channel: string, checked: boolean) {
    form.channels = checked
        ? [...form.channels, channel]
        : form.channels.filter((item) => item !== channel);
}

const submit = () =>
    form.post(wizard().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            step.value = 0;
        },
    });

const code = `
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { WizardForm, type WizardStep } from '@/components/kit';

const steps: WizardStep[] = [
    { key: 'account', title: t('Account'), fields: ['name', 'email'] },
    { key: 'plan', title: t('Plan'), fields: ['plan', 'seats'] },
    { key: 'review', title: t('Review') },
];
const form = useForm({ name: '', email: '', plan: '', seats: 5 });

// Optional: check the step in the browser before moving on
const validate = (step: WizardStep) => { /* form.setError(...) */ return true; };
<\u002Fscript>

<template>
    <WizardForm :steps="steps" :errors="form.errors" :processing="form.processing"
        :validate="validate" :submit-text="t('Create account')" @submit="form.post(store().url)">
        <template #account>...fields...</template>
        <template #plan>...fields...</template>
        <template #review>...summary...</template>
    </WizardForm>
</template>
`;
</script>

<template>
    <Head :title="t('Wizard form')" />

    <Example
        :title="t('Wizard form')"
        :description="
            t(
                'Each step validates before moving on. The server validates everything again: an example.com email is rejected only there, and the wizard goes back to the step of that field.',
            )
        "
        :code="code"
    >
        <div class="mx-auto max-w-3xl">
            <WizardForm
                v-model:step="step"
                :steps="steps"
                :errors="form.errors"
                :processing="form.processing"
                :validate="validate"
                :submit-text="t('Create account')"
                @submit="submit"
            >
                <template #account>
                    <FormField
                        v-slot="field"
                        :label="t('Name')"
                        :error="form.errors.name"
                        required
                    >
                        <Input v-model="form.name" v-bind="field" />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Email')"
                        :error="form.errors.email"
                        :description="
                            t('Try ana@example.com to see a server error.')
                        "
                        required
                    >
                        <Input
                            v-model="form.email"
                            v-bind="field"
                            type="email"
                        />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Company')"
                        :error="form.errors.company"
                        optional
                    >
                        <Input v-model="form.company" v-bind="field" />
                    </FormField>
                </template>

                <template #plan>
                    <FormField
                        v-slot="field"
                        :label="t('Plan')"
                        :error="form.errors.plan"
                        required
                    >
                        <Select v-model="form.plan">
                            <SelectTrigger
                                v-bind="field"
                                class="w-full sm:w-64"
                            >
                                <SelectValue :placeholder="t('Select')" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(label, value) in plans"
                                    :key="value"
                                    :value="value"
                                    >{{ label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Seats')"
                        :error="form.errors.seats"
                        required
                    >
                        <Input
                            v-model="form.seats"
                            v-bind="field"
                            type="number"
                            min="1"
                            max="500"
                            class="sm:w-32"
                        />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Start date')"
                        :error="form.errors.starts_on"
                        required
                    >
                        <DatePicker v-model="form.starts_on" v-bind="field" />
                    </FormField>
                </template>

                <template #preferences>
                    <div class="grid gap-3">
                        <p class="text-sm font-medium">
                            {{ t('Contact channels') }}
                        </p>
                        <FormField
                            v-for="channel in [
                                { value: 'email', label: t('Email') },
                                { value: 'chat', label: t('Chat') },
                                { value: 'phone', label: t('Phone') },
                            ]"
                            :key="channel.value"
                            v-slot="field"
                            :label="channel.label"
                            horizontal
                        >
                            <Checkbox
                                v-bind="field"
                                :model-value="
                                    form.channels.includes(channel.value)
                                "
                                @update:model-value="
                                    (value) =>
                                        toggleChannel(
                                            channel.value,
                                            value === true,
                                        )
                                "
                            />
                        </FormField>
                    </div>
                    <FormField
                        v-slot="field"
                        :label="t('I accept the terms of service')"
                        :error="form.errors.terms"
                        horizontal
                    >
                        <Checkbox v-model="form.terms" v-bind="field" />
                    </FormField>
                </template>

                <template #review>
                    <StatusAlert tone="info" :title="t('Almost done')">
                        {{
                            t(
                                'Check the data. Sending only validates on the server (demo).',
                            )
                        }}
                    </StatusAlert>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 rounded-lg border p-4 text-sm"
                    >
                        <dt class="text-muted-foreground">{{ t('Name') }}</dt>
                        <dd>{{ form.name }}</dd>
                        <dt class="text-muted-foreground">{{ t('Email') }}</dt>
                        <dd>{{ form.email }}</dd>
                        <dt class="text-muted-foreground">
                            {{ t('Company') }}
                        </dt>
                        <dd>{{ form.company || '—' }}</dd>
                        <dt class="text-muted-foreground">{{ t('Plan') }}</dt>
                        <dd>
                            {{ plans[form.plan] ?? '—' }} · {{ form.seats }}
                        </dd>
                        <dt class="text-muted-foreground">
                            {{ t('Start date') }}
                        </dt>
                        <dd>
                            {{
                                form.starts_on
                                    ? formatDate(`${form.starts_on}T12:00:00`, {
                                          dateStyle: 'long',
                                      })
                                    : '—'
                            }}
                        </dd>
                    </dl>
                </template>
            </WizardForm>
        </div>
    </Example>
</template>
