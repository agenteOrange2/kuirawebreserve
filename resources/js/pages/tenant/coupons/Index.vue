<script setup lang="ts">
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface CouponRow {
    id: number;
    code: string;
    kind: 'percent' | 'amount';
    value: number;
    label: string;
    starts_at: string | null;
    ends_at: string | null;
    max_uses: number | null;
    used_count: number;
    active: boolean;
    redeemable: boolean;
}

const props = defineProps<{
    coupons: CouponRow[];
    canManage: boolean;
}>();

const toast = useToasts();
const coupons = ref<CouponRow[]>([...props.coupons]);

const vigencyLabel = (coupon: CouponRow) => {
    if (!coupon.starts_at && !coupon.ends_at) return 'Siempre';
    if (coupon.starts_at && coupon.ends_at)
        return `${coupon.starts_at} → ${coupon.ends_at}`;
    if (coupon.starts_at) return `Desde ${coupon.starts_at}`;
    return `Hasta ${coupon.ends_at}`;
};

const usesLabel = (coupon: CouponRow) =>
    coupon.max_uses === null
        ? `${coupon.used_count} usos`
        : `${coupon.used_count} de ${coupon.max_uses}`;

// ── Modal crear/editar ──
const showForm = ref(false);
const editing = ref<CouponRow | null>(null);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    code: '',
    kind: 'percent' as 'percent' | 'amount',
    value: '' as string | number,
    starts_at: '',
    ends_at: '',
    max_uses: '' as string | number,
    active: true,
});

