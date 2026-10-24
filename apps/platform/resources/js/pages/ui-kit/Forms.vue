<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Search } from '@lucide/vue';
import DatePicker from '@/components/kit/DatePicker.vue';
import FormField from '@/components/kit/FormField.vue';
import SubmitButton from '@/components/kit/SubmitButton.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Button } from '@starter/ui/components/button';
import { Checkbox } from '@starter/ui/components/checkbox';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
    ComboboxTrigger,
} from '@starter/ui/components/combobox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSeparator,
    FieldSet,
    FieldTitle,
} from '@starter/ui/components/field';
import { Input } from '@starter/ui/components/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@starter/ui/components/input-group';
import {
    NumberField,
    NumberFieldContent,
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@starter/ui/components/number-field';
import { RadioGroup, RadioGroupItem } from '@starter/ui/components/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Slider } from '@starter/ui/components/slider';
import { Switch } from '@starter/ui/components/switch';
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from '@starter/ui/components/tags-input';
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Forms', href: show('forms') },
        ],
    },
});

const countries = [
    { value: 'cl', label: 'Chile' },
    { value: 'ar', label: 'Argentina' },
    { value: 'pe', label: 'Perú' },
    { value: 'co', label: 'Colombia' },
    { value: 'mx', label: 'México' },
    { value: 'es', label: 'España' },
    { value: 'us', label: 'United States' },
];

type Country = (typeof countries)[number];

const form = useForm({
    name: '',
    email: '',
    country: null as Country | null,
    industry: '',
    website: '',
    description: '',
    plan: 'team',
    seats: 5,
    budget: [2500],
    starts_on: null as string | null,
    tags: ['vip'] as string[],
    channels: ['email'] as string[],
    newsletter: true,
});

function toggleChannel(channel: string, checked: boolean) {
    form.channels = checked
        ? [...form.channels, channel]
        : form.channels.filter((item) => item !== channel);
}

// The catalog checks in the browser; a real form posts with
// form.post(store().url) and the errors come back in form.errors...
function submit() {
    form.clearErrors();

    if (!form.name.trim()) {
        form.setError('name', t('The name field is required.'));
    }

    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) {
        form.setError('email', t('Enter a valid email address.'));
    }

    if (!form.country) {
        form.setError('country', t('Choose a country.'));
    }

    if (!form.hasErrors) {
        notify.success(t('Valid form (demo: nothing was sent).'));
    }
}

const fieldCode = `
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { DatePicker, FormField, SubmitButton } from '@/components/kit';
import { Input } from '@starter/ui/components/input';
import { store } from '@/routes/customers';

const form = useForm({ name: '', email: '', starts_on: null });
<\u002Fscript>

<template>
    <form class="grid gap-6" @submit.prevent="form.post(store().url)">
        <FormField :label="t('Name')" :error="form.errors.name" required v-slot="field">
            <Input v-model="form.name" v-bind="field" />
        </FormField>
        <FormField :label="t('Email')" :error="form.errors.email"
            :description="t('We will send the invoices here.')" v-slot="field">
            <Input v-model="form.email" type="email" v-bind="field" />
        </FormField>
        <FormField :label="t('Start date')" :error="form.errors.starts_on" v-slot="field">
            <DatePicker v-model="form.starts_on" v-bind="field" />
        </FormField>
        <SubmitButton :processing="form.processing">{{ t('Save') }}</SubmitButton>
    </form>
</template>
`;

const choiceCode = `
<RadioGroup v-model="form.plan">
    <FieldLabel for="plan-team">
        <Field orientation="horizontal">
            <FieldContent>
                <FieldTitle>{{ t('Team') }}</FieldTitle>
                <FieldDescription>{{ t('Up to 50 users.') }}</FieldDescription>
            </FieldContent>
            <RadioGroupItem id="plan-team" value="team" />
        </Field>
    </FieldLabel>
</RadioGroup>

<FormField :label="t('Send me news')" horizontal v-slot="field">
    <Switch v-model="form.newsletter" v-bind="field" />
</FormField>
`;
</script>

