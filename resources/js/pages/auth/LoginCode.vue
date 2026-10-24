<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { store as resend, verify } from '@/routes/login-code';

defineOptions({
    layout: {
        title: 'Check your email',
        description: 'Enter the code we sent to your email address',
    },
});

const props = defineProps<{
    email: string;
    codeLength: number;
    status?: string;
}>();

const code = ref('');
</script>

<template>
    <Head :title="t('Enter your login code')" />

    <div
        v-if="status"
        class="mb-2 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="verify.form()"
        class="space-y-6"
        reset-on-error
        @error="code = ''"
        v-slot="{ errors, processing }"
    >
        <input type="hidden" name="code" :value="code" />

        <div class="flex flex-col items-center gap-3 text-center">
            <p class="text-sm text-muted-foreground">
                {{ t('Code sent to') }}
                <span class="font-medium text-foreground">{{
                    props.email
                }}</span>
            </p>
            <InputOTP
                id="login-code"
                v-model="code"
                :maxlength="codeLength"
                :disabled="processing"
                autofocus
                data-test="login-code-input"
            >
                <InputOTPGroup>
                    <InputOTPSlot
                        v-for="index in codeLength"
                        :key="index"
                        :index="index - 1"
                    />
                </InputOTPGroup>
            </InputOTP>
            <InputError :message="errors.code" />
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing || code.length < codeLength"
            data-test="verify-login-code-button"
        >
            <Spinner v-if="processing" />
            {{ t('Log in') }}
        </Button>
    </Form>

    <div
        class="mt-6 flex flex-col items-center gap-2 text-sm text-muted-foreground"
    >
        <Form v-bind="resend.form()" v-slot="{ processing }">
            <input type="hidden" name="email" :value="props.email" />
            <button
                type="submit"
                class="text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current! disabled:opacity-50 dark:decoration-neutral-500"
                :disabled="processing"
            >
                {{ t("Didn't get it? Send a new code") }}
            </button>
        </Form>
        <TextLink :href="login()">{{ t('Use another email') }}</TextLink>
    </div>
</template>
