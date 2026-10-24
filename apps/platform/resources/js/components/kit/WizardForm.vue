<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowLeft, ArrowRight, Check, CircleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@starter/ui/components/button';
import { Progress } from '@starter/ui/components/progress';
import { Spinner } from '@starter/ui/components/spinner';
import {
    Stepper,
    StepperDescription,
    StepperItem,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
} from '@starter/ui/components/stepper';
import { t } from '@/lib/i18n';

export type WizardStep = {
    /** Name of the slot with the fields of the step. */
    key: string;
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** Fields of the step: server errors on them bring the user back here. */
    fields?: string[];
};

/**
 * Form split in steps. Each step is a slot named after its key; "Next"
 * runs `validate` for the current step, the last one emits `submit` (send
 * the whole Inertia form there). When the server answers with errors, the
 * wizard returns to the first step with an invalid field.
 *
 * <WizardForm :steps="steps" :errors="form.errors" :processing="form.processing" @submit="form.post(url)">
 *     <template #account>...</template>
 *     <template #plan>...</template>
 * </WizardForm>
 */
const props = withDefaults(
    defineProps<{
        steps: WizardStep[];
        errors?: Partial<Record<string, string>>;
        processing?: boolean;
        /** Client-side check before leaving a step (return false to stay). */
        validate?: (
            step: WizardStep,
            index: number,
        ) => boolean | Promise<boolean>;
        submitText?: string;
    }>(),
    {
        errors: () => ({}),
        processing: false,
        validate: undefined,
        submitText: '',
    },
);

const emit = defineEmits<{ submit: [] }>();

/** Index of the current step (0-based), optional v-model:step. */
const current = defineModel<number>('step', { default: 0 });

defineSlots<
    Record<string, (props: { step: WizardStep; index: number }) => unknown>
>();

const checking = ref(false);
const isLast = computed(() => current.value === props.steps.length - 1);
const step = computed(() => props.steps[current.value]);

const errorFields = computed(() =>
    Object.keys(props.errors).filter((key) => props.errors[key]),
);

const hasErrors = (item: WizardStep) =>
    errorFields.value.some((key) =>
        (item.fields ?? []).some(
            (field) => key === field || key.startsWith(`${field}.`),
        ),
    );

// Server errors: go back to the first step that has one...
watch(errorFields, (fields) => {
    if (fields.length === 0) {
        return;
    }

    const index = props.steps.findIndex(hasErrors);

    if (index !== -1 && index < current.value) {
        current.value = index;
    }
});

async function passes(index: number): Promise<boolean> {
    if (!props.validate) {
        return true;
    }

    checking.value = true;

    try {
        return await props.validate(props.steps[index], index);
    } finally {
        checking.value = false;
    }
}

async function advance() {
    if (props.processing || checking.value) {
        return;
    }

    if (!(await passes(current.value))) {
        return;
    }

    if (isLast.value) {
        emit('submit');
    } else {
        current.value++;
    }
}

function goTo(index: number) {
    // Going back is free; going forward always passes through "Next"...
    if (index < current.value) {
        current.value = index;
    }
}
</script>

<template>
    <form class="grid gap-8" novalidate @submit.prevent="advance">
        <Stepper
            :model-value="current + 1"
            :linear="false"
            class="hidden w-full items-start gap-2 md:flex"
            @update:model-value="(value) => goTo(Number(value) - 1)"
        >
            <StepperItem
                v-for="(item, index) in steps"
                :key="item.key"
                :step="index + 1"
                :completed="index < current"
                :disabled="index > current"
                class="relative flex w-full flex-col items-center justify-start"
            >
                <StepperSeparator
                    v-if="index < steps.length - 1"
                    class="absolute top-5 right-[calc(-50%+24px)] left-[calc(50%+24px)] block h-0.5 shrink-0 rounded-full bg-muted group-data-[state=completed]:bg-primary"
                />
                <StepperTrigger as-child>
                    <Button
                        type="button"
                        size="icon"
                        :variant="index <= current ? 'default' : 'outline'"
                        class="z-10 size-10 shrink-0 rounded-full"
                        :class="[
                            index === current &&
                                'ring-2 ring-ring ring-offset-2 ring-offset-background',
                            hasErrors(item) &&
                                'bg-destructive text-white hover:bg-destructive/90',
                        ]"
                        :aria-label="item.title"
                    >
                        <CircleAlert v-if="hasErrors(item)" class="size-5" />
                        <Check v-else-if="index < current" class="size-5" />
                        <component
                            :is="item.icon"
                            v-else-if="item.icon"
                            class="size-5"
                        />
                        <span v-else>{{ index + 1 }}</span>
                    </Button>
                </StepperTrigger>
                <div
                    class="mt-3 flex flex-col items-center gap-0.5 text-center"
                >
                    <StepperTitle
                        class="text-sm font-semibold"
                        :class="index !== current && 'text-muted-foreground'"
                    >
                        {{ item.title }}
                    </StepperTitle>
                    <StepperDescription
                        v-if="item.description"
                        class="hidden text-xs text-muted-foreground lg:block"
                    >
                        {{ item.description }}
                    </StepperDescription>
                </div>
            </StepperItem>
        </Stepper>

        <div class="grid gap-2 md:hidden">
            <p class="text-xs text-muted-foreground">
                {{
                    t('Step :current of :total', {
                        current: current + 1,
                        total: steps.length,
                    })
                }}
            </p>
            <p class="font-semibold">{{ step?.title }}</p>
            <Progress :model-value="((current + 1) / steps.length) * 100" />
        </div>

        <div v-if="step" :key="step.key" class="grid gap-6">
            <slot :name="step.key" :step="step" :index="current" />
        </div>

        <div class="flex items-center justify-between gap-2 border-t pt-6">
            <Button
                v-if="current > 0"
                type="button"
                variant="outline"
                :disabled="processing"
                @click="current--"
            >
                <ArrowLeft class="size-4" />
                {{ t('Back') }}
            </Button>
            <span v-else />

            <Button type="submit" :disabled="processing || checking">
                <Spinner v-if="processing || checking" />
                <template v-if="isLast">
                    {{ submitText || t('Submit') }}
                </template>
                <template v-else>
                    {{ t('Next') }}
                    <ArrowRight class="size-4" />
                </template>
            </Button>
        </div>
    </form>
</template>
