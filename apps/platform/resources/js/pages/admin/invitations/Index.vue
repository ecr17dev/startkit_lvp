<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Ban, MailPlus, Send } from '@lucide/vue';
import { reactive } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
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
import { Spinner } from '@starter/ui/components/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatDate, t } from '@/lib/i18n';
import invitations from '@/routes/admin/invitations';
import { index as users } from '@/routes/admin/users';
import type { Invitation, InvitationStatus, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: users() },
            { title: 'Invitations', href: invitations.index() },
        ],
    },
});

const props = defineProps<{
    invitations: Paginated<Invitation>;
    roles: string[];
    filters: { status?: InvitationStatus };
    registrationMode: string;
    canInvite: boolean;
}>();

const form = useForm({
    email: '',
    role: props.roles.includes('reader') ? 'reader' : (props.roles[0] ?? ''),
});

const filters = reactive({ status: props.filters.status ?? 'all' });

watchDebounced(
    filters,
    () =>
        router.get(
            invitations.index().url,
            { status: filters.status === 'all' ? undefined : filters.status },
            { preserveState: true, preserveScroll: true, replace: true },
        ),
    { debounce: 150, deep: true },
);

const statusVariant: Record<
    InvitationStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'default',
    accepted: 'secondary',
    revoked: 'destructive',
    expired: 'outline',
};

const statusLabels: Record<InvitationStatus, string> = {
    pending: 'Pending',
    accepted: 'Accepted',
    revoked: 'Revoked',
    expired: 'Expired',
};

const invite = () =>
    form.post(invitations.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });

const resend = (invitation: Invitation) =>
    router.post(
        invitations.resend(invitation.id).url,
        {},
        { preserveScroll: true },
    );

const revoke = (invitation: Invitation) =>
    router.delete(invitations.destroy(invitation.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="t('Invitations')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Invitations')"
            :description="
                t(
                    'Invite people by email. They choose their name and confirm the address with a one-time code.',
                )
            "
        />

        <Alert v-if="registrationMode === 'closed'">
            <AlertDescription>
                {{
                    t(
                        'Registration is closed: invitations cannot be sent nor accepted. Change it in System settings → Authentication.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <form
            v-if="canInvite"
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end"
            @submit.prevent="invite"
        >
            <div class="grid gap-2">
                <Label for="invite-email">{{ t('Email address') }}</Label>
                <Input
                    id="invite-email"
                    v-model="form.email"
                    type="email"
                    required
                    placeholder="email@example.com"
                    data-test="invite-email"
                />
            </div>
            <div class="grid gap-2">
                <Label for="invite-role">{{ t('Role') }}</Label>
                <Select v-model="form.role">
                    <SelectTrigger id="invite-role" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                            >{{ role }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="invite-submit"
            >
                <Spinner v-if="form.processing" />
                <MailPlus v-else class="size-4" />
                {{ t('Send invitation') }}
            </Button>
            <InputError
                class="sm:col-span-3"
                :message="form.errors.email ?? form.errors.role"
            />
        </form>

        <div class="flex justify-end">
            <Select v-model="filters.status">
                <SelectTrigger class="sm:w-48"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All statuses') }}</SelectItem>
                    <SelectItem
                        v-for="(label, status) in statusLabels"
                        :key="status"
                        :value="status"
                        >{{ t(label) }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Email') }}</TableHead>
                        <TableHead>{{ t('Role') }}</TableHead>
                        <TableHead>{{ t('Status') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Invited by')
                        }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Expires')
                        }}</TableHead>
                        <TableHead class="w-24 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="invitation in props.invitations.data"
                        :key="invitation.id"
                    >
                        <TableCell class="font-medium">{{
                            invitation.email
                        }}</TableCell>
                        <TableCell
                            ><Badge variant="outline">{{
                                invitation.role
                            }}</Badge></TableCell
                        >
                        <TableCell>
                            <Badge
                                :variant="statusVariant[invitation.status]"
                                >{{ t(statusLabels[invitation.status]) }}</Badge
                            >
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                            >{{ invitation.invited_by ?? '—' }}</TableCell
                        >
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                            >{{ formatDate(invitation.expires_at) }}</TableCell
                        >
                        <TableCell class="text-right">
                            <div
                                v-if="
                                    canInvite &&
                                    ['pending', 'expired'].includes(
                                        invitation.status,
                                    )
                                "
                                class="flex justify-end gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="t('Send again')"
                                    :title="t('Send again')"
                                    @click="resend(invitation)"
                                >
                                    <Send class="size-4" />
                                </Button>
                                <ConfirmDialog
                                    :title="t('Revoke invitation')"
                                    :description="
                                        t(
                                            'The link sent to :email will stop working.',
                                            { email: invitation.email },
                                        )
                                    "
                                    :confirm-text="t('Revoke')"
                                    @confirm="revoke(invitation)"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive"
                                        :aria-label="t('Revoke')"
                                    >
                                        <Ban class="size-4" />
                                    </Button>
                                </ConfirmDialog>
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableEmpty
                        v-if="!props.invitations.data.length"
                        :colspan="6"
                    >
                        {{ t('No invitations yet.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="props.invitations.meta" />
    </div>
</template>
