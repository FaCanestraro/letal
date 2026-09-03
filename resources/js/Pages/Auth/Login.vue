<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckField from '@/Components/CheckField.vue';
import PasswordField from '@/Components/PasswordField.vue';
import TextField from '@/Components/TextField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { routes } from '@/routes';
import { Link, useForm } from '@inertiajs/vue3';

defineProps<{ canResetPassword: boolean; status?: string }>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post(routes.login, {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <GuestLayout
        title="Entrar"
        eyebrow="Acesso restrito"
        heading="Entre na sua conta"
        description="Informe suas credenciais. Em seguida enviaremos um código de verificação para o seu e-mail."
    >
        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.email"
                label="E-mail"
                type="email"
                autocomplete="username"
                placeholder="nome@escritorio.com.br"
                :error="form.errors.email"
                required
            />

            <PasswordField
                v-model="form.password"
                label="Senha"
                autocomplete="current-password"
                placeholder="••••••••"
                :error="form.errors.password"
                required
            >
                <template #action>
                    <Link
                        v-if="canResetPassword"
                        :href="routes.passwordRequest"
                        class="text-[13px] font-medium text-content-muted underline-offset-4 transition hover:text-silver-200 hover:underline"
                    >
                        Esqueci a senha
                    </Link>
                </template>
            </PasswordField>

            <CheckField v-model="form.remember">Manter conectado neste dispositivo</CheckField>

            <AppButton type="submit" block :loading="form.processing">
                {{ form.processing ? 'Verificando…' : 'Continuar' }}
            </AppButton>
        </form>

        <template #footer>
            Ainda não tem acesso?
            <Link :href="routes.register" class="font-semibold text-content-strong underline-offset-4 hover:underline">
                Cadastre seu escritório
            </Link>
        </template>
    </GuestLayout>
</template>
