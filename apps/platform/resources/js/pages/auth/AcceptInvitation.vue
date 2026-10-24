<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { MailX } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { Spinner } from '@starter/ui/components/spinner';
import { formatDate, t } from '@/lib/i18n';
import { login } from '@/routes';
import { accept } from '@/routes/invitations';

defineOptions({
    layout: {
        title: 'Accept your invitation',
        description: 'Confirm your email with a one-time code to join',
    },
});

defineProps<{
    token: string;
    invitation: {
        email: string;
        role: string;
        inviter: string | null;
        expires_at: string;
    } | null;
}>();
</script>

<template>
    <Head :title="t('Accept invitation')" />

    <div
        v-if="!invitation"
        class="flex flex-col items-center gap-4 text-center"
        data-test="invalid-invitation"
    >
        <MailX class="size-10 text-muted-foreground" />
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'This invitation is invalid, expired or was already used. Ask for a new one.',
                )
            }}
        </p>
        <TextLink :href="login()">{{ t('Go to log in') }}</TextLink>
    </div>

    <Form
        v-else
        v-bind="accept.form(token)"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="rounded-lg border bg-muted/40 p-4 text-sm">
            <p v-if="invitation.inviter">
                {{ t(':name invited you.', { name: invitation.inviter }) }}
            </p>
            <p class="mt-1 flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ invitation.email }}</span>
                <Badge variant="secondary">{{ invitation.role }}</Badge>
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{
                    t('Expires on :date', {
                        date: formatDate(invitation.expires_at),
                    })
                }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="name">{{ t('Your name') }}</Label>
            <Input
                id="name"
                name="name"
                required
                v-focus
                autocomplete="name"
                :placeholder="t('Full name')"
            />
            <InputError :message="errors.name" />
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="accept-invitation-button"
        >
            <Spinner v-if="processing" />
            {{ t('Send me a code') }}
        </Button>
    </Form>
</template>
