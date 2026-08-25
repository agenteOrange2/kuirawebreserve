<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface BankAccount {
    bank: string;
    holder: string;
    clabe: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        bank_accounts: BankAccount[];
        transfer_whatsapps: { code: string; number: string }[];
    };
    enabledMethods: Record<string, boolean>;
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    bank_accounts: props.settings.bank_accounts.map((a) => ({
        ...a,
    })) as BankAccount[],
    transfer_whatsapps: props.settings.transfer_whatsapps.map((w) => ({
        ...w,
    })),
});

// ── Alta y edición en modal ──
// La lista muestra estado (banco, titular, CLABE enmascarada); capturar
// datos pasa al modal. Antes cada cuenta eran cuatro campos abiertos en
// fila: con tres o cuatro cuentas la pantalla era un muro de inputs.
const accountModal = ref(false);
const editingIndex = ref<number | null>(null);
const draft = reactive<BankAccount>({
    bank: '',
    holder: '',
    clabe: '',
    active: true,
});

function openAccount(index: number | null) {
    editingIndex.value = index;
    Object.assign(
        draft,
        index === null
            ? { bank: '', holder: '', clabe: '', active: true }
            : { ...form.bank_accounts[index] },
    );
    Object.keys(errors).forEach((k) => delete errors[k]);
    accountModal.value = true;
}

// Cada acción persiste sola: no hay un botón "Guardar" al final que el
// hotel pueda olvidar después de capturar una cuenta.
async function saveAccount() {
    if (!draft.bank.trim() || !draft.holder.trim() || !draft.clabe.trim()) {
        toast.error(
            'Faltan datos',
            'Banco, titular y CLABE son necesarios para compartir la cuenta.',
        );
        return;
    }

    if (editingIndex.value === null) {
        form.bank_accounts.push({ ...draft });
    } else {
        form.bank_accounts[editingIndex.value] = { ...draft };
    }

    const ok = await submit();
    if (ok) accountModal.value = false;
}

async function removeBankAccount(index: number) {
    const [removed] = form.bank_accounts.splice(index, 1);
    const ok = await submit();

    if (!ok) form.bank_accounts.splice(index, 0, removed); // revertir
}

async function toggleAccount(index: number) {
    const account = form.bank_accounts[index];
    account.active = !account.active;

    const ok = await submit();
    if (!ok) account.active = !account.active;
}

// Si el servidor rechaza la cuenta, el motivo se ve DENTRO del modal: al
// mudar los campos aquí, los mensajes por campo se habían quedado fuera.
const accountError = computed(
    () =>
        Object.entries(errors).find(([key]) =>
            key.startsWith('bank_accounts'),
        )?.[1] ?? null,
);

// ── WhatsApp para comprobantes: mismo trato que las cuentas ──
const whatsappModal = ref(false);
const editingWhatsapp = ref<number | null>(null);
const whatsappDraft = reactive({ code: '52', number: '' });

function openWhatsapp(index: number | null) {
    editingWhatsapp.value = index;
    Object.assign(
        whatsappDraft,
        index === null
            ? { code: '52', number: '' }
            : { ...form.transfer_whatsapps[index] },
    );
    whatsappModal.value = true;
}

async function saveWhatsapp() {
    const digits = whatsappDraft.number.replace(/\D/g, '');

    if (digits.length < 10) {
        toast.error(
            'Número incompleto',
            'Escribe los 10 dígitos del WhatsApp.',
        );
        return;
    }

    const entry = { code: whatsappDraft.code, number: digits };

    if (editingWhatsapp.value === null) {
        form.transfer_whatsapps.push(entry);
    } else {
        form.transfer_whatsapps[editingWhatsapp.value] = entry;
    }

    const ok = await submit();
    if (ok) whatsappModal.value = false;
}

async function removeWhatsapp(index: number) {
    const [removed] = form.transfer_whatsapps.splice(index, 1);
    const ok = await submit();

    if (!ok) form.transfer_whatsapps.splice(index, 0, removed);
}

