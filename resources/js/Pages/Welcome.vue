<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import type { SharedProps } from '@/types';
import { routes } from '@/routes';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const user = computed(() => usePage<SharedProps>().props.auth.user);
</script>

<template>
    <Head title="Início" />

    <div class="plate flex min-h-dvh flex-col bg-surface-sunken px-6 py-8">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between">
            <AppLogo size="md" with-tagline />

            <nav class="flex items-center gap-2 text-[13px] font-medium">
                <Link
                    v-if="user"
                    :href="routes.dashboard"
                    class="rounded-lg bg-silver-200 px-4 py-2 text-surface-base transition hover:bg-silver-300"
                >
                    Ir para o painel
                </Link>
                <template v-else>
                    <Link :href="routes.login" class="rounded-lg px-4 py-2 text-content transition hover:text-content-strong">
                        Entrar
                    </Link>
                    <Link
                        :href="routes.register"
                        class="rounded-lg bg-silver-200 px-4 py-2 text-surface-base transition hover:bg-silver-300"
                    >
                        Criar conta
                    </Link>
                </template>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center py-20">
            <p class="eyebrow text-silver-300">Plataforma jurídico-fiscal</p>
            <h1 class="display-title mt-5 max-w-3xl text-5xl leading-[1.1] text-content-strong sm:text-6xl">
                O arquivo SPED do seu cliente,
                <span class="text-silver-300">legível em minutos.</span>
            </h1>
            <p class="mt-6 max-w-xl text-[16px] leading-relaxed text-content">
                Converta arquivos SPED em planilhas auditáveis, revise com a equipe e devolva o arquivo no
                layout oficial — com controle de acesso e verificação em duas etapas.
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <Link
                    :href="user ? routes.dashboard : routes.register"
                    class="rounded-lg bg-silver-200 px-6 py-3 text-sm font-semibold text-surface-base transition hover:bg-silver-300"
                >
                    {{ user ? 'Abrir o painel' : 'Cadastrar escritório' }}
                </Link>
                <Link
                    v-if="!user"
                    :href="routes.login"
                    class="rounded-lg border border-line-strong px-6 py-3 text-sm font-semibold text-content-strong transition hover:border-silver-600 hover:text-content-strong"
                >
                    Já tenho conta
                </Link>
            </div>
        </main>

        <footer class="mx-auto w-full max-w-5xl text-xs text-content-muted">
            © {{ new Date().getFullYear() }} L&C TAXSHEET
        </footer>
    </div>
</template>
