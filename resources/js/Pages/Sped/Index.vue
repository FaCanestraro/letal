<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import InputError from '@/Components/InputError.vue';
import SurfaceCard from '@/Components/SurfaceCard.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatBytes, formatDateTime, formatDuration, formatNumber } from '@/lib/format';
import { routes } from '@/routes';
import { router, useForm, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Conversion {
    id: number;
    direction: 'to_spreadsheet' | 'to_text';
    direction_label: string;
    model: string | null;
    input_count: number;
    processed_count: number;
    chunk_count: number;
    progress: number;
    eta: number | null;
    input_names: string[];
    output_name: string | null;
    output_size: number | null;
    row_count: number;
    sheet_count: number;
    status: 'uploading' | 'pending' | 'processing' | 'done' | 'failed';
    error_message: string | null;
    downloadable: boolean;
    download_url: string | null;
    running: boolean;
    duration: number | null;
    created_at: string | null;
}

const props = defineProps<{
    conversions: Conversion[];
    models: { value: string; label: string; short: string }[];
    uploadLimit: { files: number; appMax: number; phpMax: number; perFileMb: number; postMax: string };
}>();

const directions = [
    {
        value: 'to_spreadsheet' as const,
        title: 'SPED (.txt) → Excel',
        blurb: 'Envie os arquivos de um mesmo modelo. Todos os períodos são consolidados numa planilha, com uma aba por registro.',
        accept: '.txt',
        multiple: true,
    },
    {
        value: 'to_text' as const,
        title: 'Excel → SPED (.txt)',
        blurb: 'Envie a planilha revisada. Devolvemos um .zip com um .txt por CNPJ e período, com os contadores recalculados.',
        accept: '.xlsx',
        multiple: false,
    },
];

const form = useForm<{ direction: 'to_spreadsheet' | 'to_text'; files: File[]; expected_files: number }>({
    direction: 'to_spreadsheet',
    files: [],
    // O servidor confere este número: se chegarem menos arquivos, o corte veio
    // do PHP e a conversão é recusada em vez de gerar planilha incompleta.
    expected_files: 0,
});

const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

const sending = ref(false);
const sentCount = ref(0);
const currentName = ref('');
const uploadError = ref<string | null>(null);

const sendProgress = computed(() =>
    form.files.length ? Math.round((sentCount.value / form.files.length) * 100) : 0,
);

// A conversão roda na fila; enquanto houver algo em andamento a tela recarrega
// só a lista, e para de consultar assim que tudo termina.
const hasRunning = computed(() => props.conversions.some((c) => c.running));

const poll = usePoll(
    3000,
    { only: ['conversions'] },
    { autoStart: false },
);

watch(
    hasRunning,
    (running) => (running ? poll.start() : poll.stop()),
    { immediate: true },
);

const current = computed(() => directions.find((d) => d.value === form.direction)!);

const totalSize = computed(() => form.files.reduce((sum, file) => sum + file.size, 0));

const fileErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('files'))
        .map(([, message]) => message as string),
);

function chooseDirection(value: 'to_spreadsheet' | 'to_text'): void {
    if (form.direction === value) {
        return;
    }

    form.direction = value;
    form.files = [];
    form.clearErrors();
}

function addFiles(list: FileList | null): void {
    if (!list) {
        return;
    }

    const incoming = Array.from(list);
    form.files = current.value.multiple ? [...form.files, ...incoming] : incoming.slice(0, 1);
    form.expected_files = form.files.length;
    form.clearErrors();
}

function removeFile(index: number): void {
    form.files = form.files.filter((_, i) => i !== index);
    form.expected_files = form.files.length;
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? null);
}

/** Lê o token que o Laravel deixa no cookie para autenticar as requisições. */
function xsrfToken(): string {
    const found = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return found ? decodeURIComponent(found.split('=').slice(1).join('=')) : '';
}

