<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface GatewayLink {
    id: number;
    provider: string;
    provider_label: string;
    mode: string;
    public_key: string | null;
    masked_secret: string;
    has_webhook_secret: boolean;
    webhook_url: string;
    active: boolean;
    last_event_at: string | null;
}

const props = defineProps<{
    gateways: GatewayLink[];
    gatewayProviders: Record<string, string>;
    enabledMethods: Record<string, boolean>;
    hasCobrosModule: boolean;
    maxGateways: number | null;
}>();

const toast = useToasts();

const gateways = ref<GatewayLink[]>([...props.gateways]);
const gwBusy = ref<string | null>(null);
const gwForms = reactive<
    Record<
        string,
        {
            mode: string;
            public_key: string;
            secret_key: string;
            webhook_secret: string;
        }
    >
>({});

Object.keys(props.gatewayProviders).forEach((provider) => {
    const link = props.gateways.find((g) => g.provider === provider);
    gwForms[provider] = {
        mode: link?.mode ?? 'test',
        public_key: link?.public_key ?? '',
        secret_key: '',
        webhook_secret: '',
    };
});

const gatewayFor = (provider: string) =>
    gateways.value.find((g) => g.provider === provider);

// Filtro del admin (plataforma/hotel): un método apagado no se ofrece; una
// pasarela ya conectada pero deshabilitada queda visible solo informativa.
const providerAllowed = (provider: string) =>
    props.enabledMethods[provider] !== false;
// Array (no objeto) para que `provider` se tipe como string en el v-for:
// iterar las claves de un Record en la plantilla las degrada a string|number.
const visibleProviders = computed<{ provider: string; label: string }[]>(() =>
    Object.entries(props.gatewayProviders)
        .filter(([p]) => providerAllowed(p) || gatewayFor(p))
        .map(([provider, label]) => ({ provider, label })),
);

const gwFieldHints: Record<
    string,
    { public: string; secret: string; webhook: string }
> = {
    stripe: {
        public: 'Publishable key (pk_…)',
        secret: 'Secret key (sk_…)',
        webhook: 'Signing secret del webhook (whsec_…)',
    },
    mercadopago: {
        public: 'Public key (APP_USR-…)',
        secret: 'Access token (APP_USR-…)',
        webhook: 'Opcional: el evento se valida contra la API',
    },
    paypal: {
        public: 'Client ID',
        secret: 'Secret',
        webhook: 'Opcional: el pago se captura y valida contra la API',
    },
};

async function saveGateway(provider: string) {
    const gwForm = gwForms[provider];
    const existing = gatewayFor(provider);
    if (!existing && !gwForm.secret_key.trim()) {
        toast.error(
            'Falta la llave secreta',
            'Pega la llave secreta de tu cuenta para conectar.',
        );
        return;
    }
    gwBusy.value = provider;
    try {
        const payload = {
            mode: gwForm.mode,
            public_key: gwForm.public_key.trim() || null,
            secret_key: gwForm.secret_key.trim() || null,
            webhook_secret: gwForm.webhook_secret.trim() || null,
        };
        const { data } = existing
            ? await axios.patch<
                  GatewayLink & { test?: { ok: boolean; detail: string } }
              >(`/api/payment-gateways/${existing.id}`, payload)
            : await axios.post<
                  GatewayLink & { test?: { ok: boolean; detail: string } }
              >('/api/payment-gateways', {
                  ...payload,
                  provider,
                  secret_key: gwForm.secret_key.trim(),
              });
        gateways.value = [
            ...gateways.value.filter((g) => g.provider !== provider),
            data,
        ];
        gwForm.secret_key = '';
        gwForm.webhook_secret = '';
        if (data.test && !data.test.ok) {
            toast.error('Conectada, pero la prueba falló', data.test.detail);
        } else {
            toast.success(
                'Pasarela guardada',
                data.test?.detail ?? 'Credenciales actualizadas.',
            );
        }
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Revisa los datos de la pasarela.',
        );
    } finally {
        gwBusy.value = null;
    }
}

async function testGateway(link: GatewayLink) {
    gwBusy.value = link.provider;
    try {
        const { data } = await axios.post(
            `/api/payment-gateways/${link.id}/test`,
        );
        if (data.test.ok) {
            toast.success('Conexión correcta', data.test.detail);
        } else {
            toast.error('Fallo de conexión', data.test.detail);
        }
    } catch {
        toast.error('Error', 'No se pudo probar la conexión.');
    } finally {
        gwBusy.value = null;
    }
}

