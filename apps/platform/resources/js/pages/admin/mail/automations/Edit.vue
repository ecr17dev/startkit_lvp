<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import {
    DatePicker,
    FormField,
    StatusAlert,
    SubmitButton,
} from '@/components/kit';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@starter/ui/components/native-select';
import { RadioGroup, RadioGroupItem } from '@starter/ui/components/radio-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import {
    ToggleGroup,
    ToggleGroupItem,
} from '@starter/ui/components/toggle-group';
import { t } from '@/lib/i18n';
import type {
    MailAutomation,
    MailAutomationSettings,
    MailEvent,
    MailTrigger,
} from '@/lib/mailAutomations';
import { describeAutomation, triggers, weekdays } from '@/lib/mailAutomations';
import { destroy, index, store, update } from '@/routes/admin/mail/automations';
import { edit as editTemplate } from '@/routes/admin/mail/templates';

type TemplateOption = { key: string; name: string; category: string };

const props = defineProps<{
    automation: MailAutomation | null;
    templates: TemplateOption[];
    roles: string[];
    events: MailEvent[];
    timezones: string[];
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail', href: index() }],
    },
});

const ALL = '__all__';

const initial: Required<Omit<MailAutomationSettings, 'role' | 'date'>> & {
    role: string;
    date: string | null;
} = {
    delay_minutes: 0,
    days: 30,
    role: ALL,
    frequency: 'weekly',
    date: null,
    weekday: 1,
    day: 1,
    time: '09:00',
    timezone: props.timezone,
    event: props.events[0]?.key ?? '',
    recipients: 'person',
};

const form = useForm({
    name: props.automation?.name ?? '',
    trigger: (props.automation?.trigger ?? 'user_created') as MailTrigger,
    template_key: props.automation?.template_key ?? 'automation.welcome',
    is_active: props.automation?.is_active ?? true,
    settings: {
        ...initial,
        ...props.automation?.settings,
        role: props.automation?.settings.role ?? ALL,
    },
});

const error = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

// A new automation starts with the template of its trigger...
watch(
    () => form.trigger,
    (trigger, previous) => {
        const suggested = (value: MailTrigger) =>
            triggers.find((item) => item.value === value)?.template;

        if (!props.automation && form.template_key === suggested(previous)) {
            form.template_key = suggested(trigger) ?? form.template_key;
        }
    },
);

const event = computed(() =>
    props.events.find((item) => item.key === form.settings.event),
);

// Events about no one in particular go to a role...
watch(event, (current) => {
    if (current && current.person === null) {
        form.settings.recipients = 'role';
    }
});

const templateGroups = computed(() =>
    [
        { key: 'automation', label: t('Automations') },
        { key: 'custom', label: t('Custom') },
        { key: 'access', label: t('Access') },
        { key: 'ai', label: t('Artificial intelligence') },
        { key: 'system', label: t('System') },
    ]
        .map((group) => ({
            ...group,
            templates: props.templates.filter(
                (template) => template.category === group.key,
            ),
        }))
        .filter((group) => group.templates.length > 0),
);

const payload = () => ({
    ...form.data(),
    settings: {
        ...form.settings,
        role: form.settings.role === ALL ? null : form.settings.role,
    },
});

const summary = computed(() =>
    describeAutomation(
        {
            trigger: form.trigger,
            settings: payload().settings as MailAutomationSettings,
        },
        props.events,
    ),
);

const submit = () => {
    const transformed = form.transform(payload);

    if (props.automation) {
        transformed.put(update(props.automation.id).url);
    } else {
        transformed.post(store().url);
    }
};

const remove = () =>
    props.automation && router.delete(destroy(props.automation.id).url);
</script>

