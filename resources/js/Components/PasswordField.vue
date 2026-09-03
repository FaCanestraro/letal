<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import InputError from './InputError.vue';

const props = withDefaults(
    defineProps<{
        label: string;
        modelValue: string;
        error?: string | null;
        placeholder?: string;
        autocomplete?: string;
        required?: boolean;
        /** Exibe o medidor de força da senha. */
        withStrength?: boolean;
    }>(),
    { required: false, withStrength: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();
const visible = ref(false);
const hasError = computed(() => Boolean(props.error));

/** Pontuação simples e local — o servidor continua sendo a fonte da verdade. */
const strength = computed(() => {
    const value = props.modelValue;

    if (!value) {
        return 0;
    }

    return [
        value.length >= 8,
        /[a-z]/.test(value) && /[A-Z]/.test(value),
        /\d/.test(value),
        /[^A-Za-z0-9]/.test(value) || value.length >= 14,
    ].filter(Boolean).length;
});

const strengthLabel = computed(
    () => ['Muito fraca', 'Fraca', 'Razoável', 'Boa', 'Forte'][strength.value],
);

const strengthColor = computed(
    () => ['bg-line', 'bg-critical', 'bg-caution', 'bg-silver-200', 'bg-positive'][strength.value],
);
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
            <input
                :id="id"
                :type="visible ? 'text' : 'password'"
                :value="modelValue"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :aria-invalid="hasError"
                class="w-full rounded-lg border bg-surface-raised py-2.5 pl-3.5 pr-11 text-sm text-content-strong shadow-sm transition placeholder:text-content-faint focus:outline-none"
                :class="
                    hasError
                        ? 'border-critical/60 focus:border-critical focus:ring-4 focus:ring-critical/20'
                        : 'border-line focus:border-silver-400 focus:ring-4 focus:ring-silver-400/20'
                "
                @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />

            <button
                type="button"
                class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-lg text-content-muted transition hover:text-content"
                :aria-label="visible ? 'Ocultar senha' : 'Mostrar senha'"
                @click="visible = !visible"
            >
                <svg v-if="!visible" class="size-4.5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path
                        d="M2 10s3-5.5 8-5.5S18 10 18 10s-3 5.5-8 5.5S2 10 2 10Z"
                        stroke="currentColor"
                        stroke-width="1.4"
                    />
                    <circle cx="10" cy="10" r="2.4" stroke="currentColor" stroke-width="1.4" />
                </svg>
                <svg v-else class="size-4.5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path
                        d="M4 4l12 12M8.2 8.4A2.4 2.4 0 0 0 10 12.4c.6 0 1.2-.2 1.6-.6M6 6.1C3.6 7.5 2 10 2 10s3 5.5 8 5.5c1.3 0 2.5-.3 3.5-.8M14.9 13A11 11 0 0 0 18 10s-3-5.5-8-5.5c-.7 0-1.3.1-1.9.2"
                        stroke="currentColor"
                        stroke-width="1.4"
                        stroke-linecap="round"
                    />
                </svg>
            </button>
        </div>

        <div v-if="withStrength && modelValue" class="mt-2 flex items-center gap-2.5">
            <div class="flex h-1 flex-1 gap-1">
                <span
                    v-for="step in 4"
                    :key="step"
                    class="h-full flex-1 rounded-full transition-colors"
                    :class="step <= strength ? strengthColor : 'bg-line'"
                />
            </div>
            <span class="w-20 text-right text-[11px] font-medium text-content-muted">{{ strengthLabel }}</span>
        </div>

        <InputError :message="error" />
    </div>
</template>
