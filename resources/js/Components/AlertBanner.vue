<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ variant?: 'success' | 'error' | 'info'; dismissible?: boolean }>(),
    { variant: 'info', dismissible: false },
);

defineEmits<{ dismiss: [] }>();

const styles = computed(
    () =>
        ({
            success: 'border-positive/30 bg-positive-dim text-positive',
            error: 'border-critical/30 bg-critical-dim text-critical',
            info: 'border-line-strong bg-surface-overlay text-content',
        })[props.variant],
);

const iconPath = computed(
    () =>
        ({
            success: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13Zm3.1 4.6-3.8 4.4-2.3-2.2.9-1 1.3 1.2 3-3.4.9 1Z',
            error: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM7.25 4.5h1.5v4.25h-1.5V4.5Zm0 5.5h1.5v1.5h-1.5V10Z',
            info: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13Zm-.75 5h1.5v5h-1.5v-5Zm0-2.5h1.5v1.5h-1.5V4Z',
        })[props.variant],
);
</script>

<template>
    <div class="flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-[13px]" :class="styles" role="status">
        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path :d="iconPath" />
        </svg>
        <span class="flex-1 leading-relaxed"><slot /></span>
        <button
            v-if="dismissible"
            type="button"
            class="-mr-1 shrink-0 rounded p-0.5 opacity-60 transition hover:opacity-100"
            aria-label="Fechar aviso"
            @click="$emit('dismiss')"
        >
            <svg class="size-3.5" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="m3 3 8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
        </button>
    </div>
</template>
