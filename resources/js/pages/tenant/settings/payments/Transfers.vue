<script setup lang="ts">
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
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

function addBankAccount() {
    form.bank_accounts.push({ bank: '', holder: '', clabe: '', active: true });
}

function removeBankAccount(index: number) {
    form.bank_accounts.splice(index, 1);
}

async function submit() {
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
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Volver a Métodos de pago
                </Button>
            </div>

            <form
                class="box box--stacked mt-5 flex flex-col p-5"
                @submit.prevent="submit"
            >
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
                    class="flex flex-col gap-3"
                >
                    <div
                        v-for="(account, index) in form.bank_accounts"
                        :key="index"
                        class="grid grid-cols-12 items-end gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                    >
                        <div class="col-span-12 sm:col-span-3">
                            <label class="mb-1 block text-sm">Banco</label>
                            <FormInput
                                v-model="account.bank"
                                type="text"
                                placeholder="BBVA"
                            />
                            <FormHelp
                                v-if="errors[`bank_accounts.${index}.bank`]"
                                class="text-danger"
                                >{{
                                    errors[`bank_accounts.${index}.bank`]
                                }}</FormHelp
                            >
                        </div>
                        <div class="col-span-12 sm:col-span-4">
                            <label class="mb-1 block text-sm">Titular</label>
                            <FormInput
                                v-model="account.holder"
                                type="text"
                                placeholder="Hotel Demo Centro SA de CV"
                            />
                            <FormHelp
                                v-if="errors[`bank_accounts.${index}.holder`]"
                                class="text-danger"
                                >{{
                                    errors[`bank_accounts.${index}.holder`]
                                }}</FormHelp
                            >
                        </div>
                        <div class="col-span-12 sm:col-span-3">
                            <label class="mb-1 block text-sm"
                                >CLABE / cuenta</label
                            >
                            <FormInput
                                v-model="account.clabe"
                                type="text"
                                placeholder="18 dígitos"
                            />
                            <FormHelp
                                v-if="errors[`bank_accounts.${index}.clabe`]"
                                class="text-danger"
                                >{{
                                    errors[`bank_accounts.${index}.clabe`]
                                }}</FormHelp
                            >
                        </div>
                        <div
                            class="col-span-12 flex items-center justify-between gap-3 sm:col-span-2 sm:justify-end"
                        >
                            <FormSwitch
                                title="Solo las cuentas activas se comparten al huésped"
                            >
                                <FormSwitch.Input
                                    :checked="account.active"
                                    type="checkbox"
                                    @change="account.active = !account.active"
                                />
                            </FormSwitch>
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Quitar cuenta"
                                @click="removeBankAccount(index)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
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
                        @click="addBankAccount"
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
                    <div class="mt-2 space-y-2">
                        <div
                            v-for="(entry, index) in form.transfer_whatsapps"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <FormSelect v-model="entry.code" class="!w-44">
                                <option value="52">+52 México</option>
                                <option value="1">+1 EE. UU. / Canadá</option>
                            </FormSelect>
                            <FormInput
                                v-model="entry.number"
                                type="tel"
                                placeholder="10 dígitos"
                                class="sm:!w-56"
                            />
                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                title="Quitar este número"
                                @click="form.transfer_whatsapps.splice(index, 1)"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <Button
                        v-if="form.transfer_whatsapps.length < 5"
                        type="button"
                        variant="outline-secondary"
                        size="sm"
                        class="mt-2 rounded-[0.5rem] bg-white"
                        @click="
                            form.transfer_whatsapps.push({
                                code: '52',
                                number: '',
                            })
                        "
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Agregar número
                    </Button>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