<template>
    <Head :title="t('Forms')" />

    <Example
        :title="t('Complete form')"
        :description="
            t(
                'FormField wires label, help text and the error of form.errors. Submit it empty to see the errors.',
            )
        "
        :code="fieldCode"
    >
        <form class="grid max-w-3xl gap-8" novalidate @submit.prevent="submit">
            <FieldSet>
                <FieldLegend>{{ t('Customer') }}</FieldLegend>
                <FieldDescription>
                    {{ t('Basic data to identify the account.') }}
                </FieldDescription>
                <FieldGroup class="grid gap-6 md:grid-cols-2">
                    <FormField
                        v-slot="field"
                        :label="t('Name')"
                        :error="form.errors.name"
                        required
                    >
                        <Input
                            v-model="form.name"
                            v-bind="field"
                            autocomplete="organization"
                        />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Email')"
                        :error="form.errors.email"
                        :description="t('We will send the invoices here.')"
                        required
                    >
                        <Input
                            v-model="form.email"
                            v-bind="field"
                            type="email"
                            autocomplete="email"
                        />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Country')"
                        :error="form.errors.country"
                        required
                    >
                        <Combobox v-model="form.country" by="value">
                            <ComboboxAnchor as-child>
                                <ComboboxTrigger as-child>
                                    <Button
                                        v-bind="field"
                                        variant="outline"
                                        class="w-full justify-between font-normal aria-invalid:border-destructive"
                                        :class="
                                            !form.country &&
                                            'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            form.country?.label ??
                                            t('Search a country')
                                        }}
                                        <ChevronsUpDown
                                            class="size-4 opacity-50"
                                        />
                                    </Button>
                                </ComboboxTrigger>
                            </ComboboxAnchor>
                            <ComboboxList
                                class="w-(--reka-popper-anchor-width)"
                            >
                                <div class="relative">
                                    <ComboboxInput
                                        class="h-9 rounded-none border-0 border-b pl-9 focus-visible:ring-0"
                                        :placeholder="t('Search')"
                                    />
                                    <Search
                                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                </div>
                                <ComboboxEmpty>{{
                                    t('No results.')
                                }}</ComboboxEmpty>
                                <ComboboxGroup>
                                    <ComboboxItem
                                        v-for="country in countries"
                                        :key="country.value"
                                        :value="country"
                                    >
                                        {{ country.label }}
                                        <ComboboxItemIndicator>
                                            <Check class="ml-auto size-4" />
                                        </ComboboxItemIndicator>
                                    </ComboboxItem>
                                </ComboboxGroup>
                            </ComboboxList>
                        </Combobox>
                    </FormField>
                    <FormField v-slot="field" :label="t('Industry')" optional>
                        <Select v-model="form.industry">
                            <SelectTrigger v-bind="field" class="w-full">
                                <SelectValue :placeholder="t('Select')" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="retail">{{
                                    t('Retail')
                                }}</SelectItem>
                                <SelectItem value="services">{{
                                    t('Services')
                                }}</SelectItem>
                                <SelectItem value="industry">{{
                                    t('Industry')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField v-slot="field" :label="t('Website')" optional>
                        <InputGroup>
                            <InputGroupAddon>
                                <InputGroupText>https://</InputGroupText>
                            </InputGroupAddon>
                            <InputGroupInput
                                v-model="form.website"
                                v-bind="field"
                                placeholder="acme.cl"
                            />
                        </InputGroup>
                    </FormField>
                    <FormField v-slot="field" :label="t('Start date')" optional>
                        <DatePicker
                            v-model="form.starts_on"
                            v-bind="field"
                            class="sm:w-full"
                        />
                    </FormField>
                    <FormField
                        v-slot="field"
                        :label="t('Description')"
                        optional
                        class="md:col-span-2"
                    >
                        <Textarea
                            v-model="form.description"
                            v-bind="field"
                            rows="3"
                        />
                    </FormField>
                </FieldGroup>
            </FieldSet>

            <FieldSeparator />

            <FieldSet>
                <FieldLegend>{{ t('Plan') }}</FieldLegend>
                <RadioGroup
                    v-model="form.plan"
                    class="grid gap-3 md:grid-cols-3"
                >
                    <FieldLabel
                        v-for="plan in [
                            {
                                value: 'starter',
                                title: t('Starter'),
                                text: t('Up to 5 users.'),
                            },
                            {
                                value: 'team',
                                title: t('Team'),
                                text: t('Up to 50 users.'),
                            },
                            {
                                value: 'enterprise',
                                title: t('Enterprise'),
                                text: t('No limits and support.'),
                            },
                        ]"
                        :key="plan.value"
                        :for="`plan-${plan.value}`"
                    >
                        <Field orientation="horizontal">
                            <FieldContent>
                                <FieldTitle>{{ plan.title }}</FieldTitle>
                                <FieldDescription>{{
                                    plan.text
                                }}</FieldDescription>
                            </FieldContent>
                            <RadioGroupItem
                                :id="`plan-${plan.value}`"
                                :value="plan.value"
                            />
                        </Field>
                    </FieldLabel>
                </RadioGroup>

                <FieldGroup class="grid gap-6 md:grid-cols-2">
                    <Field>
                        <FieldLabel for="seats">{{ t('Seats') }}</FieldLabel>
                        <NumberField
                            id="seats"
                            v-model="form.seats"
                            :min="1"
                            :max="500"
                        >
                            <NumberFieldContent>
                                <NumberFieldDecrement />
                                <NumberFieldInput />
                                <NumberFieldIncrement />
                            </NumberFieldContent>
                        </NumberField>
                    </Field>
                    <Field>
                        <FieldLabel for="budget">
                            {{ t('Monthly budget') }}
                            <span class="ml-auto font-normal tabular-nums">
                                US$ {{ form.budget[0] }}
                            </span>
                        </FieldLabel>
                        <Slider
                            id="budget"
                            v-model="form.budget"
                            :min="0"
                            :max="10000"
                            :step="250"
                            class="py-2"
                        />
                    </Field>
                    <Field class="md:col-span-2">
                        <FieldLabel for="tags">{{ t('Tags') }}</FieldLabel>
                        <TagsInput id="tags" v-model="form.tags">
                            <TagsInputItem
                                v-for="tag in form.tags"
                                :key="tag"
                                :value="tag"
                            >
                                <TagsInputItemText />
                                <TagsInputItemDelete />
                            </TagsInputItem>
                            <TagsInputInput
                                :placeholder="t('Add and press Enter')"
                            />
                        </TagsInput>
                    </Field>
                </FieldGroup>
            </FieldSet>

            <FieldSeparator />

            <FieldSet>
                <FieldLegend variant="label">{{
                    t('Contact channels')
                }}</FieldLegend>
                <FieldGroup data-slot="checkbox-group" class="gap-3">
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
                            :model-value="form.channels.includes(channel.value)"
                            @update:model-value="
                                (value) =>
                                    toggleChannel(channel.value, value === true)
                            "
                        />
                    </FormField>
                </FieldGroup>
                <FormField
                    v-slot="field"
                    :label="t('Send me product news')"
                    :description="t('At most one email per month.')"
                    horizontal
                >
                    <Switch v-model="form.newsletter" v-bind="field" />
                </FormField>
            </FieldSet>

            <div class="flex gap-2">
                <SubmitButton :processing="form.processing">
                    {{ t('Save customer') }}
                </SubmitButton>
                <Button
                    type="button"
                    variant="ghost"
                    @click="
                        () => {
                            form.reset();
                            form.clearErrors();
                        }
                    "
                >
                    {{ t('Reset') }}
                </Button>
            </div>
        </form>
    </Example>

    <Example
        :title="t('Choice cards and switches')"
        :description="
            t(
                'Options with a description read better as cards; booleans as a switch or checkbox before the label.',
            )
        "
        :code="choiceCode"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t('See the Plan and Contact channels blocks of the form above.')
            }}
        </p>
    </Example>
</template>
