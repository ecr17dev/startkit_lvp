<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays, Info, Trash2, UserPlus } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/kit/FormField.vue';
import Modal from '@/components/kit/Modal.vue';
import SubmitButton from '@/components/kit/SubmitButton.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Avatar, AvatarFallback } from '@starter/ui/components/avatar';
import { Button } from '@starter/ui/components/button';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@starter/ui/components/hover-card';
import { Input } from '@starter/ui/components/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@starter/ui/components/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@starter/ui/components/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@starter/ui/components/tooltip';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Modals', href: show('overlays') },
        ],
    },
});

const open = ref(false);
const form = useForm({ email: '', role: 'editor' });

// A real modal posts the form and closes on success:
//   form.post(store().url, { onSuccess: () => (open.value = false) })
function invite() {
    form.clearErrors();

    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) {
        form.setError('email', t('Enter a valid email address.'));

        return;
    }

    open.value = false;
    notify.success(
        t('Invitation sent to :email (demo).', { email: form.email }),
    );
    form.reset();
}

const modalCode = `
<Modal v-model:open="open" :title="t('Invite user')" :description="t('...')">
    <template #trigger><Button><UserPlus class="size-4" /> {{ t('Invite user') }}</Button></template>

    <form id="invite" class="grid gap-4" @submit.prevent="form.post(store().url, { onSuccess: () => (open = false) })">
        <FormField :label="t('Email')" :error="form.errors.email" required v-slot="field">
            <Input v-model="form.email" type="email" v-bind="field" />
        </FormField>
    </form>

    <template #footer>
        <Button variant="outline" @click="open = false">{{ t('Cancel') }}</Button>
        <SubmitButton form="invite" :processing="form.processing">{{ t('Send invitation') }}</SubmitButton>
    </template>
</Modal>
`;

const confirmCode = `
<ConfirmDialog :title="t('Delete project')" :description="t('This cannot be undone.')"
    :confirm-text="t('Delete')" @confirm="remove(project)">
    <Button variant="destructive">{{ t('Delete') }}</Button>
</ConfirmDialog>
`;
</script>

<template>
    <Head :title="t('Modals')" />

    <Example
        :title="t('Modal with a form')"
        :description="
            t(
                'Centered dialog on wide screens and bottom sheet on phones. The footer button submits the form through its id.',
            )
        "
        :code="modalCode"
    >
        <Modal
            v-model:open="open"
            :title="t('Invite user')"
            :description="
                t('We will send a sign in code to the email address.')
            "
        >
            <template #trigger>
                <Button
                    ><UserPlus class="size-4" /> {{ t('Invite user') }}</Button
                >
            </template>

            <form
                id="invite-demo"
                class="grid gap-4 py-1"
                novalidate
                @submit.prevent="invite"
            >
                <FormField
                    v-slot="field"
                    :label="t('Email')"
                    :error="form.errors.email"
                    required
                >
                    <Input v-model="form.email" v-bind="field" type="email" />
                </FormField>
                <FormField v-slot="field" :label="t('Role')">
                    <Select v-model="form.role">
                        <SelectTrigger v-bind="field" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="admin">Admin</SelectItem>
                            <SelectItem value="editor">Editor</SelectItem>
                            <SelectItem value="reader">{{
                                t('Reader')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
            </form>

            <template #footer>
                <Button variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <SubmitButton form="invite-demo" :processing="form.processing">
                    {{ t('Send invitation') }}
                </SubmitButton>
            </template>
        </Modal>
    </Example>

    <Example
        :title="t('Confirmation')"
        :description="
            t(
                'Ask before destructive actions, naming exactly what will happen.',
            )
        "
        :code="confirmCode"
    >
        <ConfirmDialog
            :title="t('Delete project')"
            :description="
                t('The project will be deleted. This cannot be undone.')
            "
            :confirm-text="t('Delete')"
            @confirm="notify.success(t('Deleted (demo).'))"
        >
            <Button variant="destructive">
                <Trash2 class="size-4" /> {{ t('Delete project') }}
            </Button>
        </ConfirmDialog>
    </Example>

    <Example
        :title="t('Side panel')"
        :description="
            t(
                'Sheet: details or filters next to a list, without leaving the page.',
            )
        "
    >
        <Sheet>
            <SheetTrigger as-child>
                <Button variant="outline">{{ t('Open panel') }}</Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>{{ t('Filters') }}</SheetTitle>
                    <SheetDescription>{{
                        t('Narrow the list down.')
                    }}</SheetDescription>
                </SheetHeader>
                <div class="grid gap-4 px-4">
                    <FormField v-slot="field" :label="t('Name')">
                        <Input v-bind="field" />
                    </FormField>
                </div>
                <SheetFooter>
                    <Button>{{ t('Apply') }}</Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </Example>

    <Example
        :title="t('Popover, hover card and tooltip')"
        :description="
            t(
                'Small floating content: a popover holds controls, a hover card previews, a tooltip names an icon.',
            )
        "
    >
        <div class="flex flex-wrap items-center gap-3">
            <Popover>
                <PopoverTrigger as-child>
                    <Button variant="outline">
                        <CalendarDays class="size-4" /> {{ t('Schedule') }}
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="grid w-72 gap-3">
                    <p class="text-sm font-medium">{{ t('Send later') }}</p>
                    <Input type="datetime-local" />
                    <Button size="sm">{{ t('Schedule') }}</Button>
                </PopoverContent>
            </Popover>

            <HoverCard>
                <HoverCardTrigger as-child>
                    <Button variant="link">@ana.perez</Button>
                </HoverCardTrigger>
                <HoverCardContent class="w-64">
                    <div class="flex gap-3">
                        <Avatar><AvatarFallback>AP</AvatarFallback></Avatar>
                        <div class="text-sm">
                            <p class="font-medium">Ana Pérez</p>
                            <p class="text-muted-foreground">
                                {{ t('Editor since March 2026') }}
                            </p>
                        </div>
                    </div>
                </HoverCardContent>
            </HoverCard>

            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="t('More information')"
                        >
                            <Info class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{ t('More information') }}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </Example>
</template>
