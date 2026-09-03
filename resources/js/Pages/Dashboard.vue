<script setup lang="ts">
import SurfaceCard from '@/Components/SurfaceCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { routes } from '@/routes';
import type { Metric, SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ metrics: Metric[]; lastLoginAt: string | null }>();

const user = computed(() => usePage<SharedProps>().props.auth.user);

const checklist = [
    { done: true, label: 'Conta criada e escritório identificado' },
    { done: true, label: 'Verificação em duas etapas ativa' },
    { done: false, label: 'Configurar o provedor de e-mail no arquivo .env' },
    { done: false, label: 'Importar o primeiro arquivo SPED' },
];
</script>

<template>
    <AppLayout
        title="Painel"
        :heading="`Bem-vindo, ${user?.name.split(' ')[0]}`"
        description="Visão geral da operação fiscal do escritório."
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="metric in metrics"
                :key="metric.label"
                class="rounded-card border border-line bg-surface-raised px-5 py-4 shadow-card"
            >
                <p class="text-[13px] font-medium text-content-muted">{{ metric.label }}</p>
                <p class="display-title mt-2 text-3xl text-content-strong">{{ metric.value }}</p>
                <p class="mt-1 truncate text-[12px] text-content-muted">{{ metric.hint }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[1.6fr_1fr]">
            <SurfaceCard
                title="Conversor SPED"
                description="Envie os arquivos de um lote e acompanhe o resultado pelo histórico."
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <Link
                        :href="routes.sped"
                        class="group rounded-xl border border-line bg-surface-overlay p-4 transition hover:border-silver-500 hover:bg-surface-hover"
                    >
                        <span class="grid size-9 place-items-center rounded-lg bg-surface-sunken text-silver-300">
                            <svg class="size-4.5" viewBox="0 0 20 20" fill="none">
                                <path
                                    d="M4 3.5h7L16 8v8.5H4v-13Z"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                    stroke-linejoin="round"
                                />
                                <path d="M10.8 3.6V8.2H15.6" stroke="currentColor" stroke-width="1.4" />
                            </svg>
                        </span>
                        <p class="mt-3 text-[14px] font-semibold text-content-strong">SPED (.txt) → Excel</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-content-muted">
                            Interpreta os blocos e registros do arquivo e gera uma planilha por bloco.
                        </p>
                    </Link>

                    <Link
                        :href="routes.sped"
                        class="group rounded-xl border border-line bg-surface-overlay p-4 transition hover:border-silver-500 hover:bg-surface-hover"
                    >
                        <span class="grid size-9 place-items-center rounded-lg bg-surface-sunken text-silver-300">
                            <svg class="size-4.5" viewBox="0 0 20 20" fill="none">
                                <path
                                    d="M3 6.5h10.5m0 0L10.5 3.5m3 3-3 3M17 13.5H6.5m0 0 3-3m-3 3 3 3"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </span>
                        <p class="mt-3 text-[14px] font-semibold text-content-strong">Excel → SPED (.txt)</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-content-muted">
                            Reconstrói o arquivo no layout oficial a partir da planilha revisada.
                        </p>
                    </Link>
                </div>
            </SurfaceCard>

            <div class="space-y-4">
                <SurfaceCard title="Primeiros passos">
                    <ul class="space-y-3">
                        <li v-for="item in checklist" :key="item.label" class="flex items-start gap-2.5">
                            <span
                                class="mt-0.5 grid size-4.5 shrink-0 place-items-center rounded-full"
                                :class="item.done ? 'bg-positive text-content-strong' : 'border border-line-strong'"
                            >
                                <svg v-if="item.done" class="size-2.5" viewBox="0 0 12 12" fill="none">
                                    <path
                                        d="m2.5 6.2 2.3 2.3L9.5 3.8"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                            <span
                                class="text-[13px] leading-relaxed"
                                :class="item.done ? 'text-content-muted line-through' : 'text-content'"
                            >
                                {{ item.label }}
                            </span>
                        </li>
                    </ul>
                </SurfaceCard>

                <SurfaceCard title="Sessão">
                    <dl class="space-y-2.5 text-[13px]">
                        <div class="flex justify-between gap-3">
                            <dt class="text-content-muted">Escritório</dt>
                            <dd class="truncate font-medium text-content-strong">{{ user?.company }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-content-muted">Perfil</dt>
                            <dd class="font-medium text-content-strong">
                                {{ user?.role === 'owner' ? 'Administrador' : 'Membro' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-content-muted">Último acesso</dt>
                            <dd class="font-medium text-content-strong">{{ formatDateTime(lastLoginAt) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-content-muted">Duplo fator</dt>
                            <dd class="font-medium" :class="user?.two_factor_enabled ? 'text-positive' : 'text-caution'">
                                {{ user?.two_factor_enabled ? 'Ativo' : 'Inativo' }}
                            </dd>
                        </div>
                    </dl>
                </SurfaceCard>
            </div>
        </div>
    </AppLayout>
</template>
