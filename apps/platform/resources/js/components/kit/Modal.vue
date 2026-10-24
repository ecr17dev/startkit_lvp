<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@starter/ui/components/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@starter/ui/components/drawer';

/**
 * Modal window: a centered dialog on wide screens and a bottom sheet
 * (drawer) on phones. Open it with the `trigger` slot or v-model:open.
 *
 * <Modal v-model:open="open" :title="t('Invite user')" :description="...">
 *     <template #trigger><Button>{{ t('Invite') }}</Button></template>
 *     ...content...
 *     <template #footer><SubmitButton form="invite">{{ t('Send') }}</SubmitButton></template>
 * </Modal>
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        /** Use a drawer below 768 px. */
        responsive?: boolean;
    }>(),
    { description: '', size: 'md', responsive: true },
);

const open = defineModel<boolean>('open', { default: false });

const wide = useMediaQuery('(min-width: 768px)');
const asDrawer = computed(() => props.responsive && !wide.value);

const sizes = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
} as const;
</script>

<template>
    <Drawer v-if="asDrawer" v-model:open="open">
        <DrawerTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DrawerTrigger>
        <DrawerContent>
            <DrawerHeader class="text-left">
                <DrawerTitle>{{ title }}</DrawerTitle>
                <DrawerDescription v-if="description">
                    {{ description }}
                </DrawerDescription>
            </DrawerHeader>
            <div class="overflow-y-auto px-4">
                <slot />
            </div>
            <DrawerFooter v-if="$slots.footer" class="flex-col-reverse">
                <slot name="footer" />
            </DrawerFooter>
        </DrawerContent>
    </Drawer>

    <Dialog v-else v-model:open="open">
        <DialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>
        <DialogContent :class="sizes[size]">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>
            <div class="max-h-[65vh] overflow-y-auto">
                <slot />
            </div>
            <DialogFooter v-if="$slots.footer">
                <slot name="footer" />
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
