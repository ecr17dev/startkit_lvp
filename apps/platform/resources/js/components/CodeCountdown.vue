<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core';
import { ref, watch } from 'vue';

/**
 * Seconds left before an action (e.g. resending a code) is available again.
 */
const props = defineProps<{ seconds: number }>();

const left = ref(props.seconds);

watch(
    () => props.seconds,
    (value) => {
        left.value = value;
    },
);

useIntervalFn(() => {
    if (left.value > 0) {
        left.value--;
    }
}, 1000);

defineSlots<{ default(props: { left: number }): unknown }>();
</script>

<template>
    <slot :left="left" />
</template>
