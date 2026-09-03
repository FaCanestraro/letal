<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        size?: 'sm' | 'md' | 'lg' | 'xl';
        withTagline?: boolean;
        /** Só o monograma "L&C", para espaços apertados. */
        markOnly?: boolean;
    }>(),
    { size: 'md', withTagline: false, markOnly: false },
);

const scale = computed(
    () =>
        ({
            sm: { mark: '1.25rem', word: '0.6875rem', tagline: '0.5rem', gap: 'gap-1.5' },
            md: { mark: '1.625rem', word: '0.8125rem', tagline: '0.5625rem', gap: 'gap-2' },
            lg: { mark: '2.25rem', word: '1.0625rem', tagline: '0.625rem', gap: 'gap-2.5' },
            xl: { mark: '3.25rem', word: '1.5rem', tagline: '0.75rem', gap: 'gap-3.5' },
        })[props.size],
);
</script>

<template>
    <span class="inline-flex select-none items-center" :class="scale.gap">
        <span
            class="display-title brushed leading-none"
            :style="{ fontSize: scale.mark, letterSpacing: '-0.02em' }"
            aria-hidden="true"
        >
            L&amp;C
        </span>

        <span v-if="!markOnly" class="flex flex-col leading-none">
            <span
                class="brushed font-semibold"
                :style="{ fontSize: scale.word, letterSpacing: '0.19em' }"
            >
                TAXSHEET
            </span>
            <span
                v-if="withTagline"
                class="mt-1.5 font-semibold uppercase text-content-faint"
                :style="{ fontSize: scale.tagline, letterSpacing: '0.24em' }"
            >
                Consultoria tributária
            </span>
        </span>

        <span class="sr-only">L&amp;C TAXSHEET</span>
    </span>
</template>
