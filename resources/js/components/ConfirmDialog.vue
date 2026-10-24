<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { buttonVariants } from '@/components/ui/button';
import { t } from '@/lib/i18n';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmText?: string;
        destructive?: boolean;
    }>(),
    {
        description: '',
        confirmText: '',
        destructive: true,
    },
);

const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <AlertDialog>
        <AlertDialogTrigger as-child>
            <slot />
        </AlertDialogTrigger>
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription v-if="description">
                    {{ description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>{{ t('Cancel') }}</AlertDialogCancel>
                <AlertDialogAction
                    :class="
                        buttonVariants({
                            variant: destructive ? 'destructive' : 'default',
                        })
                    "
                    @click="emit('confirm')"
                >
                    {{ confirmText || t('Confirm') }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