async function api(url: string, options: RequestInit = {}): Promise<any> {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(options.headers ?? {}),
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(payload.message ?? `Falha na comunicação com o servidor (${response.status}).`);
    }

    return payload;
}

/**
 * Envia um arquivo por requisição.
 *
 * Um POST único com o lote inteiro não funciona: o PHP carrega o corpo da
 * requisição inteiro na memória, então algumas centenas de MB já derrubam o
 * processo antes de qualquer validação.
 */
async function submit(): Promise<void> {
    if (!form.files.length || sending.value) {
        return;
    }

    sending.value = true;
    sentCount.value = 0;
    uploadError.value = null;
    form.clearErrors();

    const files = [...form.files];
    let batchId: number | null = null;

    try {
        const batch = await api(routes.spedBatch, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ direction: form.direction, total: files.length }),
        });

        batchId = batch.id;

        // Três de cada vez: aproveita a rede sem encher a fila do navegador.
        const fila = files.map((file, index) => ({ file, index }));
        const trabalhador = async (): Promise<void> => {
            for (;;) {
                const item = fila.shift();

                if (!item) {
                    return;
                }

                const body = new FormData();
                body.append('index', String(item.index));
                body.append('file', item.file);

                currentName.value = item.file.name;
                await api(routes.spedBatchUpload(batchId!), { method: 'POST', body });
                sentCount.value += 1;
            }
        };

        await Promise.all([trabalhador(), trabalhador(), trabalhador()]);

        await api(routes.spedBatchConvert(batch.id), { method: 'POST' });

        form.files = [];
        currentName.value = '';

        if (fileInput.value) {
            fileInput.value.value = '';
        }

        router.reload({ only: ['conversions'] });
        poll.start();
    } catch (error) {
        uploadError.value = error instanceof Error ? error.message : 'Falha no envio.';

        const aberto = batchId;

        if (aberto !== null) {
            await api(routes.spedBatchCancel(aberto), { method: 'DELETE' }).catch(() => undefined);
        }
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <AppLayout
        title="Conversor SPED"
        heading="Conversor SPED"
        description="Converta arquivos SPED em planilhas auditáveis e reconstrua o arquivo oficial a partir da planilha revisada."
    >
        <div class="grid gap-4 lg:grid-cols-[1.35fr_1fr] lg:items-start">
            <SurfaceCard title="Nova conversão">
                <form class="space-y-6" @submit.prevent="submit">
                    <!-- Direção -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="option in directions"
                            :key="option.value"
                            type="button"
                            class="rounded-xl border p-4 text-left transition"
                            :class="
                                form.direction === option.value
                                    ? 'border-silver-400 bg-surface-hover ring-2 ring-silver-400/25'
                                    : 'border-line bg-surface-overlay hover:border-line-strong'
                            "
                            @click="chooseDirection(option.value)"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="grid size-4 place-items-center rounded-full border"
                                    :class="form.direction === option.value ? 'border-silver-300' : 'border-line-strong'"
                                >
                                    <span v-if="form.direction === option.value" class="size-2 rounded-full bg-silver-400" />
                                </span>
                                <span class="text-[14px] font-semibold text-content-strong">{{ option.title }}</span>
                            </span>
                            <span class="mt-2 block text-[13px] leading-relaxed text-content-muted">{{ option.blurb }}</span>
                        </button>
                    </div>

                    <!-- Área de envio -->
                    <div>
                        <div
                            class="rounded-xl border-2 border-dashed px-5 py-8 text-center transition"
                            :class="
                                dragging
                                    ? 'border-silver-400 bg-surface-hover'
                                    : 'border-line-strong bg-surface-overlay hover:border-silver-600'
                            "
                            @dragover.prevent="dragging = true"
                            @dragleave.prevent="dragging = false"
                            @drop.prevent="onDrop"
                        >
                            <svg class="mx-auto size-7 text-content-faint" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path
                                    d="M12 15.5V4m0 0L8 8m4-4 4 4M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>

                            <p class="mt-3 text-[14px] text-content">
                                Arraste os arquivos <span class="font-mono font-semibold">{{ current.accept }}</span> aqui
                            </p>
                            <p class="mt-1 text-[13px] text-content-muted">
                                ou
                                <button
                                    type="button"
                                    class="font-semibold text-content-strong underline underline-offset-4 hover:text-silver-200"
                                    @click="fileInput?.click()"
                                >
                                    escolha no computador
                                </button>
                            </p>

                            <input
                                ref="fileInput"
                                type="file"
                                class="hidden"
                                :accept="current.accept"
                                :multiple="current.multiple"
                                @change="addFiles(($event.target as HTMLInputElement).files)"
                            />

                            <p class="mt-3 text-[12px] text-content-muted">
                                Até {{ uploadLimit.files }} arquivos, {{ uploadLimit.perFileMb }} MB cada
                            </p>
                            <p class="mx-auto mt-2 max-w-sm text-[12px] leading-relaxed text-content-faint">
                                Os arquivos sobem um a um, então o tamanho do lote não é limitado pelo servidor.
                            </p>
                        </div>

                        <InputError v-for="(message, i) in fileErrors" :key="i" :message="message" />
                    </div>

                    <!-- Selecionados -->
                    <div v-if="form.files.length" class="rounded-lg border border-line bg-surface-raised">
                        <div class="flex items-center justify-between border-b border-line-soft px-4 py-2.5">
                            <p class="text-[13px] font-medium text-content">
                                {{ form.files.length }} arquivo(s) — {{ formatBytes(totalSize) }}
                            </p>
                            <button
                                type="button"
                                class="text-[13px] text-content-muted transition hover:text-critical"
                                @click="form.files = []; form.expected_files = 0"
                            >
                                Limpar
                            </button>
                        </div>
                        <ul class="max-h-52 divide-y divide-line-soft overflow-y-auto">
                            <li
                                v-for="(file, index) in form.files"
                                :key="`${file.name}-${index}`"
                                class="flex items-center gap-3 px-4 py-2"
                            >
                                <span class="min-w-0 flex-1 truncate text-[13px] text-content">{{ file.name }}</span>
                                <span class="shrink-0 text-[12px] text-content-muted">{{ formatBytes(file.size) }}</span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-1 text-content-faint transition hover:text-critical"
                                    :aria-label="`Remover ${file.name}`"
                                    @click="removeFile(index)"
                                >
                                    <svg class="size-3.5" viewBox="0 0 14 14" fill="none">
                                        <path d="m3 3 8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div v-if="sending" class="space-y-1.5">
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-hover">
                            <div
                                class="h-full rounded-full bg-silver-200 transition-all"
                                :style="{ width: `${sendProgress}%` }"
                            />
                        </div>
                        <p class="text-[12px] text-content-muted">
                            Enviando {{ sentCount }} de {{ form.files.length }} —
                            <span class="font-mono">{{ currentName }}</span>
                        </p>
                    </div>

                    <InputError :message="uploadError" />

                    <AppButton type="submit" :loading="sending" :disabled="!form.files.length">
                        {{ sending ? 'Enviando…' : 'Converter' }}
                    </AppButton>
                </form>
            </SurfaceCard>

            <SurfaceCard title="Modelos reconhecidos" description="O modelo é identificado pelo registro 0000 do arquivo.">
                <ul class="space-y-2.5">
                    <li v-for="model in models" :key="model.value" class="flex items-start gap-2.5">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-silver-200" />
                        <span class="text-[13px] leading-relaxed text-content">{{ model.label }}</span>
                    </li>
                </ul>

                <div class="mt-5 rounded-lg border border-line bg-surface-overlay px-4 py-3">
                    <p class="text-[13px] leading-relaxed text-content">
                        A planilha traz uma aba por registro. As colunas
                        <span class="font-mono text-[12px]">ID_DT_INI</span>,
                        <span class="font-mono text-[12px]">ID_DT_FIN</span> e
                        <span class="font-mono text-[12px]">ID_CNPJ</span>
                        identificam o arquivo de origem de cada linha — é o que permite consolidar vários
                        períodos e depois separá-los de volta.
                    </p>
                </div>
            </SurfaceCard>
        </div>

        <SurfaceCard title="Conversões recentes" class="mt-4">
            <p v-if="!conversions.length" class="py-6 text-center text-[13px] text-content-muted">
                Nenhuma conversão ainda.
            </p>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-left text-[13px]">
                    <thead>
                        <tr class="border-b border-line-soft text-[12px] text-content-muted">
                            <th class="pb-2 pr-4 font-medium">Quando</th>
                            <th class="pb-2 pr-4 font-medium">Conversão</th>
                            <th class="pb-2 pr-4 font-medium">Modelo</th>
                            <th class="pb-2 pr-4 font-medium">Entrada</th>
                            <th class="pb-2 pr-4 font-medium">Resultado</th>
                            <th class="pb-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        <tr v-for="c in conversions" :key="c.id" class="align-top">
                            <td class="py-3 pr-4 whitespace-nowrap text-content">{{ formatDateTime(c.created_at) }}</td>
                            <td class="py-3 pr-4 text-content-strong">{{ c.direction_label }}</td>
                            <td class="py-3 pr-4 text-content">{{ c.model ?? '—' }}</td>
                            <td class="py-3 pr-4 text-content">
                                {{ c.input_count }} arquivo(s)
                                <span class="block max-w-[16rem] truncate text-[12px] text-content-muted">
                                    {{ c.input_names.join(', ') }}
                                </span>
                            </td>
                            <td class="py-3 pr-4">
                                <span v-if="c.running" class="block text-content">
                                    <span class="flex items-center gap-2">
                                        <svg class="size-3.5 shrink-0 animate-spin text-silver-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                        </svg>
                                        <template v-if="c.status === 'uploading'">Recebendo arquivos…</template>
                                        <template v-else-if="c.status === 'pending'">Na fila</template>
                                        <template v-else-if="c.input_count > 1">
                                            {{ formatNumber(c.processed_count) }} de {{ formatNumber(c.input_count) }}
                                            <span class="text-content-muted">
                                                ({{ formatNumber(c.input_count - c.processed_count) }} restantes)
                                            </span>
                                        </template>
                                        <template v-else>Convertendo…</template>
                                    </span>

                                    <span v-if="c.status === 'processing' && c.input_count > 1" class="mt-2 block">
                                        <span class="block h-1 w-44 overflow-hidden rounded-full bg-surface-hover">
                                            <span
                                                class="block h-full rounded-full bg-silver-300 transition-all duration-500"
                                                :style="{ width: `${c.progress}%` }"
                                            />
                                        </span>
                                        <span class="mt-1.5 block text-[12px] text-content-muted">
                                            {{ c.progress }}%<template v-if="c.eta"> · faltam ~{{ formatDuration(c.eta) }}</template>
                                        </span>
                                    </span>
                                </span>
                                <span v-else-if="c.status === 'done'" class="text-content">
                                    <template v-if="c.direction === 'to_spreadsheet'">
                                        {{ c.sheet_count }} abas · {{ formatNumber(c.row_count) }} linhas
                                    </template>
                                    <template v-else>arquivo .zip</template>
                                    <span class="block text-[12px] text-content-muted">
                                        {{ formatBytes(c.output_size) }}<template v-if="c.duration"> · {{ c.duration }}s</template>
                                    </span>
                                </span>
                                <span v-else class="text-critical">
                                    Falhou
                                    <span class="block max-w-[18rem] text-[12px] text-critical">{{ c.error_message }}</span>
                                </span>
                            </td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <a
                                    v-if="c.download_url"
                                    :href="c.download_url"
                                    class="font-semibold text-content-strong underline-offset-4 hover:text-silver-200 hover:underline"
                                >
                                    Baixar
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </SurfaceCard>
    </AppLayout>
</template>
