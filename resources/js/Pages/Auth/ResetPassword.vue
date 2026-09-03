<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import PasswordField from '@/Components/PasswordField.vue';
import TextField from '@/Components/TextField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { routes } from '@/routes';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ email: string; token: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(routes.passwordStore, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout
        title="Definir nova senha"
        eyebrow="Recuperação"
        heading="Defina uma nova senha"
        description="Escolha uma senha forte, com letras maiúsculas, minúsculas e números."
    >
        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.email"
                label="E-mail"
                type="email"
                autocomplete="username"
                :error="form.errors.email"
                required
            />

            <PasswordField
                v-model="form.password"
                label="Nova senha"
                autocomplete="new-password"
                :error="form.errors.password"
                with-strength
                required
            />

            <PasswordField
                v-model="form.password_confirmation"
                label="Confirmar nova senha"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ form.processing ? 'Salvando…' : 'Redefinir senha' }}
            </AppButton>
        </form>
    </GuestLayout>
</template>
