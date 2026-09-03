<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import OtpField from '@/Components/OtpField.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { formatSeconds } from '@/lib/format';
import { routes } from '@/routes';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    maskedEmail: string;
    codeLength: number;
    expiresInMinutes: number;
    resendAvailableIn: number;
    localHintCode: string | null;
}>();

const form = useForm({ code: '' });

const cooldown = ref(props.resendAvailableIn);
const resending = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

const canResend = computed(() => cooldown.value <= 0 && !resending.value);

function startCooldown(seconds: number): void {
    cooldown.value = seconds;
}

function submit(): void {
    form.post(routes.twoFactor, {
        onError: () => form.reset('code'),
    });
}

function resend(): void {
    if (!canResend.value) {
        return;
    }

    resending.value = true;

    router.post(
        routes.twoFactorResend,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('code');
                form.clearErrors();
                startCooldown(60);
            },
            onFinish: () => (resending.value = false),
        },
    );
}

function useHintCode(): void {
    if (!props.localHintCode) {
        return;
    }

    form.code = props.localHintCode;
    form.clearErrors();
    submit();
}

function cancel(): void {
    router.delete(routes.twoFactor);
}

onMounted(() => {
    timer = setInterval(() => {
        if (cooldown.value > 0) {
            cooldown.value -= 1;
        }
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <GuestLayout
        title="Verificação em duas etapas"
        eyebrow="Segunda etapa"
        heading="Confirme sua identidade"
        :description="`Enviamos um código de ${codeLength} dígitos para ${maskedEmail}. Ele expira em ${expiresInMinutes} minutos.`"
    >
        <!-- Atalho de desenvolvimento: nenhum provedor de e-mail configurado ainda. -->
        <div
            v-if="localHintCode"
            class="mb-6 rounded-lg border border-dashed border-silver-500 bg-surface-hover px-4 py-3.5"
        >
            <p class="eyebrow text-silver-300">Modo desenvolvimento</p>
            <div class="mt-2 flex items-center justify-between gap-3">
                <code class="font-mono text-2xl font-bold tracking-[0.35em] text-content-strong">
                    {{ localHintCode }}
                </code>
                <button
                    type="button"
                    class="shrink-0 rounded-lg border border-silver-500 bg-surface-raised px-3 py-1.5 text-[13px] font-semibold text-content-strong transition hover:bg-surface-hover"
                    @click="useHintCode"
                >
                    Usar este código
                </button>
            </div>
            <p class="mt-2 text-[12px] leading-relaxed text-content-muted">
                Exibido porque <code class="font-mono">MAIL_MAILER=log</code> e
                <code class="font-mono">APP_ENV=local</code>. Ao configurar o provedor de e-mail no
                <code class="font-mono">.env</code>, este bloco some sozinho.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <OtpField
                v-model="form.code"
                :length="codeLength"
                :error="form.errors.code"
                :disabled="form.processing"
                @complete="submit"
            />

            <AppButton
                type="submit"
                block
                :loading="form.processing"
                :disabled="form.code.length !== codeLength"
            >
                {{ form.processing ? 'Validando…' : 'Confirmar e entrar' }}
            </AppButton>

            <div class="flex items-center justify-between gap-3 border-t border-line pt-5">
                <button
                    type="button"
                    class="text-[13px] font-medium underline-offset-4 transition"
                    :class="
                        canResend
                            ? 'text-content hover:text-silver-200 hover:underline'
                            : 'cursor-not-allowed text-content-muted'
                    "
                    :disabled="!canResend"
                    @click="resend"
                >
                    <template v-if="canResend">Reenviar código</template>
                    <template v-else>Reenviar em {{ formatSeconds(cooldown) }}</template>
                </button>

                <button
                    type="button"
                    class="text-[13px] font-medium text-content-muted underline-offset-4 transition hover:text-content hover:underline"
                    @click="cancel"
                >
                    Usar outra conta
                </button>
            </div>
        </form>

        <template #footer>
            Não recebeu? Verifique a caixa de spam ou confirme o e-mail cadastrado com o administrador.
        </template>
    </GuestLayout>
</template>