<template>
    <Head :title="automation ? automation.name : t('New automation')" />

    <PageHeader
        :title="automation ? automation.name : t('New automation')"
        :description="
            t('Choose when it sends, to whom and with which template.')
        "
    >
        <template #icon>
            <Button
                variant="ghost"
                size="icon"
                as-child
                :aria-label="t('Back')"
            >
                <Link :href="index()"><ArrowLeft /></Link>
            </Button>
        </template>
    </PageHeader>

    <form class="grid max-w-3xl gap-8" @submit.prevent="submit">
        <FormField
            :label="t('Name')"
            :error="form.errors.name"
            required
            v-slot="field"
        >
            <Input
                v-model="form.name"
                v-bind="field"
                :placeholder="t('Welcome to new users')"
                data-test="automation-name"
            />
        </FormField>

        <fieldset class="grid gap-3">
            <legend class="mb-3 text-sm font-medium">
                {{ t('When does it send?') }}
            </legend>
            <RadioGroup
                v-model="form.trigger"
                class="grid gap-2 sm:grid-cols-2"
                data-test="automation-trigger"
            >
                <Label
                    v-for="option in triggers"
                    :key="option.value"
                    class="flex items-start gap-3 rounded-lg border p-3 font-normal has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent/40"
                >
                    <RadioGroupItem
                        :value="option.value"
                        class="mt-0.5"
                        :data-test="`trigger-${option.value}`"
                    />
                    <component
                        :is="option.icon"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span class="grid gap-0.5">
                        <span class="font-medium">{{ t(option.title) }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t(option.description)
                        }}</span>
                    </span>
                </Label>
            </RadioGroup>
        </fieldset>

        <!-- Welcome -->
        <div v-if="form.trigger === 'user_created'" class="grid gap-4">
            <FormField
                :label="t('Delay in minutes')"
                :error="error('settings.delay_minutes')"
                :description="t('0 sends it right away.')"
                v-slot="field"
            >
                <Input
                    v-model.number="form.settings.delay_minutes"
                    v-bind="field"
                    type="number"
                    min="0"
                    max="43200"
                    class="w-40"
                />
            </FormField>
        </div>

        <!-- Inactivity -->
        <div
            v-else-if="form.trigger === 'inactivity'"
            class="grid gap-4 sm:grid-cols-2"
        >
            <FormField
                :label="t('Days without logging in')"
                :error="error('settings.days')"
                required
                v-slot="field"
            >
                <Input
                    v-model.number="form.settings.days"
                    v-bind="field"
                    type="number"
                    min="1"
                    max="365"
                    data-test="inactivity-days"
                />
            </FormField>
            <FormField
                :label="t('Recipients')"
                :error="error('settings.role')"
                v-slot="field"
            >
                <Select v-model="form.settings.role">
                    <SelectTrigger v-bind="field" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">{{
                            t('All active users')
                        }}</SelectItem>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ t('Role :role', { role }) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>
            <StatusAlert tone="info" class="sm:col-span-2">
                {{
                    t(
                        'Users who are already inactive also receive it. Each person gets it once per inactive period: again only after logging in and going inactive again.',
                    )
                }}
            </StatusAlert>
        </div>

        <!-- Scheduled -->
        <div
            v-else-if="form.trigger === 'scheduled'"
            class="grid gap-4 sm:grid-cols-2"
        >
            <FormField
                :label="t('Frequency')"
                :error="error('settings.frequency')"
                class="sm:col-span-2"
                v-slot="field"
            >
                <ToggleGroup
                    :model-value="form.settings.frequency"
                    type="single"
                    variant="outline"
                    v-bind="field"
                    @update:model-value="
                        (value) =>
                            value &&
                            (form.settings.frequency = value as
                                'once' | 'weekly' | 'monthly')
                    "
                >
                    <ToggleGroupItem value="once">{{
                        t('Once')
                    }}</ToggleGroupItem>
                    <ToggleGroupItem value="weekly">{{
                        t('Every week')
                    }}</ToggleGroupItem>
                    <ToggleGroupItem value="monthly">{{
                        t('Every month')
                    }}</ToggleGroupItem>
                </ToggleGroup>
            </FormField>

            <FormField
                v-if="form.settings.frequency === 'once'"
                :label="t('Date')"
                :error="error('settings.date')"
                required
                v-slot="field"
            >
                <DatePicker v-model="form.settings.date" v-bind="field" />
            </FormField>
            <FormField
                v-else-if="form.settings.frequency === 'weekly'"
                :label="t('Day of the week')"
                :error="error('settings.weekday')"
                v-slot="field"
            >
                <NativeSelect
                    v-model.number="form.settings.weekday"
                    v-bind="field"
                >
                    <NativeSelectOption
                        v-for="(name, position) in weekdays()"
                        :key="name"
                        :value="position + 1"
                        >{{
                            name.charAt(0).toLocaleUpperCase() + name.slice(1)
                        }}</NativeSelectOption
                    >
                </NativeSelect>
            </FormField>
            <FormField
                v-else
                :label="t('Day of the month')"
                :error="error('settings.day')"
                :description="t('From 1 to 28, so it exists every month.')"
                v-slot="field"
            >
                <NativeSelect v-model.number="form.settings.day" v-bind="field">
                    <NativeSelectOption
                        v-for="day in 28"
                        :key="day"
                        :value="day"
                        >{{ day }}</NativeSelectOption
                    >
                </NativeSelect>
            </FormField>

            <FormField
                :label="t('Send time')"
                :error="error('settings.time')"
                required
                v-slot="field"
            >
                <Input
                    v-model="form.settings.time"
                    v-bind="field"
                    type="time"
                />
            </FormField>

            <FormField
                :label="t('Time zone')"
                :error="error('settings.timezone')"
                v-slot="field"
            >
                <NativeSelect v-model="form.settings.timezone" v-bind="field">
                    <NativeSelectOption
                        v-for="zone in timezones"
                        :key="zone"
                        :value="zone"
                        >{{ zone }}</NativeSelectOption
                    >
                </NativeSelect>
            </FormField>

            <FormField
                :label="t('Recipients')"
                :error="error('settings.role')"
                v-slot="field"
            >
                <Select v-model="form.settings.role">
                    <SelectTrigger v-bind="field" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">{{
                            t('All active users')
                        }}</SelectItem>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ t('Role :role', { role }) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>
        </div>

        <!-- System event -->
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <FormField
                :label="t('Event')"
                :error="error('settings.event')"
                required
                v-slot="field"
            >
                <Select v-model="form.settings.event">
                    <SelectTrigger
                        v-bind="field"
                        class="w-full"
                        data-test="automation-event"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="item in events"
                            :key="item.key"
                            :value="item.key"
                            >{{ item.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>

            <FormField
                :label="t('Send to')"
                :error="error('settings.recipients')"
                v-slot="field"
            >
                <Select v-model="form.settings.recipients">
                    <SelectTrigger v-bind="field" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            value="person"
                            :disabled="event?.person === null"
                            >{{ t('The person involved') }}</SelectItem
                        >
                        <SelectItem value="role">{{
                            t('The users of a role')
                        }}</SelectItem>
                    </SelectContent>
                </Select>
            </FormField>

            <FormField
                v-if="form.settings.recipients === 'role'"
                :label="t('Role')"
                :error="error('settings.role')"
                required
                v-slot="field"
            >
                <Select v-model="form.settings.role">
                    <SelectTrigger v-bind="field" class="w-full">
                        <SelectValue :placeholder="t('Choose a role')" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ role }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </FormField>
        </div>

        <FormField
            :label="t('Template')"
            :error="form.errors.template_key"
            required
            v-slot="field"
        >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Select v-model="form.template_key">
                    <SelectTrigger
                        v-bind="field"
                        class="w-full sm:max-w-sm"
                        data-test="automation-template"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup
                            v-for="group in templateGroups"
                            :key="group.key"
                        >
                            <SelectLabel>{{ group.label }}</SelectLabel>
                            <SelectItem
                                v-for="template in group.templates"
                                :key="template.key"
                                :value="template.key"
                                >{{ template.name }}</SelectItem
                            >
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <Button variant="link" as-child class="px-0 sm:px-2">
                    <Link :href="editTemplate(form.template_key)">
                        {{ t('Edit template') }}
                        <ExternalLink />
                    </Link>
                </Button>
            </div>
        </FormField>

        <FormField :label="t('Active')" horizontal v-slot="field">
            <Switch v-model="form.is_active" v-bind="field" />
        </FormField>

        <StatusAlert
            tone="info"
            :title="t('Summary')"
            data-test="automation-summary"
        >
            {{ summary }}
        </StatusAlert>

        <div class="flex flex-wrap items-center gap-2">
            <SubmitButton
                :processing="form.processing"
                data-test="automation-save"
                >{{ t('Save') }}</SubmitButton
            >
            <ConfirmDialog
                v-if="automation"
                :title="t('Delete automation')"
                :description="
                    t(
                        'It stops sending. Its sends stay in the log; the pending ones are skipped.',
                    )
                "
                :confirm-text="t('Delete')"
                @confirm="remove"
            >
                <Button type="button" variant="ghost" class="text-destructive">
                    <Trash2 />
                    {{ t('Delete') }}
                </Button>
            </ConfirmDialog>
        </div>
    </form>
</template>
