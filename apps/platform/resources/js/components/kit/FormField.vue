<script setup lang="ts">
import { computed, useId } from 'vue';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@starter/ui/components/field';
import { t } from '@/lib/i18n';

/**
 * Label, control, help text and the validation error of one field. The
 * slot receives the ids to wire the control (works with form.errors of
 * Inertia's useForm):
 *
 * <FormField :label="t('Name')" :error="form.errors.name" required v-slot="field">
 *     <Input v-model="form.name" v-bind="field" />
 * </FormField>
 *
 * `horizontal` puts a checkbox or switch before its label.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        error?: string;
        description?: string;
        required?: boolean;
        /** Shows "(optional)" next to the label. */
        optional?: boolean;
        horizontal?: boolean;
        id?: string;
    }>(),
    {
        error: undefined,
        description: undefined,
        required: false,
        optional: false,
        horizontal: false,
        id: undefined,
    },
);

const generated = useId();
const controlId = computed(() => props.id ?? `field-${generated}`);

const bindings = computed(() => ({
    id: controlId.value,
    'aria-invalid': props.error ? true : undefined,
    'aria-describedby':
        [
            props.description ? `${controlId.value}-description` : null,
            props.error ? `${controlId.value}-error` : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
    'aria-required': props.required || undefined,
}));
</script>

<template>
    <Field
        :orientation="horizontal ? 'horizontal' : 'vertical'"
        :data-invalid="error ? true : undefined"
    >
        <template v-if="horizontal">
            <slot v-bind="bindings" />
            <FieldContent>
                <FieldLabel :for="controlId" class="font-normal">
                    {{ label }}
                </FieldLabel>
                <FieldDescription
                    v-if="description"
                    :id="`${controlId}-description`"
                >
                    {{ description }}
                </FieldDescription>
                <FieldError v-if="error" :id="`${controlId}-error`">
                    {{ error }}
                </FieldError>
            </FieldContent>
        </template>

        <template v-else>
            <FieldLabel :for="controlId">
                {{ label }}
                <span
                    v-if="required"
                    class="text-destructive"
                    aria-hidden="true"
                    >*</span
                >
                <span
                    v-else-if="optional"
                    class="font-normal text-muted-foreground"
                    >({{ t('optional') }})</span
                >
            </FieldLabel>
            <slot v-bind="bindings" />
            <FieldDescription
                v-if="description"
                :id="`${controlId}-description`"
            >
                {{ description }}
            </FieldDescription>
            <FieldError v-if="error" :id="`${controlId}-error`">
                {{ error }}
            </FieldError>
        </template>
    </Field>
</template>
