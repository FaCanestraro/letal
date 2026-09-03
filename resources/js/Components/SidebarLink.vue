<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ href: string; active: boolean; label: string }>();
</script>

<template>
    <Link
        :href="href"
        prefetch
        class="group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
        :class="
            active
                ? 'bg-surface-hover text-content-strong'
                : 'text-content-muted hover:bg-surface-hover/60 hover:text-content-strong'
        "
    >
        <span
            class="absolute inset-y-1.5 -left-3 w-0.5 rounded-full bg-silver-200 transition-opacity"
            :class="active ? 'opacity-100' : 'opacity-0'"
        />
        <span
            class="grid size-5 shrink-0 place-items-center"
            :class="active ? 'text-silver-300' : 'text-content-faint group-hover:text-silver-300'"
        >
            <slot name="icon" />
        </span>
        {{ label }}
    </Link>
</template>
