<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { Loader2, Plus, Trash2, X, BarChart3 } from 'lucide-vue-next';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const { t } = useI18n();

const loading = ref(false);
const saving = ref(false);
const connecting = ref(false);
const disconnecting = ref(false);
const testing = ref(false);
const errorMessage = ref(null);
const successMessage = ref(null);
const testPhone = ref('');
const testMessage = ref('');
const testResult = ref(null);
const credentialsConfigured = ref(false);
const recentDispatches = ref([]);
const pollTimer = ref(null);

const defaultSteps = () => [
    { delay_value: 10, delay_unit: 'minutes', message: 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}' },
    { delay_value: 24, delay_unit: 'hours', message: 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}' },
    { delay_value: 2, delay_unit: 'days', message: 'Oi {nome}! Seu {produto} ainda está disponível. Finalize aqui: {link}' },
];

const defaultPixSteps = () => [
    { delay_value: 30, delay_unit: 'minutes', message: '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}' },
    { delay_value: 2, delay_unit: 'hours', message: '{nome}, ainda dá tempo de pagar o PIX de {valor} para {produto}: {link}' },
];

const instance = ref({
    status: 'disconnected',
    phone: null,
    profile_name: null,
    qrcode: null,
    paircode: null,
    server_url: '',
    has_token: false,
    has_credentials: false,
    is_active: true,
    cart_recovery_enabled: false,
    pix_recovery_enabled: false,
    send_product_image: true,
    cart_recovery_steps: defaultSteps(),
    pix_recovery_steps: defaultPixSteps(),
    message_pix: '{nome}, seu PIX de {valor} para {produto} está pronto. Pague para concluir: {link}',
    last_error: null,
    connected: false,
    has_instance: false,
});
const instanceToken = ref('');

const delayUnits = [
    { value: 'minutes', label: 'Minutos' },
    { value: 'hours', label: 'Horas' },
    { value: 'days', label: 'Dias' },
];

const statusLabel = computed(() => {
    return {
        connected: 'Conectado',
        connecting: 'Aguardando leitura do QR',
        disconnected: 'Desconectado',
        hibernated: 'Hibernado',
    }[instance.value.status] || instance.value.status;
});

const qrSrc = computed(() => {
    const qr = instance.value.qrcode;
    if (!qr) return null;
    return String(qr).startsWith('data:') ? qr : `data:image/png;base64,${qr}`;
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            load();
        } else {
            stopPoll();
            errorMessage.value = null;
            successMessage.value = null;
            testResult.value = null;
        }
    }
);

watch(
    () => instance.value.status,
    (status) => {
        if (props.open && status === 'connecting') {
            startPoll();
        } else {
            stopPoll();
        }
    }
);

onUnmounted(() => stopPoll());

function applyPayload(data) {
    credentialsConfigured.value = Boolean(data.credentials_configured ?? data.instance?.has_credentials);
    if (data.instance) {
        instance.value = {
            ...instance.value,
            ...data.instance,
            cart_recovery_steps: data.instance.cart_recovery_steps?.length
                ? data.instance.cart_recovery_steps
                : defaultSteps(),
            pix_recovery_steps: Array.isArray(data.instance.pix_recovery_steps)
                ? data.instance.pix_recovery_steps
                : defaultPixSteps(),
        };
        if (!data.instance.has_token) {
            instanceToken.value = '';
        }
    }
    recentDispatches.value = data.recent_dispatches || [];
}

async function load() {
    loading.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.get('/integracoes/uazapi');
        applyPayload(data);
    } catch (err) {
        errorMessage.value = err.response?.data?.message || 'Não foi possível carregar a integração.';
    } finally {
        loading.value = false;
    }
}

async function refreshStatus() {
    try {
        const { data } = await axios.get('/integracoes/uazapi/status');
        applyPayload(data);
    } catch {
        // polling silencioso
    }
}

function startPoll() {
    if (pollTimer.value) return;
    pollTimer.value = setInterval(refreshStatus, 3000);
}

