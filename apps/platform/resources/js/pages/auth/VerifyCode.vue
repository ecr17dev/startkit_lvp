<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CodeCountdown from '@/components/CodeCountdown.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@starter/ui/components/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@starter/ui/components/input-otp';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import verifyRoutes from '@/routes/login/verify';

defineOptions({
    layout: {
        title: 'Check your email',
        description: 'Enter the code we sent to your email address',
    },
});

const props = defineProps<{
    email: string;
    purpose: 'login' | 'register' | 'invitation';
    codeLength: number;
    expiresInMinutes: number;
    resendAvailableIn: number;
}>();

const code = ref('');

const submitLabel = computed(() =>
    props.purpose === 'login' ? t('Log in') : t('Verify and continue'),
);
</script>

<template>
    <Head :title="t('Enter your code')" />

    <Form
        v-bind="verifyRoutes.store.form()"
        class="space-y-6"
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
                id="otp-code"
                v-model="code"
                :maxlength="codeLength"
                :disabled="processing"
                autofocus
                inputmode="numeric"
                autocomplete="one-time-code"
                data-test="otp-code-input"
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
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'The code expires in :minutes minutes and can only be used once.',
                        { minutes: expiresInMinutes },
                    )
                }}
            </p>
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing || code.length < codeLength"
            data-test="verify-code-button"
        >
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>

    <div
        class="mt-6 flex flex-col items-center gap-2 text-sm text-muted-foreground"
    >
        <CodeCountdown :seconds="resendAvailableIn" v-slot="{ left }">
            <Form v-bind="verifyRoutes.resend.form()" v-slot="{ processing }">
                <button
                    type="submit"
                    class="text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current! disabled:no-underline disabled:opacity-50 dark:decoration-neutral-500"
                    :disabled="processing || left > 0"
                    data-test="resend-code-button"
                >
                    {{
                        left > 0
                            ? t('You can request a new code in :seconds s', {
                                  seconds: left,
                              })
                            : t("Didn't get it? Send a new code")
                    }}
                </button>
            </Form>
        </CodeCountdown>
        <TextLink :href="login()">{{ t('Use another email') }}</TextLink>
    </div>
</template>
