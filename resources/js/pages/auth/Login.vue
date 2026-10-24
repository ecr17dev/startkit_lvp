<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { KeyRound, Mail } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { t } from '@/lib/i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { store as sendCode } from '@/routes/login-code';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Choose how you want to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    codeLength: number;
}>();

const page = usePage();
const features = computed(() => page.props.features);
const method = ref<string>(features.value.codeLogin ? 'code' : 'password');
const email = ref('');
</script>

<template>
    <Head :title="t('Log in')" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <PasskeyVerify
        :label="t('Sign in with a passkey')"
        :loading-label="t('Authenticating...')"
        :separator="t('Or continue with')"
    />

    <Tabs v-model="method" class="gap-6">
        <TabsList
            v-if="features.codeLogin && features.passwordLogin"
            class="grid w-full grid-cols-2"
        >
            <TabsTrigger value="code" data-test="login-code-tab">
                <Mail class="size-4" />
                {{ t('Email code') }}
            </TabsTrigger>
            <TabsTrigger value="password" data-test="login-password-tab">
                <KeyRound class="size-4" />
                {{ t('Password') }}
            </TabsTrigger>
        </TabsList>

        <TabsContent v-if="features.codeLogin" value="code">
            <Form
                v-bind="sendCode.form()"
                v-slot="{ errors, processing }"
                class="grid gap-6"
            >
                <div class="grid gap-2">
                    <Label for="code-email">{{ t('Email address') }}</Label>
                    <Input
                        id="code-email"
                        v-model="email"
                        type="email"
                        name="email"
                        required
                        v-focus
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'We will email you a :length digit code to log in without a password.',
                                { length: codeLength },
                            )
                        }}
                    </p>
                </div>

                <Label for="code-remember" class="flex items-center space-x-3">
                    <Checkbox id="code-remember" name="remember" />
                    <span>{{ t('Remember me') }}</span>
                </Label>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="send-login-code-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Send me a login code') }}
                </Button>
            </Form>
        </TabsContent>

        <TabsContent v-if="features.passwordLogin" value="password">
            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="email">{{ t('Email address') }}</Label>
                        <Input
                            id="email"
                            v-model="email"
                            type="email"
                            name="email"
                            required
                            :tabindex="1"
                            autocomplete="email"
                            placeholder="email@example.com"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">{{ t('Password') }}</Label>
                            <TextLink
                                v-if="canResetPassword"
                                :href="request()"
                                class="text-sm"
                                :tabindex="5"
                            >
                                {{ t('Forgot your password?') }}
                            </TextLink>
                        </div>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
                            :placeholder="t('Password')"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="flex items-center justify-between">
                        <Label
                            for="remember"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox
                                id="remember"
                                name="remember"
                                :tabindex="3"
                            />
                            <span>{{ t('Remember me') }}</span>
                        </Label>
                    </div>

                    <Button
                        type="submit"
                        class="mt-2 w-full"
                        :tabindex="4"
                        :disabled="processing"
                        data-test="login-button"
                    >
                        <Spinner v-if="processing" />
                        {{ t('Log in') }}
                    </Button>
                </div>
            </Form>
        </TabsContent>
    </Tabs>

    <div
        v-if="features.registration"
        class="mt-6 text-center text-sm text-muted-foreground"
    >
        {{ t("Don't have an account?") }}
        <TextLink :href="register()" :tabindex="5">{{ t('Sign up') }}</TextLink>
    </div>
</template>
