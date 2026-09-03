<script setup lang="ts">
withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        type?: 'button' | 'submit' | 'reset';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', type: 'button', loading: false, disabled: false, block: false },
);

// No escuro quem avança é a peça clara: a prata da marca com texto grafite.
const variants: Record<string, string> = {
    primary:
        'bg-silver-200 text-surface-base shadow-card hover:bg-silver-100 active:bg-silver-300 disabled:hover:bg-silver-200',
    secondary:
        'border border-line-strong bg-surface-overlay text-content-strong hover:border-silver-600 hover:bg-surface-hover',
    ghost: 'text-content-muted hover:bg-surface-hover hover:text-content-strong',
    danger: 'bg-critical text-surface-base shadow-card hover:brightness-110',
};
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-45"
        :class="[variants[variant], block ? 'w-full' : '']"
    >
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <slot />
    </button>
</template>
