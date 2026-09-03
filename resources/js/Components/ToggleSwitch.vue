<script setup lang="ts">
defineProps<{ modelValue: boolean; disabled?: boolean }>();
defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-45"
        :class="modelValue ? 'border-silver-400 bg-silver-300' : 'border-line-strong bg-surface-input'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="inline-block size-4 rounded-full transition-transform"
            :class="modelValue ? 'translate-x-6 bg-surface-base' : 'translate-x-1 bg-silver-600'"
        />
    </button>
</template>
