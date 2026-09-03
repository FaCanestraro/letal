<script setup lang="ts">
import AlertBanner from '@/Components/AlertBanner.vue';
import AppLogo from '@/Components/AppLogo.vue';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

defineProps<{
    title: string;
    eyebrow?: string;
    heading: string;
    description?: string;
}>();

const page = usePage<SharedProps>();
const flash = computed(() => page.props.flash);

const dismissed = ref(false);
watch(flash, () => (dismissed.value = false));

const highlights = [
    'Conversão de arquivos SPED fiscal em planilhas auditáveis',
    'Trilha de acesso com verificação em duas etapas',
    'Organização por escritório, cliente e competência',
];
</script>

<template>
    <Head :title="title" />

    <div class="min-h-dvh bg-surface-base lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
        <!-- Painel institucional -->
        <aside class="plate relative hidden overflow-hidden bg-surface-sunken px-12 py-14 lg:flex lg:flex-col">
            <div class="relative z-10 flex h-full flex-col">
                <Link :href="'/'" class="w-fit">
                    <AppLogo size="lg" with-tagline />
                </Link>

                <div class="mt-auto max-w-md">
                    <p class="eyebrow text-silver-300">Plataforma jurídico-fiscal</p>
                    <h1 class="display-title mt-4 text-4xl leading-[1.15] text-content-strong">
                        A rotina fiscal do escritório,
                        <span class="text-silver-300">em ordem.</span>
                    </h1>
                    <p class="mt-5 text-[15px] leading-relaxed text-content">
                        Centralize a conversão de arquivos SPED, o controle de acessos e o histórico de
                        entregas em um único ambiente, com o rigor que a atividade exige.
                    </p>

                    <ul class="mt-9 space-y-3.5">
                        <li
                            v-for="item in highlights"
                            :key="item"
                            class="flex items-start gap-3 text-[14px] text-content"
                        >
                            <svg class="mt-0.5 size-4 shrink-0 text-silver-300" viewBox="0 0 16 16" fill="none">
                                <path
                                    d="m3.5 8.3 3 3 6-6.6"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <p class="mt-auto pt-12 text-xs text-content-faint">
                    © {{ new Date().getFullYear() }} L&C TAXSHEET — uso restrito a profissionais autorizados.
                </p>
            </div>

            <div
                class="pointer-events-none absolute -right-24 top-1/3 size-[420px] rounded-full bg-silver-400/[0.05] blur-3xl"
            />
        </aside>

        <!-- Formulário -->
        <main class="flex min-h-dvh flex-col justify-center border-line-soft px-5 py-10 sm:px-10 lg:border-l lg:px-16">
            <div class="mx-auto w-full max-w-[26rem]">
                <div class="mb-9 lg:hidden">
                    <AppLogo size="md" />
                </div>

                <p v-if="eyebrow" class="eyebrow text-silver-400">{{ eyebrow }}</p>
                <h2 class="display-title mt-2 text-[26px] leading-tight text-content-strong">{{ heading }}</h2>
                <p v-if="description" class="mt-2 text-[14px] leading-relaxed text-content-muted">
                    {{ description }}
                </p>

                <AlertBanner
                    v-if="flash.success && !dismissed"
                    variant="success"
                    dismissible
                    class="mt-6"
                    @dismiss="dismissed = true"
                >
                    {{ flash.success }}
                </AlertBanner>

                <div class="mt-7">
                    <slot />
                </div>

                <p v-if="$slots.footer" class="mt-8 text-center text-[13px] text-content-muted">
                    <slot name="footer" />
                </p>
            </div>
        </main>
    </div>
</template>
