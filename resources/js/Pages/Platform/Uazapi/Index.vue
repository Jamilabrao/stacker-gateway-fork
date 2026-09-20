<script setup>
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import { MessageCircle, ExternalLink } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    docs_url: { type: String, default: '' },
    instances: { type: Array, default: () => [] },
    recent_dispatches: { type: Array, default: () => [] },
});

function statusLabel(status) {
    return {
        connected: 'Conectado',
        connecting: 'Conectando',
        disconnected: 'Desconectado',
        hibernated: 'Hibernado',
    }[status] || status;
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                <MessageCircle class="h-7 w-7 text-[#25D366]" />
                WhatsApp (uazapi)
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Cada infoprodutor informa a própria Server URL e o token da instância na aba Integrações. Esta tela só acompanha as conexões.
            </p>
            <a
                v-if="docs_url"
                :href="docs_url"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-2 inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
            >
                Documentação uazapi
                <ExternalLink class="h-3.5 w-3.5" />
            </a>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Instâncias dos infoprodutores</h2>
            <div v-if="instances.length" class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                            <th class="px-2 py-2">Tenant</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="px-2 py-2">Número</th>
                            <th class="px-2 py-2">Perfil</th>
                            <th class="px-2 py-2">Carrinho</th>
                            <th class="px-2 py-2">PIX</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in instances" :key="row.tenant_id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="px-2 py-2 font-mono text-xs">{{ row.tenant_id }}</td>
                            <td class="px-2 py-2">{{ statusLabel(row.status) }}</td>
                            <td class="px-2 py-2">{{ row.phone || '—' }}</td>
                            <td class="px-2 py-2">{{ row.profile_name || '—' }}</td>
                            <td class="px-2 py-2">{{ row.cart_recovery_enabled ? 'Sim' : 'Não' }}</td>
                            <td class="px-2 py-2">{{ row.pix_recovery_enabled ? 'Sim' : 'Não' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-zinc-500">Nenhum infoprodutor conectou o WhatsApp ainda.</p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Últimos envios</h2>
            <div v-if="recent_dispatches.length" class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                            <th class="px-2 py-2">ID</th>
                            <th class="px-2 py-2">Evento</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="px-2 py-2">WhatsApp</th>
                            <th class="px-2 py-2">Tenant</th>
                            <th class="px-2 py-2">Erro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in recent_dispatches" :key="row.id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="px-2 py-2 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-2">{{ row.event_type }}</td>
                            <td class="px-2 py-2">{{ row.status }}</td>
                            <td class="px-2 py-2">{{ row.wa_status || '—' }}</td>
                            <td class="px-2 py-2 font-mono text-xs">{{ row.tenant_id }}</td>
                            <td class="px-2 py-2 text-xs text-red-600 dark:text-red-400">{{ row.error || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-zinc-500">Nenhum envio registrado.</p>
        </section>
    </div>
</template>
