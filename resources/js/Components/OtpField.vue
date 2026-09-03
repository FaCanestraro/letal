<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import InputError from './InputError.vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        length?: number;
        error?: string | null;
        disabled?: boolean;
        autofocus?: boolean;
    }>(),
    { length: 6, disabled: false, autofocus: true },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    complete: [value: string];
}>();

const boxes = ref<HTMLInputElement[]>([]);

const digits = computed(() =>
    Array.from({ length: props.length }, (_, index) => props.modelValue[index] ?? ''),
);

function commit(next: string[]): void {
    const value = next.join('').replace(/\D/g, '').slice(0, props.length);

    emit('update:modelValue', value);

    if (value.length === props.length) {
        emit('complete', value);
    }
}

function focusBox(index: number): void {
    void nextTick(() => boxes.value[index]?.focus());
}

function onInput(index: number, event: Event): void {
    const target = event.target as HTMLInputElement;
    const typed = target.value.replace(/\D/g, '').slice(-1);

    const next = [...digits.value];
    next[index] = typed;
    target.value = typed;

    commit(next);

    if (typed && index < props.length - 1) {
        focusBox(index + 1);
    }
}

function onKeydown(index: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        event.preventDefault();

        const next = [...digits.value];
        next[index - 1] = '';
        commit(next);
        focusBox(index - 1);

        return;
    }

    if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        focusBox(index - 1);
    }

    if (event.key === 'ArrowRight' && index < props.length - 1) {
        event.preventDefault();
        focusBox(index + 1);
    }
}

function onPaste(event: ClipboardEvent): void {
    event.preventDefault();

    const pasted = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '').slice(0, props.length);

    if (!pasted) {
        return;
    }

    commit(pasted.split(''));
    focusBox(Math.min(pasted.length, props.length - 1));
}

onMounted(() => {
    if (props.autofocus) {
        focusBox(0);
    }
});
</script>

<template>
    <div>
        <div class="flex justify-between gap-2" @paste="onPaste">
            <input
                v-for="(digit, index) in digits"
                :key="index"
                :ref="(el) => { if (el) boxes[index] = el as HTMLInputElement }"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="1"
                :value="digit"
                :disabled="disabled"
                :aria-label="`Dígito ${index + 1} de ${length}`"
                class="h-14 w-full rounded-lg border bg-surface-raised text-center font-mono text-xl font-semibold text-content-strong shadow-sm transition focus:outline-none disabled:bg-surface-sunken"
                :class="
                    error
                        ? 'border-critical/60 focus:border-critical focus:ring-4 focus:ring-critical/20'
                        : 'border-line focus:border-silver-400 focus:ring-4 focus:ring-silver-400/20'
                "
                @input="onInput(index, $event)"
                @keydown="onKeydown(index, $event)"
                @focus="($event.target as HTMLInputElement).select()"
            />
        </div>
        <InputError :message="error" />
    </div>
</template>
