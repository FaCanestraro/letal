<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import CheckField from '@/Components/CheckField.vue';
import PasswordField from '@/Components/PasswordField.vue';
import TextField from '@/Components/TextField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { maskPhone } from '@/lib/format';
import { routes } from '@/routes';
import { Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    phone: '',
    company: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

function submit(): void {
    form.post(routes.register, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout
        title="Criar conta"
        eyebrow="Novo cadastro"
        heading="Cadastre seu escritório"
        description="Leva menos de um minuto. Você poderá convidar a equipe depois."
    >
        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.name"
                label="Nome completo"
                autocomplete="name"
                placeholder="Maria Oliveira"
                :error="form.errors.name"
                required
            />

            <TextField
                v-model="form.email"
                label="E-mail"
                type="email"
                autocomplete="email"
                placeholder="nome@escritorio.com.br"
                hint="Usaremos este endereço para enviar o código de verificação."
                :error="form.errors.email"
                required
            />

            <div class="grid gap-5 sm:grid-cols-2">
                <TextField
                    v-model="form.phone"
                    label="Telefone"
                    type="tel"
                    autocomplete="tel"
                    placeholder="(11) 91234-5678"
                    :transform="maskPhone"
                    :error="form.errors.phone"
                    required
                />

                <TextField
                    v-model="form.company"
                    label="Empresa"
                    autocomplete="organization"
                    placeholder="Oliveira Advogados"
                    :error="form.errors.company"
                    required
                />
            </div>

            <PasswordField
                v-model="form.password"
                label="Senha"
                autocomplete="new-password"
                placeholder="Mínimo de 8 caracteres"
                :error="form.errors.password"
                with-strength
                required
            />

            <PasswordField
                v-model="form.password_confirmation"
                label="Confirmar senha"
                autocomplete="new-password"
                placeholder="Repita a senha"
                :error="form.errors.password_confirmation"
                required
            />

            <CheckField v-model="form.terms" :error="form.errors.terms">
                Li e aceito os termos de uso e a política de privacidade da plataforma.
            </CheckField>

            <AppButton type="submit" block :loading="form.processing">
                {{ form.processing ? 'Criando conta…' : 'Criar conta' }}
            </AppButton>
        </form>

        <template #footer>
            Já possui cadastro?
            <Link :href="routes.login" class="font-semibold text-content-strong underline-offset-4 hover:underline">
                Entrar
            </Link>
        </template>
    </GuestLayout>
</template>
