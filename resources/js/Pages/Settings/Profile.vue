<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import PasswordField from '@/Components/PasswordField.vue';
import SurfaceCard from '@/Components/SurfaceCard.vue';
import TextField from '@/Components/TextField.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { maskPhone } from '@/lib/format';
import { routes } from '@/routes';
import type { SharedProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ twoFactorForcedByConfig: boolean }>();

const user = computed(() => usePage<SharedProps>().props.auth.user!);

const profile = useForm({
    name: user.value.name,
    email: user.value.email,
    phone: maskPhone(user.value.phone),
    company: user.value.company,
});

const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function updateProfile(): void {
    profile.patch(routes.settings.profile, { preserveScroll: true });
}

function updatePassword(): void {
    password.put(routes.settings.password, {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('current_password'),
    });
}

function toggleTwoFactor(value: boolean): void {
    router.put(
        routes.settings.twoFactor,
        { two_factor_enabled: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout
        title="Meu perfil"
        heading="Meu perfil"
        description="Dados cadastrais, senha e segurança da sua conta."
    >
        <div class="grid gap-4 lg:grid-cols-[1.4fr_1fr] lg:items-start">
            <SurfaceCard title="Dados cadastrais" description="Informações usadas nos documentos e no acesso.">
                <form class="space-y-5" @submit.prevent="updateProfile">
                    <TextField
                        v-model="profile.name"
                        label="Nome completo"
                        autocomplete="name"
                        :error="profile.errors.name"
                        required
                    />

                    <TextField
                        v-model="profile.email"
                        label="E-mail"
                        type="email"
                        autocomplete="email"
                        hint="O código de verificação em duas etapas é enviado para este endereço."
                        :error="profile.errors.email"
                        required
                    />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <TextField
                            v-model="profile.phone"
                            label="Telefone"
                            type="tel"
                            autocomplete="tel"
                            :transform="maskPhone"
                            :error="profile.errors.phone"
                            required
                        />

                        <TextField
                            v-model="profile.company"
                            label="Empresa"
                            autocomplete="organization"
                            :error="profile.errors.company"
                            required
                        />
                    </div>

                    <div class="flex items-center gap-3 pt-1">
                        <AppButton type="submit" :loading="profile.processing">Salvar alterações</AppButton>
                        <Transition
                            enter-active-class="transition duration-150"
                            enter-from-class="opacity-0"
                            leave-active-class="transition duration-300"
                            leave-to-class="opacity-0"
                        >
                            <span v-if="profile.recentlySuccessful" class="text-[13px] text-positive">
                                Salvo.
                            </span>
                        </Transition>
                    </div>
                </form>
            </SurfaceCard>

            <div class="space-y-4">
                <SurfaceCard title="Verificação em duas etapas">
                    <div class="flex items-start justify-between gap-4">
                        <p class="text-[13px] leading-relaxed text-content">
                            A cada login enviamos um código de uso único para o seu e-mail. Recomendado para
                            contas com acesso a documentos fiscais.
                        </p>
                        <ToggleSwitch
                            :model-value="user.two_factor_enabled"
                            :disabled="twoFactorForcedByConfig"
                            @update:model-value="toggleTwoFactor"
                        />
                    </div>

                    <p v-if="twoFactorForcedByConfig" class="mt-3 text-[12px] text-caution">
                        O recurso está desligado globalmente pela variável TWO_FACTOR_ENABLED no .env.
                    </p>
                </SurfaceCard>

                <SurfaceCard title="Alterar senha">
                    <form class="space-y-5" @submit.prevent="updatePassword">
                        <PasswordField
                            v-model="password.current_password"
                            label="Senha atual"
                            autocomplete="current-password"
                            :error="password.errors.current_password"
                            required
                        />

                        <PasswordField
                            v-model="password.password"
                            label="Nova senha"
                            autocomplete="new-password"
                            :error="password.errors.password"
                            with-strength
                            required
                        />

                        <PasswordField
                            v-model="password.password_confirmation"
                            label="Confirmar nova senha"
                            autocomplete="new-password"
                            :error="password.errors.password_confirmation"
                            required
                        />

                        <AppButton type="submit" variant="secondary" :loading="password.processing">
                            Atualizar senha
                        </AppButton>
                    </form>
                </SurfaceCard>
            </div>
        </div>
    </AppLayout>
</template>
