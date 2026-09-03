<script setup lang="ts">
defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <section class="rounded-card border border-line bg-surface-raised shadow-card">
        <header
            v-if="title || $slots.header"
            class="flex flex-wrap items-start justify-between gap-3 border-b border-line-soft px-6 py-4"
        >
            <div v-if="title">
                <h2 class="display-title text-base text-content-strong">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-[13px] text-content-muted">{{ description }}</p>
            </div>
            <slot name="header" />
        </header>

        <div class="px-6 py-5">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t border-line-soft bg-surface-overlay px-6 py-3.5">
            <slot name="footer" />
        </footer>
    </section>
</template>