function stopPoll() {
    if (pollTimer.value) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

async function connect() {
    connecting.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        if (!instance.value.has_credentials || instanceToken.value.trim() || instance.value.server_url) {
            const payload = {
                server_url: instance.value.server_url,
                is_active: instance.value.is_active,
                cart_recovery_enabled: instance.value.cart_recovery_enabled,
                pix_recovery_enabled: instance.value.pix_recovery_enabled,
                send_product_image: instance.value.send_product_image,
                message_pix: instance.value.message_pix,
                cart_recovery_steps: instance.value.cart_recovery_steps,
                pix_recovery_steps: instance.value.pix_recovery_steps,
            };
            if (instanceToken.value.trim()) {
                payload.instance_token = instanceToken.value.trim();
            }
            const saved = await axios.put('/integracoes/uazapi', payload);
            applyPayload(saved.data);
            instanceToken.value = '';
        }
        const { data } = await axios.post('/integracoes/uazapi/connect');
        applyPayload(data);
        successMessage.value = instance.value.connected
            ? 'WhatsApp conectado.'
            : 'Leia o QR Code no WhatsApp do celular (Aparelhos conectados).';
    } catch (err) {
        if (err.response?.data?.instance) {
            applyPayload(err.response.data);
        }
        errorMessage.value = err.response?.data?.message || 'Não foi possível iniciar a conexão.';
    } finally {
        connecting.value = false;
    }
}

async function disconnect() {
    disconnecting.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.post('/integracoes/uazapi/disconnect');
        applyPayload(data);
        successMessage.value = 'WhatsApp desconectado.';
    } catch (err) {
        errorMessage.value = err.response?.data?.message || 'Não foi possível desconectar.';
    } finally {
        disconnecting.value = false;
    }
}

function addRecoveryStep() {
    if (instance.value.cart_recovery_steps.length >= 10) return;
    const last = instance.value.cart_recovery_steps.at(-1);
    instance.value.cart_recovery_steps.push({
        delay_value: last?.delay_value || 1,
        delay_unit: last?.delay_unit === 'minutes' ? 'hours' : 'days',
        message: last?.message || defaultSteps()[0].message,
    });
}

function removeRecoveryStep(index) {
    if (instance.value.cart_recovery_steps.length <= 1) return;
    instance.value.cart_recovery_steps.splice(index, 1);
}

function addPixRecoveryStep() {
    if (instance.value.pix_recovery_steps.length >= 10) return;
    const last = instance.value.pix_recovery_steps.at(-1);
    instance.value.pix_recovery_steps.push({
        delay_value: last?.delay_value || 30,
        delay_unit: last?.delay_unit === 'minutes' ? 'hours' : 'days',
        message: last?.message || defaultPixSteps()[0].message,
    });
}

function removePixRecoveryStep(index) {
    instance.value.pix_recovery_steps.splice(index, 1);
}

function onDelayValueInput(step, event) {
    const digits = String(event.target.value || '').replace(/\D+/g, '');
    step.delay_value = Math.max(1, Number.parseInt(digits || '1', 10));
}

async function save() {
    saving.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        const payload = {
            server_url: instance.value.server_url,
            is_active: instance.value.is_active,
            cart_recovery_enabled: instance.value.cart_recovery_enabled,
            pix_recovery_enabled: instance.value.pix_recovery_enabled,
            send_product_image: instance.value.send_product_image,
            message_pix: instance.value.message_pix,
            cart_recovery_steps: instance.value.cart_recovery_steps,
            pix_recovery_steps: instance.value.pix_recovery_steps,
        };
        if (instanceToken.value.trim()) {
            payload.instance_token = instanceToken.value.trim();
        }
        const { data } = await axios.put('/integracoes/uazapi', payload);
        applyPayload(data);
        instanceToken.value = '';
        successMessage.value = 'Configurações salvas.';
    } catch (err) {
        if (err.response?.data?.instance) {
            applyPayload(err.response.data);
        }
        errorMessage.value =
            err.response?.data?.message
            || err.response?.data?.errors?.server_url?.[0]
            || err.response?.data?.errors?.cart_recovery_steps?.[0]
            || 'Não foi possível salvar.';
    } finally {
        saving.value = false;
    }
}

async function persistActive(checked) {
    instance.value.is_active = Boolean(checked);
    await save();
}

async function sendTest() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post('/integracoes/uazapi/test', {
            phone: testPhone.value,
            message: testMessage.value,
        });
        testResult.value = { success: data.success, message: 'Mensagem de teste enviada.' };
    } catch (err) {
        testResult.value = {
            success: false,
            message: err.response?.data?.message || 'Falha no envio de teste.',
        };
    } finally {
        testing.value = false;
    }
}

