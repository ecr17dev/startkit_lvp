<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, ExternalLink, KeyRound, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, t } from '@/lib/i18n';
import { destroy, index, store } from '@/routes/api-tokens';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
};

const props = defineProps<{
    tokens: Token[];
    abilities: string[];
    plainTextToken: string | null;
    docsUrl: string | null;
}>();

const form = useForm({
    name: '',
    abilities: [...props.abilities] as string[],
    expires_in_days: '' as string | number,
});

const toggleAbility = (ability: string, checked: boolean | 'indeterminate') => {
    form.abilities = checked
        ? [...new Set([...form.abilities, ability])]
        : form.abilities.filter((item) => item !== ability);
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        expires_in_days:
            data.expires_in_days === '' ? null : data.expires_in_days,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'expires_in_days'),
    });
};

const revoke = (token: Token) => {
    router.delete(destroy(token.id).url, { preserveScroll: true });
};

const { copy, copied } = useClipboard();

const abilityDescriptions: Record<string, string> = {
    read: 'Read data (GET requests)',
    write: 'Create, update and delete data',
    ai: 'Use the AI endpoints',
};
</script>

<template>
    <Head :title="t('API tokens')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('API tokens')"
            :description="
                t(
                    'Personal access tokens let other applications use the API on your behalf.',
                )
            "
        />

        <Alert v-if="plainTextToken" class="border-green-600/40">
            <KeyRound class="size-4" />
            <AlertTitle>{{ t('Copy your new token now') }}</AlertTitle>
            <AlertDescription class="space-y-2">
                <p>
                    {{ t('For security reasons it will not be shown again.') }}
                </p>
                <div class="flex w-full items-center gap-2">
                    <code
                        class="flex-1 overflow-x-auto rounded bg-muted px-2 py-1.5 font-mono text-xs"
                        data-test="plain-text-token"
                        >{{ plainTextToken }}</code
                    >
                    <Button
                        size="icon"
                        variant="outline"
                        type="button"
                        @click="copy(plainTextToken)"
                    >
                        <Check v-if="copied" class="size-4 text-green-600" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="token-name">{{ t('Token name') }}</Label>
                <Input
                    id="token-name"
                    v-model="form.name"
                    :placeholder="t('e.g. Mobile app, Zapier, CI')"
                    required
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label>{{ t('Abilities') }}</Label>
                <div class="grid gap-2 sm:grid-cols-3">
                    <Label
                        v-for="ability in abilities"
                        :key="ability"
                        class="flex items-start gap-2 rounded-md border p-3 font-normal"
                    >
                        <Checkbox
                            :model-value="form.abilities.includes(ability)"
                            @update:model-value="
                                (checked) => toggleAbility(ability, checked)
                            "
                        />
                        <span class="grid gap-0.5">
                            <span class="font-medium">{{ ability }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(abilityDescriptions[ability] ?? ability)
                            }}</span>
                        </span>
                    </Label>
                </div>
                <InputError :message="form.errors.abilities" />
            </div>

            <div class="grid gap-2 sm:w-64">
                <Label for="token-expiration">{{
                    t('Expires in (days)')
                }}</Label>
                <Input
                    id="token-expiration"
                    v-model="form.expires_in_days"
                    type="number"
                    min="1"
                    :placeholder="t('Never')"
                />
                <InputError :message="form.errors.expires_in_days" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ t('Create token') }}
                </Button>
                <Button v-if="docsUrl" variant="link" as-child>
                    <a :href="docsUrl" target="_blank" rel="noopener">
                        <ExternalLink class="size-4" />
                        {{ t('API documentation') }}
                    </a>
                </Button>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        <Heading variant="small" :title="t('Active tokens')" />

        <p v-if="!tokens.length" class="text-sm text-muted-foreground">
            {{ t('You have not created any token yet.') }}
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="min-w-0 space-y-1">
                    <p class="truncate font-medium">{{ token.name }}</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="ability in token.abilities"
                            :key="ability"
                            variant="secondary"
                            >{{ ability }}</Badge
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Created') }} {{ formatDate(token.created_at) }} ·
                        {{ t('Last used') }}
                        {{
                            token.last_used_at
                                ? formatDate(token.last_used_at)
                                : t('Never')
                        }}
                        <template v-if="token.expires_at">
                            · {{ t('Expires') }}
                            {{ formatDate(token.expires_at) }}
                        </template>
                    </p>
                </div>
                <ConfirmDialog
                    :title="t('Revoke token')"
                    :description="
                        t(
                            'Applications using this token will no longer be able to access the API.',
                        )
                    "
                    :confirm-text="t('Revoke')"
                    @confirm="revoke(token)"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-destructive"
                        :aria-label="t('Revoke')"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </ConfirmDialog>
            </li>
        </ul>
    </div>
</template>