// Como lo lee una persona: +52 656 750 9087.
const prettyPhone = (code: string, number: string) => {
    const d = number.replace(/\D/g, '');

    return d.length === 10
        ? `+${code} ${d.slice(0, 3)} ${d.slice(3, 6)} ${d.slice(6)}`
        : `+${code} ${d}`;
};

// Últimos 4 dígitos: la CLABE completa no tiene por qué estar a la vista
// de quien pase junto a la pantalla de recepción.
const maskedClabe = (clabe: string) => {
    const digits = clabe.replace(/\D/g, '');

    return digits.length > 4 ? `•••• ${digits.slice(-4)}` : clabe;
};

async function submit(): Promise<boolean> {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH de settings hace merge en el backend: mandar solo este
        // subconjunto no pisa lo demás.
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                bank_accounts: form.bank_accounts,
                transfer_whatsapps: form.transfer_whatsapps.filter(
                    (w) => w.number.trim() !== '',
                ),
            },
        });
        toast.success(
            'Guardado',
            'Las cuentas para transferencia se actualizaron.',
        );

        return true;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('settings.', '')] = (
                        msgs as string[]
                    )[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }

        return false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Pago por transferencia">
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
                        <Lucide icon="Landmark" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">
                            Pago por transferencia
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Cuentas que se comparten al huésped para pagar por
                            transferencia (anticipos y saldos).
                            <span class="font-medium"
                                >El asistente IA las entrega tal cual al
                                solicitar un pago; el comprobante siempre lo
                                verifica tu equipo.</span
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
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Métodos de pago
                </Button>
            </div>

            <div class="box box--stacked mt-5 flex flex-col p-5">
                <p
                    v-if="enabledMethods.transfer === false"
                    class="mb-4 flex items-start gap-1.5 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <Lucide
                        icon="Info"
                        class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                    />
                    Las transferencias bancarias no están habilitadas para tu
                    hotel; el asistente no las ofrecerá. Contacta a la
                    plataforma si las necesitas.
                </p>

                <div
                    v-if="
                        enabledMethods.transfer !== false &&
                        form.bank_accounts.length
                    "
                    class="flex flex-col gap-2"
                >
                    <div
                        v-for="(account, index) in form.bank_accounts"
                        :key="index"
                        class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Landmark" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    account.bank || 'Sin banco'
                                }}</span>
                                <span
                                    v-if="!account.active"
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                    >Pausada</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ account.holder || 'Sin titular' }} ·
                                {{ maskedClabe(account.clabe) }}
                            </p>
                        </div>
                        <FormSwitch
                            title="Solo las cuentas activas se comparten al huésped"
                        >
                            <FormSwitch.Input
                                :checked="account.active"
                                type="checkbox"
                                :disabled="saving"
                                @change="toggleAccount(index)"
                            />
                        </FormSwitch>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            size="sm"
                            class="shrink-0 rounded-[0.5rem] bg-white"
                            @click="openAccount(index)"
                        >
                            <Lucide
                                icon="Settings"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Editar
                        </Button>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                            title="Quitar cuenta"
                            :disabled="saving"
                            @click="removeBankAccount(index)"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <div
                    v-else-if="enabledMethods.transfer !== false"
                    class="rounded-lg border border-dashed border-slate-300/70 px-4 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                >
                    Sin cuentas registradas: no se podrá ofrecer pago por
                    transferencia.
                </div>

                <div v-if="enabledMethods.transfer !== false" class="mt-3">
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                        @click="openAccount(null)"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                        Agregar cuenta
                    </Button>
                </div>

                <!-- A dónde manda el huésped su comprobante -->
                <div
                    v-if="enabledMethods.transfer !== false"
                    class="mt-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <div class="text-sm font-medium">
                        WhatsApp para comprobantes
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Los wizards le dicen al huésped que mande su comprobante
                        de transferencia a estos WhatsApp, con link directo.
                        Puedes tener varios (línea de México, de Estados
                        Unidos...). Sin números: solo se le dice que el hotel lo
                        contactará.
                    </p>
                    <div
                        v-if="form.transfer_whatsapps.length"
                        class="mt-3 flex flex-col gap-2"
                    >
                        <div
                            v-for="(entry, index) in form.transfer_whatsapps"
                            :key="index"
                            class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200/70 bg-white p-3 dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Phone" class="h-3.5 w-3.5" />
                            </div>
                            <span class="min-w-0 flex-1 text-sm">
                                {{ prettyPhone(entry.code, entry.number) }}
                            </span>
                            <Button
                                type="button"
                                variant="outline-secondary"
                                size="sm"
                                class="shrink-0 rounded-[0.5rem] bg-white"
                                @click="openWhatsapp(index)"
                            >
                                <Lucide
                                    icon="Settings"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Editar
                            </Button>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Quitar este número"
                                :disabled="saving"
                                @click="removeWhatsapp(index)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-xs text-slate-500">
                        Sin números: al huésped solo se le dice que el hotel lo
                        contactará.
                    </p>
                    <Button
                        v-if="form.transfer_whatsapps.length < 5"
                        type="button"
                        variant="outline-secondary"
                        size="sm"
                        class="mt-3 rounded-[0.5rem] bg-white"
                        @click="openWhatsapp(null)"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Agregar número
                    </Button>
                </div>
            </div>
        </div>
        <!-- Alta y edición de la cuenta en modal: la lista se queda con el
             estado, la captura pasa aquí. -->
        <Dialog :open="accountModal" @close="accountModal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Landmark" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        {{
                            editingIndex === null
                                ? 'Agregar cuenta'
                                : 'Editar cuenta'
                        }}
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <div class="space-y-3">
                        <p
                            v-if="accountError"
                            class="rounded-md bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ accountError }}
                        </p>
                        <div>
                            <label class="mb-1 block text-sm">Banco</label>
                            <FormInput
                                v-model="draft.bank"
                                type="text"
                                placeholder="BBVA"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Titular</label>
                            <FormInput
                                v-model="draft.holder"
                                type="text"
                                placeholder="Como aparece en el estado de cuenta"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >CLABE / cuenta</label
                            >
                            <FormInput
                                v-model="draft.clabe"
                                type="text"
                                placeholder="18 dígitos"
                            />
                            <FormHelp>
                                Es lo que se le comparte al huésped para que
                                haga su transferencia: revísalo con cuidado.
                            </FormHelp>
                        </div>
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="draft.active"
                                type="checkbox"
                            />
                            <FormSwitch.Label class="ml-2 text-sm">
                                Compartir esta cuenta a los huéspedes
                            </FormSwitch.Label>
                        </FormSwitch>
                    </div>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="accountModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="saving"
                        @click="saveAccount"
                    >
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>

        <!-- Número de WhatsApp: mismo trato que la cuenta bancaria. -->
        <Dialog :open="whatsappModal" @close="whatsappModal = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Phone" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        {{
                            editingWhatsapp === null
                                ? 'Agregar número'
                                : 'Editar número'
                        }}
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-sm">País</label>
                            <FormSelect v-model="whatsappDraft.code">
                                <option value="52">+52 México</option>
                                <option value="1">+1 EE. UU. / Canadá</option>
                            </FormSelect>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">WhatsApp</label>
                            <FormInput
                                v-model="whatsappDraft.number"
                                type="tel"
                                placeholder="10 dígitos"
                            />
                            <FormHelp>
                                Aquí llegan los comprobantes de transferencia:
                                usa un número que alguien revise.
                            </FormHelp>
                        </div>
                    </div>
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="whatsappModal = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-28"
                        :disabled="saving"
                        @click="saveWhatsapp"
                    >
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
