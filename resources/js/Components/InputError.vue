<script setup lang="ts">
defineProps<{ message?: string | null }>();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150"
        enter-from-class="-translate-y-0.5 opacity-0"
        leave-active-class="transition duration-100"
        leave-to-class="opacity-0"
    >
        <p v-if="message" class="mt-1.5 flex items-start gap-1.5 text-[13px] text-critical">
            <svg class="mt-0.5 size-3.5 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path
                    d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM7.25 4.5h1.5v4.25h-1.5V4.5Zm0 5.5h1.5v1.5h-1.5V10Z"
                />
            </svg>
            {{ message }}
        </p>
    </Transition>
</template>