async function toggleGateway(link: GatewayLink) {
    try {
        const { data } = await axios.patch(`/api/payment-gateways/${link.id}`, {
            active: !link.active,
        });
        gateways.value = gateways.value.map((g) =>
            g.id === link.id ? { ...g, active: data.active } : g,
        );
        toast.success(
            data.active ? 'Pasarela activada' : 'Pasarela desactivada',
            data.canceled_requests
                ? `Se cancelaron ${data.canceled_requests} cobro(s) pendiente(s).`
                : undefined,
        );
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado de la pasarela.');
    }
}

async function copyWebhookUrl(link: GatewayLink) {
    try {
        await navigator.clipboard.writeText(link.webhook_url);
        toast.success(
            'URL copiada',
            'Pégala como webhook en el dashboard del proveedor.',
        );
    } catch {
        toast.error('No se pudo copiar', link.webhook_url);
    }
}
</script>

<template>
    <RazeLayout title="Pasarelas de pago">
        <div class="mt-2">
            <!-- Header de tarjeta, mismo patrón que Usuarios: icono en
                 círculo + título + acción a la derecha -->
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CreditCard" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">Pasarelas de pago</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Conecta tus cuentas para cobrar con link de pago: el
                            dinero llega directo a tu cuenta del proveedor.
                            <span class="font-medium"
                                >Con una pasarela activa, el asistente envía
                                links en lugar de datos de transferencia.</span
                            >
                            <span v-if="maxGateways !== null">
                                Tu plan permite hasta
                                {{ maxGateways }} pasarela(s)
                                conectada(s).</span
                            >
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.payment-methods')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Volver a Métodos de pago
                </Button>
            </div>

            <div class="box box--stacked mt-5 p-5">
                <div
                    v-if="!hasCobrosModule"
                    class="flex items-start gap-3 rounded-lg border border-dashed border-slate-300/70 p-5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50"
                    >
                        <Lucide icon="Lock" class="h-4 w-4" />
                    </div>
                    <div class="text-sm text-slate-500">
                        <div
                            class="font-medium text-slate-600 dark:text-slate-300"
                        >
                            Necesitas el módulo Cobros en línea
                        </div>
                        <p class="mt-0.5 text-xs">
                            Tu plan no incluye pasarelas de pago; puedes cobrar
                            por transferencia con las cuentas de
                            <Link
                                :href="
                                    route('tenant.payment-methods.transfers')
                                "
                                class="font-medium text-primary hover:underline"
                                >Pago por transferencia</Link
                            >. Solicita la activación del módulo desde la
                            tarjeta
                            <Link
                                :href="route('tenant.hotel-settings')"
                                class="font-medium text-primary hover:underline"
                                >Tu plan en Ajustes</Link
                            >.
                        </p>
                    </div>
                </div>

                <div v-else class="grid grid-cols-12 gap-4">
                    <div
                        v-for="{ provider, label } in visibleProviders"
                        :key="provider"
                        class="col-span-12 rounded-lg border border-slate-200/70 p-4 xl:col-span-6 dark:border-darkmode-400"
                    >
                        <div class="flex items-center gap-2.5">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    icon="CreditCard"
                                    class="h-4 w-4 text-primary"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ label }}</span>
                                    <span
                                        v-if="gatewayFor(provider)?.active"
                                        class="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-medium text-success"
                                        >Activa</span
                                    >
                                    <span
                                        v-else-if="gatewayFor(provider)"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >Pausada</span
                                    >
                                    <span
                                        v-if="
                                            gatewayFor(provider)?.mode ===
                                            'test'
                                        "
                                        class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning"
                                        >Modo prueba</span
                                    >
                                    <span
                                        v-if="!providerAllowed(provider)"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                                        >Deshabilitada por plataforma</span
                                    >
                                </div>
                                <p
                                    v-if="gatewayFor(provider)?.last_event_at"
                                    class="text-xs text-slate-500"
                                >
                                    Último evento del webhook:
                                    {{ gatewayFor(provider)?.last_event_at }}
                                </p>
                                <p
                                    v-else-if="gatewayFor(provider)"
                                    class="text-xs text-slate-500"
                                >
                                    Aún sin eventos del webhook.
                                </p>
                            </div>
                            <FormSwitch
                                v-if="
                                    gatewayFor(provider) &&
                                    providerAllowed(provider)
                                "
                                title="Pausar deja de ofrecer esta pasarela y cancela sus cobros pendientes"
                            >
                                <FormSwitch.Input
                                    :checked="gatewayFor(provider)?.active"
                                    type="checkbox"
                                    @change="
                                        toggleGateway(gatewayFor(provider)!)
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div
                            v-if="providerAllowed(provider)"
                            class="mt-4 space-y-3"
                        >
                            <div>
                                <label class="mb-1 block text-sm">{{
                                    gwFieldHints[provider]?.public
                                }}</label>
                                <FormInput
                                    v-model="gwForms[provider].public_key"
                                    type="text"
                                    placeholder="Llave pública"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">{{
                                    gwFieldHints[provider]?.secret
                                }}</label>
                                <FormInput
                                    v-model="gwForms[provider].secret_key"
                                    type="password"
                                    :placeholder="
                                        gatewayFor(provider)
                                            ? `Guardada (${gatewayFor(provider)?.masked_secret}) — escribe para reemplazar`
                                            : 'Llave secreta'
                                    "
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Secreto del webhook</label
                                >
                                <FormInput
                                    v-model="gwForms[provider].webhook_secret"
                                    type="password"
                                    :placeholder="
                                        gatewayFor(provider)?.has_webhook_secret
                                            ? 'Guardado — escribe para reemplazar'
                                            : gwFieldHints[provider]?.webhook
                                    "
                                />
                                <FormHelp>{{
                                    gwFieldHints[provider]?.webhook
                                }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Modo</label>
                                <FormSelect
                                    v-model="gwForms[provider].mode"
                                    class="!py-1.5 text-sm"
                                >
                                    <option value="test">
                                        Prueba (sandbox)
                                    </option>
                                    <option value="live">Producción</option>
                                </FormSelect>
                                <FormHelp>
                                    El entorno real lo dicta la llave: sk_test_
                                    = sandbox (tarjeta 4242), sk_live_ =
                                    producción con cobros reales. Este selector
                                    se corrige solo al guardar.
                                </FormHelp>
                            </div>

                            <div
                                v-if="gatewayFor(provider)"
                                class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-darkmode-700"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate font-mono text-xs text-slate-500"
                                    :title="gatewayFor(provider)?.webhook_url"
                                    >{{
                                        gatewayFor(provider)?.webhook_url
                                    }}</span
                                >
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-1 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                    title="Copiar URL del webhook"
                                    @click="
                                        copyWebhookUrl(gatewayFor(provider)!)
                                    "
                                >
                                    <Lucide icon="Copy" class="h-3.5 w-3.5" />
                                </button>
                            </div>

                            <div class="flex items-center gap-2 pt-1">
                                <Button
                                    variant="primary"
                                    size="sm"
                                    class="rounded-[0.5rem]"
                                    :disabled="gwBusy === provider"
                                    @click="saveGateway(provider)"
                                >
                                    <Lucide
                                        icon="Check"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        gwBusy === provider
                                            ? 'Guardando…'
                                            : gatewayFor(provider)
                                              ? 'Guardar cambios'
                                              : 'Conectar'
                                    }}
                                </Button>
                                <Button
                                    v-if="gatewayFor(provider)"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="rounded-[0.5rem] bg-white"
                                    title="Prueba las llaves GUARDADAS — si acabas de escribirlas, primero usa Guardar cambios"
                                    :disabled="gwBusy === provider"
                                    @click="testGateway(gatewayFor(provider)!)"
                                >
                                    <Lucide
                                        icon="PlugZap"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Probar conexión
                                </Button>
                            </div>
                            <FormHelp v-if="gatewayFor(provider)">
                                "Probar conexión" usa las llaves guardadas: si
                                acabas de escribir llaves nuevas, guarda
                                primero.
                            </FormHelp>
                        </div>
                        <p
                            v-else
                            class="mt-3 flex items-start gap-1.5 text-xs text-slate-400"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            La plataforma deshabilitó esta pasarela; sus cobros
                            pendientes se pausaron. Contacta a soporte si la
                            necesitas.
                        </p>
                    </div>
                </div>

                <div
                    class="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <Lucide
                        icon="ShieldCheck"
                        class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                    />
                    <span
                        >Las llaves se guardan cifradas. Registra la URL del
                        webhook en el dashboard del proveedor (en Stripe copia
                        también el signing secret) para que los pagos se
                        confirmen solos. Nadie del hotel ni el asistente ven
                        datos de tarjeta: el cobro ocurre en la página del
                        proveedor.</span
                    >
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
