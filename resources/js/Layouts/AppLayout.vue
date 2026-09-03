<script setup lang="ts">
import AlertBanner from '@/Components/AlertBanner.vue';
import AppLogo from '@/Components/AppLogo.vue';
import SidebarLink from '@/Components/SidebarLink.vue';
import { routes } from '@/routes';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

defineProps<{
    title: string;
    heading: string;
    description?: string;
}>();

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);

const drawerOpen = ref(false);
const dismissed = ref(false);

watch(
    () => page.url,
    () => {
        drawerOpen.value = false;
        dismissed.value = false;
    },
);
watch(flash, () => (dismissed.value = false));

const navigation = [
    { label: 'Painel', href: routes.dashboard, icon: 'dashboard' },
    { label: 'Conversor SPED', href: routes.sped, icon: 'convert' },
    { label: 'Meu perfil', href: routes.settings.profile, icon: 'user' },
];

const isActive = (href: string) => page.url === href || page.url.startsWith(`${href}/`);
</script>

<template>
    <Head :title="title" />

    <div class="min-h-dvh bg-surface-base">
        <!-- Overlay do menu no mobile -->
        <div
            v-if="drawerOpen"
            class="fixed inset-0 z-30 bg-surface-sunken/50 backdrop-blur-[2px] lg:hidden"
            @click="drawerOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-[17rem] flex-col bg-surface-sunken transition-transform duration-200 lg:translate-x-0"
            :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center border-b border-line-soft px-6">
                <Link :href="routes.dashboard">
                    <AppLogo size="md" />
                </Link>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-6 py-6">
                <p class="eyebrow mb-3 px-3 text-content-muted">Navegação</p>

                <SidebarLink
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    :label="item.label"
                    :active="isActive(item.href)"
                >
                    <template #icon>
                        <svg
                            v-if="item.icon === 'dashboard'"
                            class="size-4.5"
                            viewBox="0 0 20 20"
                            fill="none"
                        >
                            <rect x="2.5" y="2.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                            <rect x="11.5" y="2.5" width="6" height="9" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                            <rect x="2.5" y="11.5" width="6" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                            <rect x="11.5" y="14.5" width="6" height="3" rx="1.5" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                        <svg v-else-if="item.icon === 'convert'" class="size-4.5" viewBox="0 0 20 20" fill="none">
                            <path
                                d="M3 6.5h10.5m0 0L10.5 3.5m3 3-3 3M17 13.5H6.5m0 0 3-3m-3 3 3 3"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else class="size-4.5" viewBox="0 0 20 20" fill="none">
                            <circle cx="10" cy="6.8" r="3.3" stroke="currentColor" stroke-width="1.5" />
                            <path
                                d="M3.8 16.8c.6-3 3.2-4.6 6.2-4.6s5.6 1.6 6.2 4.6"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                            />
                        </svg>
                    </template>
                </SidebarLink>
            </nav>

            <div class="border-t border-line-soft p-4">
                <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-full bg-silver-400/12 text-[13px] font-semibold text-silver-300 ring-1 ring-silver-400/25"
                    >
                        {{ user?.initials }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] font-medium text-content-strong">{{ user?.name }}</span>
                        <span class="block truncate text-[11px] text-content-faint">{{ user?.company }}</span>
                    </span>
                </div>

                <Link
                    :href="routes.logout"
                    method="post"
                    as="button"
                    class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium text-content-muted transition hover:bg-surface-hover/70 hover:text-content-strong"
                >
                    <svg class="size-4.5 shrink-0" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M12.5 6.5V4.8A1.8 1.8 0 0 0 10.7 3H4.8A1.8 1.8 0 0 0 3 4.8v10.4A1.8 1.8 0 0 0 4.8 17h5.9a1.8 1.8 0 0 0 1.8-1.8v-1.7M8 10h9m0 0-2.6-2.6M17 10l-2.6 2.6"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    Encerrar sessão
                </Link>
            </div>
        </aside>

        <div class="lg:pl-[17rem]">
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-line bg-surface-base/85 px-5 backdrop-blur sm:px-8"
            >
                <button
                    type="button"
                    class="-ml-1 rounded-lg p-2 text-content transition hover:bg-surface-hover lg:hidden"
                    aria-label="Abrir menu"
                    @click="drawerOpen = true"
                >
                    <svg class="size-5" viewBox="0 0 20 20" fill="none">
                        <path d="M3 5.5h14M3 10h14M3 14.5h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </button>

                <p class="display-title truncate text-[15px] text-content-strong">{{ heading }}</p>

                <span class="ml-auto hidden items-center gap-2 text-xs text-content-muted sm:flex">
                    <span class="size-1.5 rounded-full bg-positive" />
                    Sessão verificada
                </span>
            </header>

            <main class="px-5 py-8 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-6xl">
                    <div class="mb-7">
                        <h1 class="display-title text-2xl text-content-strong">{{ heading }}</h1>
                        <p v-if="description" class="mt-1.5 max-w-2xl text-[14px] leading-relaxed text-content-muted">
                            {{ description }}
                        </p>
                    </div>

                    <div class="space-y-4">
                        <AlertBanner
                            v-if="flash.success && !dismissed"
                            variant="success"
                            dismissible
                            @dismiss="dismissed = true"
                        >
                            {{ flash.success }}
                        </AlertBanner>
                        <AlertBanner
                            v-if="flash.error && !dismissed"
                            variant="error"
                            dismissible
                            @dismiss="dismissed = true"
                        >
                            {{ flash.error }}
                        </AlertBanner>
                    </div>

                    <div class="mt-5">
                        <slot />
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
