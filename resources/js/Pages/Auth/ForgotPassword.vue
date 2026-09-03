<script setup lang="ts">
import AlertBanner from '@/Components/AlertBanner.vue';
import AppButton from '@/Components/AppButton.vue';
import TextField from '@/Components/TextField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { routes } from '@/routes';
import { Link, useForm } from '@inertiajs/vue3';

defineProps<{ status?: string }>();

const form = useForm({ email: '' });

function submit(): void {
    form.post(routes.passwordEmail);
}
</script>

<template>
    <GuestLayout
        title="Recuperar senha"
        eyebrow="Recuperação"
        heading="Esqueceu sua senha?"
        description="Informe o e-mail cadastrado e enviaremos um link seguro para você definir uma nova senha."
    >
        <AlertBanner v-if="status" variant="success" class="mb-5">{{ status }}</AlertBanner>

        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.email"
                label="E-mail"
                type="email"
                autocomplete="email"
                placeholder="nome@escritorio.com.br"
                :error="form.errors.email"
                required
            />

            <AppButton type="submit" block :loading="form.processing">
                {{ form.processing ? 'Enviando…' : 'Enviar link de redefinição' }}
            </AppButton>
        </form>

        <template #footer>
            <Link :href="routes.login" class="font-semibold text-content-strong underline-offset-4 hover:underline">
                Voltar para o login
            </Link>
        </template>
    </GuestLayout>
</template>
