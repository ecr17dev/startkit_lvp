<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Separator } from '@starter/ui/components/separator';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/onboarding';

defineOptions({
    layout: {
        title: 'Welcome',
        description: 'A couple of details and you are ready',
    },
});

const props = defineProps<{
    profile: { name: string; locale: string };
    workspace: {
        app_name: string;
        locale: string;
        timezone: string;
        registration: string;
    } | null;
    timezones: string[];
    registrationModes: string[];
}>();

const page = usePage();
const locales = computed(
    () => (page.props.locales ?? {}) as Record<string, string>,
);

const form = useForm({
    name: props.profile.name,
    locale: props.profile.locale,
    app_name: props.workspace?.app_name ?? '',
    workspace_locale: props.workspace?.locale ?? props.profile.locale,
    timezone: props.workspace?.timezone ?? 'UTC',
    registration: props.workspace?.registration ?? 'invite-only',
});

const modeLabels: Record<string, string> = {
    open: 'Open: anyone can sign up',
    'invite-only': 'Invite only',
    closed: 'Closed: administrators create the users',
};

const submit = () => form.post(store().url);
</script>

<template>
    <Head :title="t('Welcome')" />

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">{{ t('Your name') }}</Label>
            <Input
                id="name"
                v-model="form.name"
                required
                v-focus
                autocomplete="name"
                data-test="onboarding-name"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="locale">{{ t('Your language') }}</Label>
            <Select v-model="form.locale">
                <SelectTrigger id="locale"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="(label, code) in locales"
                        :key="code"
                        :value="code"
                        >{{ label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <InputError :message="form.errors.locale" />
        </div>

        <template v-if="workspace">
            <Separator />
            <div>
                <h2 class="text-sm font-medium">{{ t('Workspace') }}</h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'You are the owner. You can change this later in the system settings.',
                        )
                    }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="app_name">{{ t('Application name') }}</Label>
                <Input
                    id="app_name"
                    v-model="form.app_name"
                    required
                    data-test="onboarding-app-name"
                />
                <InputError :message="form.errors.app_name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="workspace_locale">{{
                        t('Default language')
                    }}</Label>
                    <Select v-model="form.workspace_locale">
                        <SelectTrigger id="workspace_locale"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(label, code) in locales"
                                :key="code"
                                :value="code"
                                >{{ label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.workspace_locale" />
                </div>
                <div class="grid gap-2">
                    <Label for="timezone">{{ t('Time zone') }}</Label>
                    <Select v-model="form.timezone">
                        <SelectTrigger id="timezone"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent class="max-h-72">
                            <SelectItem
                                v-for="zone in timezones"
                                :key="zone"
                                :value="zone"
                                >{{ zone }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.timezone" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="registration">{{
                    t('Who can create an account?')
                }}</Label>
                <Select v-model="form.registration">
                    <SelectTrigger id="registration"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="mode in registrationModes"
                            :key="mode"
                            :value="mode"
                            >{{ t(modeLabels[mode] ?? mode) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.registration" />
            </div>
        </template>

        <Button
            type="submit"
            class="w-full"
            :disabled="form.processing"
            data-test="onboarding-submit"
        >
            <Spinner v-if="form.processing" />
            {{ t('Continue') }}
        </Button>
    </form>
</template>
