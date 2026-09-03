<script setup lang="ts">
import { useId } from 'vue';
import InputError from './InputError.vue';

defineProps<{ modelValue: boolean; error?: string | null }>();
defineEmits<{ 'update:modelValue': [value: boolean] }>();

const id = useId();
</script>

<template>
    <div>
        <div class="flex items-start gap-2.5">
            <input
                :id="id"
                type="checkbox"
                :checked="modelValue"
                class="mt-0.5 size-4 rounded border-line-strong bg-surface-input text-silver-200 transition focus:ring-2 focus:ring-silver-400/40 focus:ring-offset-0"
                @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
            />
            <label :for="id" class="text-[13px] leading-relaxed text-content-muted">
                <slot />
            </label>
        </div>
        <InputError :message="error" />
    </div>
</template>
