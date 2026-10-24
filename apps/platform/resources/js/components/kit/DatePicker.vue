<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import { parseDate } from '@internationalized/date';
import { CalendarDays, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@starter/ui/components/button';
import { Calendar } from '@starter/ui/components/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@starter/ui/components/popover';
import { cn } from '@/lib/utils';
import { currentLocale, formatDate, t } from '@/lib/i18n';

/**
 * Date field with a calendar. The model is the ISO date the backend
 * validates ("2026-10-24") or null; attributes (id, aria-*) go to the
 * trigger, so it works inside FormField.
 *
 * <DatePicker v-model="form.starts_on" min="2026-01-01" />
 */
defineOptions({ inheritAttrs: false });

const model = defineModel<string | null | undefined>({ default: null });

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        min?: string;
        max?: string;
        clearable?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    {
        placeholder: '',
        min: undefined,
        max: undefined,
        clearable: true,
        disabled: false,
        class: '',
    },
);

const open = ref(false);

const parse = (value: string | null | undefined): DateValue | undefined => {
    try {
        return value ? parseDate(value.slice(0, 10)) : undefined;
    } catch {
        return undefined;
    }
};

const selected = computed({
    get: () => parse(model.value),
    set: (value: DateValue | undefined) => {
        model.value = value ? value.toString() : null;
        open.value = false;
    },
});

const text = computed(() =>
    model.value
        ? formatDate(`${model.value.slice(0, 10)}T12:00:00`, {
              dateStyle: 'medium',
          })
        : props.placeholder || t('Pick a date'),
);
</script>

<template>
    <Popover v-model:open="open">
        <div :class="cn('relative w-full sm:w-56', props.class)">
            <PopoverTrigger as-child>
                <Button
                    v-bind="$attrs"
                    type="button"
                    variant="outline"
                    :disabled="disabled"
                    class="w-full justify-start font-normal aria-invalid:border-destructive"
                    :class="[
                        !model && 'text-muted-foreground',
                        clearable && model && 'pr-9',
                    ]"
                >
                    <CalendarDays class="size-4" />
                    {{ text }}
                </Button>
            </PopoverTrigger>
            <Button
                v-if="clearable && model && !disabled"
                type="button"
                variant="ghost"
                size="icon-sm"
                class="absolute top-1/2 right-0.5 size-7 -translate-y-1/2"
                :aria-label="t('Clear')"
                @click="model = null"
            >
                <X class="size-3.5" />
            </Button>
        </div>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                v-model="selected"
                :locale="currentLocale()"
                :min-value="parse(min)"
                :max-value="parse(max)"
                :default-placeholder="selected"
                layout="month-and-year"
                initial-focus
            />
        </PopoverContent>
    </Popover>
</template>
