<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    codeLength: number;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'We will email you a code to confirm your address',
    },
});
</script>

<template>
    <Head :title="t('Register')" />

    <Form
        v-bind="store.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }}</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    autocomplete="name"
                    name="name"
                    :placeholder="t('Full name')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'You will receive a :length digit code. No password needed.',
                            { length: codeLength },
                        )
                    }}
                </p>
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{ t('Create account') }}
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            {{ t('Already have an account?') }}
            <TextLink :href="login()" class="underline underline-offset-4">{{
                t('Log in')
            }}</TextLink>
        </div>
    </Form>
</template>