function openForm(coupon: CouponRow | null = null) {
    editing.value = coupon;
    form.code = coupon?.code ?? '';
    form.kind = coupon?.kind ?? 'percent';
    form.value = coupon?.value ?? '';
    form.starts_at = coupon?.starts_at ?? '';
    form.ends_at = coupon?.ends_at ?? '';
    form.max_uses = coupon?.max_uses ?? '';
    form.active = coupon?.active ?? true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    showForm.value = true;
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const payload = {
        code: form.code,
        kind: form.kind,
        value: form.value,
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        max_uses: form.max_uses === '' ? null : Number(form.max_uses),
        active: form.active,
    };
    try {
        if (editing.value) {
            const { data } = await axios.patch<CouponRow>(
                `/api/coupons/${editing.value.id}`,
                payload,
            );
            coupons.value = coupons.value.map((c) =>
                c.id === data.id ? data : c,
            );
            toast.success(
                'Cupón actualizado',
                'Las reservas que ya lo usaron conservan su descuento.',
            );
        } else {
            const { data } = await axios.post<CouponRow>(
                '/api/coupons',
                payload,
            );
            coupons.value = [data, ...coupons.value];
            toast.success(
                'Cupón creado',
                'El wizard ya lo acepta al reservar.',
            );
        }
        showForm.value = false;
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

async function toggleActive(coupon: CouponRow) {
    try {
        const { data } = await axios.patch<CouponRow>(
            `/api/coupons/${coupon.id}`,
            { active: !coupon.active },
        );
        coupons.value = coupons.value.map((c) => (c.id === data.id ? data : c));
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado.');
    }
}

// ── Eliminar ──
const deleting = ref<CouponRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/coupons/${deleting.value.id}`);
        coupons.value = coupons.value.filter(
            (c) => c.id !== deleting.value!.id,
        );
        toast.success(
            'Cupón eliminado',
            'Las reservas que ya lo usaron conservan su descuento congelado.',
        );
        deleting.value = null;
    } catch {
        toast.error('Error', 'No se pudo eliminar.');
    }
}
</script>

<template>
    <RazeLayout title="Cupones">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide
                            icon="TicketPercent"
                            class="h-5 w-5 sm:h-7 sm:w-7"
                        />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">Cupones</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Códigos de descuento que el huésped aplica al
                            reservar en línea. El uso se cuenta cuando la
                            reserva se confirma, no al apartar.
                        </p>
                    </div>
                </div>
                <Button
                    v-if="canManage"
                    variant="primary"
                    class="min-h-11 w-full rounded-[0.5rem] shadow-md shadow-primary/20 md:w-auto"
                    @click="openForm()"
                >
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo cupón
                </Button>
            </div>

            <div class="box box--stacked mt-5">
                <template v-if="coupons.length">
                    <!-- Móvil: tarjetas apiladas (patrón rooms/Index.vue) -->
                    <div class="space-y-2.5 p-5 sm:hidden">
                        <div
                            v-for="coupon in coupons"
                            :key="`card-${coupon.id}`"
                            class="rounded-lg border border-slate-200/70 bg-white p-3.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div
                                    class="min-w-0 truncate font-medium"
                                    :class="{
                                        'text-slate-400': !coupon.active,
                                    }"
                                >
                                    {{ coupon.code }}
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        coupon.redeemable
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{
                                        coupon.redeemable
                                            ? 'Vigente'
                                            : 'No aplica'
                                    }}
                                </span>
                            </div>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span class="font-medium text-slate-600">{{
                                    coupon.label
                                }}</span>
                                <span>{{ vigencyLabel(coupon) }}</span>
                                <span>{{ usesLabel(coupon) }}</span>
                            </div>
                            <div
                                v-if="canManage"
                                class="mt-3 flex items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                            >
                                <FormSwitch
                                    title="Solo los cupones activos se aceptan"
                                >
                                    <FormSwitch.Input
                                        :checked="coupon.active"
                                        type="checkbox"
                                        @change="toggleActive(coupon)"
                                    />
                                </FormSwitch>
                                <button
                                    type="button"
                                    class="ml-auto flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-slate-500 dark:border-darkmode-400"
                                    title="Editar"
                                    @click="openForm(coupon)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-danger dark:border-darkmode-400"
                                    title="Eliminar"
                                    @click="deleting = coupon"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Escritorio: tabla -->
                    <div
                        class="hidden overflow-auto p-5 sm:block lg:overflow-visible"
                    >
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th class="whitespace-nowrap"
                                        >Código</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Descuento</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Vigencia</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Usos</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Activo</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Acciones</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="coupon in coupons"
                                    :key="coupon.id"
                                >
                                    <Table.Td>
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                                            >
                                                <Lucide
                                                    icon="TicketPercent"
                                                    class="h-4 w-4 text-primary"
                                                />
                                            </div>
                                            <div
                                                class="font-medium"
                                                :class="{
                                                    'text-slate-400':
                                                        !coupon.active,
                                                }"
                                            >
                                                {{ coupon.code }}
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="font-medium">
                                        {{ coupon.label }}
                                        <span
                                            class="block text-xs font-normal text-slate-500"
                                        >
                                            {{
                                                coupon.kind === 'percent'
                                                    ? 'Porcentaje'
                                                    : 'Monto fijo'
                                            }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">{{
                                        vigencyLabel(coupon)
                                    }}</Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        {{ usesLabel(coupon) }}
                                        <span
                                            v-if="
                                                coupon.max_uses !== null &&
                                                coupon.used_count >=
                                                    coupon.max_uses
                                            "
                                            class="block text-xs text-warning"
                                            >Agotado</span
                                        >
                                    </Table.Td>
                                    <Table.Td>
                                        <FormSwitch
                                            v-if="canManage"
                                            title="Solo los cupones activos se aceptan al reservar"
                                        >
                                            <FormSwitch.Input
                                                :checked="coupon.active"
                                                type="checkbox"
                                                @change="toggleActive(coupon)"
                                            />
                                        </FormSwitch>
                                        <span
                                            v-else
                                            class="text-xs"
                                            :class="
                                                coupon.active
                                                    ? 'text-success'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                coupon.active
                                                    ? 'Activo'
                                                    : 'Pausado'
                                            }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>
                                        <div
                                            v-if="canManage"
                                            class="flex items-center justify-end gap-3"
                                        >
                                            <a
                                                href="#"
                                                class="flex items-center text-primary"
                                                title="Editar"
                                                @click.prevent="
                                                    openForm(coupon)
                                                "
                                            >
                                                <Lucide
                                                    icon="Pencil"
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                            <a
                                                href="#"
                                                class="flex items-center text-danger"
                                                title="Eliminar"
                                                @click.prevent="
                                                    deleting = coupon
                                                "
                                            >
                                                <Lucide
                                                    icon="Trash2"
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>
                </template>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-12 text-center"
                >
                    <Lucide
                        icon="TicketPercent"
                        class="h-10 w-10 text-slate-300"
                    />
                    <div>
                        <p class="text-sm font-medium text-slate-600">
                            Aún no tienes cupones
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Crea tu primer código de descuento y el wizard lo
                            aceptará al reservar en línea.
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        variant="primary"
                        class="rounded-[0.5rem]"
                        @click="openForm()"
                    >
                        <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nuevo cupón
                    </Button>
                </div>
            </div>
        </div>

        <!-- Modal crear/editar -->
        <Dialog :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submit">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="TicketPercent"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{
                                    editing
                                        ? `Editar ${editing.code}`
                                        : 'Nuevo cupón'
                                }}
                            </h2>
                            <p class="text-xs text-slate-500">
                                El descuento se congela en cada reserva al
                                momento de apartar.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm">Código</label>
                            <FormInput
                                v-model="form.code"
                                type="text"
                                class="uppercase"
                                placeholder="VERANO25"
                            />
                            <FormHelp v-if="errors.code" class="text-danger">{{
                                errors.code
                            }}</FormHelp>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm">Tipo</label>
                                <FormSelect v-model="form.kind">
                                    <option value="percent">
                                        Porcentaje (%)
                                    </option>
                                    <option value="amount">
                                        Monto fijo ($)
                                    </option>
                                </FormSelect>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">
                                    {{
                                        form.kind === 'percent'
                                            ? 'Porcentaje'
                                            : 'Monto ($)'
                                    }}
                                </label>
                                <FormInput
                                    v-model="form.value"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :placeholder="
                                        form.kind === 'percent'
                                            ? '10'
                                            : '200.00'
                                    "
                                />
                                <FormHelp
                                    v-if="errors.value"
                                    class="text-danger"
                                    >{{ errors.value }}</FormHelp
                                >
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Vigente desde (opcional)</label
                                >
                                <FormInput
                                    v-model="form.starts_at"
                                    type="date"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Vigente hasta (opcional)</label
                                >
                                <FormInput v-model="form.ends_at" type="date" />
                                <FormHelp
                                    v-if="errors.ends_at"
                                    class="text-danger"
                                    >{{ errors.ends_at }}</FormHelp
                                >
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Límite de usos (opcional)</label
                            >
                            <FormInput
                                v-model="form.max_uses"
                                type="number"
                                min="1"
                                placeholder="Sin límite"
                            />
                            <FormHelp
                                v-if="errors.max_uses"
                                class="text-danger"
                                >{{ errors.max_uses }}</FormHelp
                            >
                            <FormHelp v-else>
                                El uso se descuenta cuando la reserva se
                                confirma, no al apartar.
                            </FormHelp>
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <span class="text-sm">Activo</span>
                            <FormSwitch>
                                <FormSwitch.Input
                                    :checked="form.active"
                                    type="checkbox"
                                    @change="form.active = !form.active"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11"
                            :disabled="saving"
                        >
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editing
                                      ? 'Guardar cambios'
                                      : 'Crear cupón'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="AlertTriangle"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar "{{ deleting?.code }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Las reservas que ya lo usaron conservan su descuento
                        congelado. Si solo quieres dejar de aceptarlo, usa el
                        switch.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="min-h-11"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="min-h-11"
                            @click="destroy"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
