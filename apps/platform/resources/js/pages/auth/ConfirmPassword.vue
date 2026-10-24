<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import CodeCountdown from '@/components/CodeCountdown.vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@starter/ui/components/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@starter/ui/components/input-otp';
import { Label } from '@starter/ui/components/label';
import { Separator } from '@starter/ui/components/separator';
import { Spinner } from '@starter/ui/components/spinner';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import { t } from '@/lib/i18n';
import confirm from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Confirm it is you',
        description:
            'This is a secure area of the application. Confirm your identity before continuing.',
    },
});

defineProps<{
    canUsePassword: boolean;
    codeSent: boolean;
    resendAvailableIn: number;
    codeLength: number;
    email: string | null;
}>();

const code = ref('');
</script>

<template>
    <Head :title="t('Confirm it is you')" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        :label="t('Confirm with passkey')"
        :loading-label="t('Confirming...')"
        :separator="t('Or confirm with a code')"
    />

    <div class="space-y-6">
        <Form
            v-if="!codeSent"
            v-bind="confirm.code.form()"
            v-slot="{ errors, processing }"
            class="space-y-3"
        >
            <p class="text-center text-sm text-muted-foreground">
                {{
                    t('We will send a one-time code to :email.', {
                        email: email ?? '',
                    })
                }}
            </p>
            <InputError :message="errors.code" />
            <Button
                class="w-full"
                :disabled="processing"
                data-test="send-confirmation-code-button"
            >
                <Spinner v-if="processing" />
                {{ t('Send me a code') }}
            </Button>
        </Form>

        <template v-else>
            <Form
                v-bind="confirm.code.verify.form()"
                class="space-y-4"
                @error="code = ''"
                v-slot="{ errors, processing }"
            >
                <input type="hidden" name="code" :value="code" />
                <div class="flex flex-col items-center gap-3">
                    <p class="text-sm text-muted-foreground">
                        {{ t('Code sent to') }}
                        <span class="font-medium text-foreground">{{
                            email
                        }}</span>
                    </p>
                    <InputOTP
                        v-model="code"
                        :maxlength="codeLength"
                        :disabled="processing"
                        autofocus
                        autocomplete="one-time-code"
                        data-test="confirmation-code-input"
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
                    class="w-full"
                    :disabled="processing || code.length < codeLength"
                    data-test="confirm-code-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Confirm') }}
                </Button>
            </Form>

            <CodeCountdown :seconds="resendAvailableIn" v-slot="{ left }">
                <Form
                    v-bind="confirm.code.form()"
                    v-slot="{ processing }"
                    class="text-center text-sm"
                >
                    <button
                        type="submit"
                        class="underline underline-offset-4 disabled:no-underline disabled:opacity-50"
                        :disabled="processing || left > 0"
                    >
                        {{
                            left > 0
                                ? t(
                                      'You can request a new code in :seconds s',
                                      {
                                          seconds: left,
                                      },
                                  )
                                : t("Didn't get it? Send a new code")
                        }}
                    </button>
                </Form>
            </CodeCountdown>
        </template>

        <template v-if="canUsePassword">
            <div class="relative">
                <Separator />
                <span
                    class="absolute inset-x-0 -top-2.5 mx-auto w-fit bg-background px-2 text-xs text-muted-foreground"
                    >{{ t('Or use your password') }}</span
                >
            </div>

            <Form
                v-bind="confirm.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="space-y-4"
            >
                <div class="grid gap-2">
                    <Label for="password">{{ t('Password') }}</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    />
                    <InputError :message="errors.password" />
                </div>
                <Button
                    variant="outline"
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Confirm password') }}
                </Button>
            </Form>
        </template>
    </div>
</template>