function close() {
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside class="relative flex h-full w-full max-w-lg flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900">
                <div class="flex items-center justify-between rounded-tl-2xl bg-zinc-50/80 px-5 py-4 dark:bg-zinc-800/50">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">WhatsApp</h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-700"
                        :aria-label="t('common.close', 'Fechar')"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto p-5">
                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Cole a Server URL e o token da sua instância uazapi. Depois conecte o WhatsApp para recuperar carrinhos abandonados e PIX gerados e não pagos.
                    </p>
                    <a
                        href="/relatorios/whatsapp"
                        class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-[var(--color-primary)] hover:underline"
                    >
                        <BarChart3 class="h-4 w-4" />
                        Painel de conversão
                    </a>

                    <p v-if="loading" class="text-sm text-zinc-500">Carregando…</p>

                    <div v-else class="space-y-6">
                        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">Credenciais da uazapi</p>
                                <p class="text-xs text-zinc-500">
                                    No painel uazapi copie a Server URL da conta. Depois abra a instância e copie o token dela.
                                    O admintoken do servidor não funciona aqui.
                                </p>
                                <p v-if="credentialsConfigured" class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                    Credenciais salvas.
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Server URL</label>
                                <input
                                    v-model="instance.server_url"
                                    type="url"
                                    placeholder="https://seu-subdominio.uazapi.com"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Token da instância</label>
                                <input
                                    v-model="instanceToken"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="instance.has_token ? 'Cole de novo só se quiser trocar o token' : 'Cole o token da instância'"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                />
                            </div>
                        </section>

                        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">Conexão</p>
                                    <p class="text-xs text-zinc-500">{{ statusLabel }}</p>
                                </div>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="instance.connected
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                        : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                                >
                                    {{ instance.connected ? 'Ativo' : statusLabel }}
                                </span>
                            </div>

                            <p v-if="instance.phone" class="mb-2 text-sm text-zinc-700 dark:text-zinc-300">
                                Número: <span class="font-mono">{{ instance.phone }}</span>
                                <span v-if="instance.profile_name"> · {{ instance.profile_name }}</span>
                            </p>

                            <div v-if="qrSrc && !instance.connected" class="mb-3 flex flex-col items-center gap-2">
                                <img :src="qrSrc" alt="QR Code WhatsApp" class="h-48 w-48 rounded-lg border border-zinc-200 bg-white p-2 dark:border-zinc-700" />
                                <p class="text-center text-xs text-zinc-500">
                                    WhatsApp → Aparelhos conectados → Conectar um aparelho
                                </p>
                                <p v-if="instance.paircode" class="font-mono text-sm">Código: {{ instance.paircode }}</p>
                            </div>

                            <p v-if="instance.last_error" class="mb-2 text-xs text-red-600 dark:text-red-400">{{ instance.last_error }}</p>

                            <div class="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    :disabled="connecting || !(instance.has_credentials || (instance.server_url && instanceToken))"
                                    @click="connect"
                                >
                                    <Loader2 v-if="connecting" class="mr-2 h-4 w-4 animate-spin" />
                                    {{ instance.connected ? 'Reconectar' : 'Conectar WhatsApp' }}
                                </Button>
                                <Button
                                    v-if="instance.has_instance"
                                    size="sm"
                                    variant="outline"
                                    :disabled="disconnecting"
                                    @click="disconnect"
                                >
                                    Desconectar
                                </Button>
                            </div>
                        </section>

                        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">Recuperação de carrinho</p>
                                    <p class="text-xs text-zinc-500">Mensagens após o abandono do checkout. Placeholders: {nome}, {produto}, {link}</p>
                                </div>
                                <Toggle v-model="instance.cart_recovery_enabled" />
                            </div>

                            <div
                                v-for="(step, index) in instance.cart_recovery_steps"
                                :key="index"
                                class="rounded-lg border border-zinc-100 p-3 dark:border-zinc-700"
                            >
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Mensagem {{ index + 1 }}</p>
                                    <button
                                        v-if="instance.cart_recovery_steps.length > 1"
                                        type="button"
                                        class="text-xs text-red-500"
                                        @click="removeRecoveryStep(index)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <div class="mb-2 grid grid-cols-[5rem_minmax(0,1fr)] gap-2">
                                    <input
                                        :value="step.delay_value"
                                        type="text"
                                        inputmode="numeric"
                                        class="h-9 rounded-lg border border-zinc-300 px-2 text-center text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                        @input="onDelayValueInput(step, $event)"
                                    />
                                    <select
                                        v-model="step.delay_unit"
                                        class="h-9 rounded-lg border border-zinc-300 px-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                    >
                                        <option v-for="unit in delayUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                    </select>
                                </div>
                                <textarea
                                    v-model="step.message"
                                    rows="2"
                                    maxlength="1000"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                />
                            </div>

                            <Button type="button" size="sm" variant="outline" :disabled="instance.cart_recovery_steps.length >= 10" @click="addRecoveryStep">
                                <Plus class="mr-1 h-4 w-4" />
                                Adicionar mensagem
                            </Button>
                        </section>

                        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">PIX gerado e não pago</p>
                                    <p class="text-xs text-zinc-500">Placeholders: {nome}, {produto}, {valor}, {link}, {pix}</p>
                                </div>
                                <Toggle v-model="instance.pix_recovery_enabled" />
                            </div>
                            <textarea
                                v-model="instance.message_pix"
                                rows="3"
                                maxlength="1000"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            />
                            <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Lembretes se o PIX continuar pendente</p>
                            <div
                                v-for="(step, index) in instance.pix_recovery_steps"
                                :key="`pix-${index}`"
                                class="rounded-lg border border-zinc-100 p-3 dark:border-zinc-700"
                            >
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Lembrete {{ index + 1 }}</p>
                                    <button
                                        type="button"
                                        class="text-xs text-red-500"
                                        @click="removePixRecoveryStep(index)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <div class="mb-2 grid grid-cols-[5rem_minmax(0,1fr)] gap-2">
                                    <input
                                        :value="step.delay_value"
                                        type="text"
                                        inputmode="numeric"
                                        class="h-9 rounded-lg border border-zinc-300 px-2 text-center text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                        @input="onDelayValueInput(step, $event)"
                                    />
                                    <select
                                        v-model="step.delay_unit"
                                        class="h-9 rounded-lg border border-zinc-300 px-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                    >
                                        <option v-for="unit in delayUnits" :key="unit.value" :value="unit.value">{{ unit.label }}</option>
                                    </select>
                                </div>
                                <textarea
                                    v-model="step.message"
                                    rows="2"
                                    maxlength="1000"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                                />
                            </div>
                            <Button type="button" size="sm" variant="outline" :disabled="instance.pix_recovery_steps.length >= 10" @click="addPixRecoveryStep">
                                <Plus class="mr-1 h-4 w-4" />
                                Adicionar lembrete
                            </Button>
                            <p class="text-xs text-zinc-500">
                                Qualquer resposta pausa a sequência. Palavras como parar, stop ou não quero cancelam envios futuros neste número.
                            </p>
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-700">
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">Enviar imagem do produto</p>
                                    <p class="text-xs text-zinc-500">Capa do produto antes do texto e do botão de checkout.</p>
                                </div>
                                <Toggle v-model="instance.send_product_image" />
                            </div>
                        </section>

                        <section class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">Teste de envio</p>
                            <input
                                v-model="testPhone"
                                type="text"
                                placeholder="11999999999"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            />
                            <input
                                v-model="testMessage"
                                type="text"
                                placeholder="Mensagem opcional"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            />
                            <Button size="sm" variant="outline" :disabled="testing || !instance.connected" @click="sendTest">
                                Enviar teste
                            </Button>
                            <p
                                v-if="testResult"
                                class="text-xs"
                                :class="testResult.success ? 'text-emerald-600' : 'text-red-600'"
                            >
                                {{ testResult.message }}
                            </p>
                        </section>

                        <p v-if="errorMessage" class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            {{ errorMessage }}
                        </p>
                        <p v-if="successMessage" class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                            {{ successMessage }}
                        </p>

                        <div class="flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50/80 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <div>
                                <span class="block text-sm font-medium text-zinc-900 dark:text-white">Integração ativa</span>
                                <span class="text-xs text-zinc-500">Pausa todos os disparos sem desconectar o número.</span>
                            </div>
                            <Toggle :model-value="instance.is_active" @update:model-value="persistActive" />
                        </div>

                        <Button class="w-full" :disabled="saving" @click="save">
                            <Loader2 v-if="saving" class="mr-2 h-4 w-4 animate-spin" />
                            Salvar recuperação
                        </Button>

                        <section v-if="recentDispatches.length" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <p class="mb-2 text-sm font-medium text-zinc-900 dark:text-white">Últimos envios</p>
                            <ul class="space-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                                <li v-for="row in recentDispatches" :key="row.id">
                                    {{ row.event_type }} · {{ row.status }}<span v-if="row.wa_status"> · {{ row.wa_status }}</span>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
