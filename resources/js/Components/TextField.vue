<script setup lang="ts">
import { computed, useId } from 'vue';
import InputError from './InputError.vue';

const props = withDefaults(
    defineProps<{
        label: string;
        modelValue: string;
        type?: string;
        error?: string | null;
        hint?: string;
        placeholder?: string;
        autocomplete?: string;
        required?: boolean;
        disabled?: boolean;
        /** Transforma o valor a cada digitação (ex.: máscara de telefone). */
        transform?: (value: string) => string;
    }>(),
    { type: 'text', required: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();
const hasError = computed(() => Boolean(props.error));

function onInput(event: Event) {
    const raw = (event.target as HTMLInputElement).value;
    emit('update:modelValue', props.transform ? props.transform(raw) : raw);
}
</script>

<template>
    <div>
        <div class="mb-1.5 flex items-baseline justify-between gap-3">
            <label :for="id" class="text-[13px] font-medium text-content">
                {{ label }}
                <span v-if="required" class="text-silver-400" aria-hidden="true">*</span>
            </label>
            <slot name="action" />
        </div>

        <div class="relative">
            <span
                v-if="$slots.icon"
                class="pointer-events-none absolute inset-y-0 left-0 grid w-10 place-items-center text-content-muted"
            >
                <slot name="icon" />
            </span>

            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :disabled="disabled"
                :aria-invalid="hasError"
                :aria-describedby="hint ? `${id}-hint` : undefined"
                class="w-full rounded-lg border bg-surface-raised py-2.5 text-sm text-content-strong shadow-sm transition placeholder:text-content-faint focus:outline-none disabled:bg-surface-sunken disabled:text-content-faint"
                :class="[
                    $slots.icon ? 'pl-10 pr-3.5' : 'px-3.5',
                    hasError
                        ? 'border-critical/60 focus:border-critical focus:ring-4 focus:ring-critical/20'
                        : 'border-line focus:border-silver-400 focus:ring-4 focus:ring-silver-400/20',
                ]"
                @input="onInput"
            />
        </div>

        <p v-if="hint && !hasError" :id="`${id}-hint`" class="mt-1.5 text-[13px] text-content-muted">
            {{ hint }}
        </p>
        <InputError :message="error" />
    </div>
</template>
