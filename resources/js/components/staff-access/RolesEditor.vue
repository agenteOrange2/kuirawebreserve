<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, reactive, ref, useTemplateRef } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import type {
    PanelArea,
    RoleEndpoints,
    StaffAccessPayload,
    StaffRole,
} from './types';
import { roleIcon, roleTone } from './types';

/**
 * Editor de roles del hotel: qué áreas del panel ve cada rol y qué puede
 * hacer dentro de ellas. Lo usan el propietario (/usuarios/roles) y la
 * plataforma (/admin/tenants/{id}/roles); cada página le pasa a dónde
 * guardar. Un área se ve si el rol tiene el permiso que la abre y no la
 * tiene oculta: mostrarla enciende ese permiso, ocultarla no lo apaga
 * (otras pantallas pueden necesitarlo).
 */
const props = defineProps<
    StaffAccessPayload & {
        endpoints: RoleEndpoints;
        // Para los textos: "el hotel" visto desde la plataforma o "tu hotel".
        hotelName?: string;
    }
>();

const toast = useToasts();
const roles = ref<StaffRole[]>([...props.roles]);

const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const groupLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';

// ── Rol seleccionado y su borrador ──
const selectedId = ref<number | null>(
    roles.value.find((r) => !r.locked)?.id ?? roles.value[0]?.id ?? null,
);
const selected = computed(
    () => roles.value.find((r) => r.id === selectedId.value) ?? null,
);

const draft = reactive<{ permissions: string[]; hidden: string[] }>({
    permissions: [],
    hidden: [],
});

function loadDraft() {
    draft.permissions = [...(selected.value?.permissions ?? [])];
    draft.hidden = [...(selected.value?.hidden_areas ?? [])];
}
loadDraft();

const sameSet = (a: string[], b: string[]) =>
    a.length === b.length && a.every((x) => b.includes(x));

// Las áreas ocultas que el hotel ya no tiene (módulo apagado) no cuentan
// como cambio: el servidor las conserva tal cual.
const areaKeys = computed(() => props.areas.map((a) => a.key));
const dirty = computed(() => {
    if (!selected.value || selected.value.locked) return false;
    const savedHidden = selected.value.hidden_areas.filter((k) =>
        areaKeys.value.includes(k),
    );
    const draftHidden = draft.hidden.filter((k) => areaKeys.value.includes(k));
    return (
        !sameSet(draft.permissions, selected.value.permissions) ||
        !sameSet(draftHidden, savedHidden)
    );
});

// ── Visibilidad efectiva ──
const sees = (area: PanelArea, perms: string[], hidden: string[]) =>
    perms.includes(area.permission) && !hidden.includes(area.key);

const visibleNow = (area: PanelArea) =>
    selected.value?.locked || sees(area, draft.permissions, draft.hidden);

const visibleCount = (role: StaffRole) =>
    role.locked
        ? props.areas.length
        : props.areas.filter((a) =>
              sees(a, role.permissions, role.hidden_areas),
          ).length;

const draftVisibleCount = computed(
    () => props.areas.filter((a) => visibleNow(a)).length,
);

function setArea(area: PanelArea, on: boolean) {
    if (!selected.value || selected.value.locked) return;
    if (on) {
        draft.hidden = draft.hidden.filter((k) => k !== area.key);
        if (!draft.permissions.includes(area.permission)) {
            draft.permissions = [...draft.permissions, area.permission];
        }
    } else if (!draft.hidden.includes(area.key)) {
        draft.hidden = [...draft.hidden, area.key];
    }
}

function setGroup(group: string, on: boolean) {
    props.areas.filter((a) => a.group === group).forEach((a) => setArea(a, on));
}

function setCapability(key: string, on: boolean) {
    if (!selected.value || selected.value.locked) return;
    draft.permissions = on
        ? [...new Set([...draft.permissions, key])]
        : draft.permissions.filter((p) => p !== key);
}

const hasCapability = (key: string) =>
    selected.value?.locked || draft.permissions.includes(key);

const capabilityLabel = (key: string) =>
    props.capabilities.find((c) => c.key === key)?.label ?? key;

// Áreas visibles que dependen de un permiso: si se apaga, dejan de verse.
const areasUsing = (key: string) =>
    props.areas.filter(
        (a) => a.permission === key && !draft.hidden.includes(a.key),
    );

const areasByGroup = computed(() =>
    props.areaGroups
        .map((g) => ({
            ...g,
            areas: props.areas.filter((a) => a.group === g.key),
        }))
        .filter((g) => g.areas.length),
);

const capabilitiesByGroup = computed(() =>
    props.capabilityGroups
        .map((g) => ({
            ...g,
            items: props.capabilities.filter((c) => c.group === g.key),
        }))
        .filter((g) => g.items.length),
);

const groupState = (group: string) => {
    const list = props.areas.filter((a) => a.group === group);
    const on = list.filter((a) => visibleNow(a)).length;
    return { on, total: list.length };
};

// ── Pestañas del editor ──
const tab = ref<'areas' | 'capabilities'>('areas');

// ── Cambiar de rol con cambios sin guardar ──
const pendingSwitch = ref<number | null>(null);
const editorBox = useTemplateRef<HTMLElement>('editorBox');

function selectRole(id: number) {
    if (id === selectedId.value) return;
    if (dirty.value) {
        pendingSwitch.value = id;
        return;
    }
    switchTo(id);
}

function switchTo(id: number) {
    selectedId.value = id;
    pendingSwitch.value = null;
    loadDraft();
    // En el teléfono la lista va arriba: se baja al editor.
    if (window.matchMedia('(max-width: 1279px)').matches) {
        nextTick(() =>
            editorBox.value?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            }),
        );
    }
}

// ── Guardar áreas y permisos ──
const saving = ref(false);

function payloadFor(role: StaffRole, extra: Record<string, unknown> = {}) {
    return {
        label: role.label,
        description: role.description || null,
        permissions: draft.permissions,
        hidden_areas: draft.hidden,
        ...extra,
    };
}

function replaceRoles(list: StaffRole[]) {
    roles.value = list;
}

function errorMessage(e: any, fallback: string) {
    const d = e?.response?.data;
    if (d?.errors) {
        const first = Object.values(d.errors)[0] as string[] | undefined;
        if (first?.[0]) return first[0];
    }
    return d?.message ?? fallback;
}

async function save() {
    if (!selected.value || !dirty.value) return;
    saving.value = true;
    try {
        const { data } = await axios.put<StaffRole[]>(
            props.endpoints.update(selected.value.id),
            payloadFor(selected.value),
        );
        replaceRoles(data);
        loadDraft();
        toast.success(
            'Accesos guardados',
            `${selected.value?.label}: los cambios se aplican en su siguiente clic.`,
        );
    } catch (e) {
        toast.error('No se guardó', errorMessage(e, 'Intenta de nuevo.'));
    } finally {
        saving.value = false;
    }
}

// ── Crear y renombrar ──
const modal = ref<'create' | 'edit' | null>(null);
const form = reactive({ label: '', description: '', copyFrom: '' as string });
const formErrors = reactive<Record<string, string>>({});

function openCreate() {
    form.label = '';
    form.description = '';
    form.copyFrom = String(
        roles.value.find((r) => r.name === 'front-desk')?.id ?? '',
    );
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    modal.value = 'create';
}

function openEdit() {
    if (!selected.value) return;
    form.label = selected.value.label;
    form.description = selected.value.description ?? '';
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    modal.value = 'edit';
}

function closeModal() {
    if (!saving.value) modal.value = null;
}

const copySource = computed(() =>
    roles.value.find((r) => String(r.id) === form.copyFrom),
);

async function submitModal() {
    saving.value = true;
    Object.keys(formErrors).forEach((k) => delete formErrors[k]);
    try {
        if (modal.value === 'create') {
            const source = copySource.value;
            const all = props.capabilities.map((c) => c.key);
            const { data } = await axios.post<StaffRole[]>(
                props.endpoints.store,
                {
                    label: form.label,
                    description: form.description || null,
                    // Copiar del propietario es "todo": él no guarda lista.
                    permissions: source
                        ? source.locked
                            ? all
                            : source.permissions
                        : [],
                    hidden_areas:
                        source && !source.locked ? source.hidden_areas : [],
                },
            );
            const before = new Set(roles.value.map((r) => r.id));
            replaceRoles(data);
            const created = data.find((r) => !before.has(r.id));
            if (created) {
                selectedId.value = created.id;
                loadDraft();
            }
            toast.success(
                'Rol creado',
                `${form.label}: ya lo puedes asignar en Usuarios.`,
            );
        } else if (selected.value) {
            // Renombrar guarda también lo que esté pendiente en el editor.
            const { data } = await axios.put<StaffRole[]>(
                props.endpoints.update(selected.value.id),
                payloadFor(selected.value, {
                    label: form.label,
                    description: form.description || null,
                }),
            );
            replaceRoles(data);
            loadDraft();
            toast.success('Rol actualizado', form.label);
        }
        modal.value = null;
    } catch (e: any) {
        const d = e?.response?.data;
        if (d?.errors) {
            Object.entries(d.errors).forEach(
                ([k, msgs]) => (formErrors[k] = (msgs as string[])[0]),
            );
        } else {
            formErrors._ = d?.message ?? 'No se pudo guardar el rol.';
        }
    } finally {
        saving.value = false;
    }
}

// ── Eliminar ──
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function openDelete() {
    deleteError.value = null;
    deleting.value = true;
}

async function destroy() {
    if (!selected.value) return;
    saving.value = true;
    deleteError.value = null;
    try {
        const gone = selected.value;
        const { data } = await axios.delete<StaffRole[]>(
            props.endpoints.destroy(gone.id),
        );
        replaceRoles(data);
        selectedId.value =
            data.find((r) => !r.locked)?.id ?? data[0]?.id ?? null;
        loadDraft();
        deleting.value = false;
        toast.success('Rol eliminado', gone.label);
    } catch (e) {
        deleteError.value = errorMessage(e, 'No se pudo eliminar el rol.');
    } finally {
        saving.value = false;
    }
}

const peopleLabel = (n: number) =>
    n === 0 ? 'Nadie lo tiene' : n === 1 ? '1 persona' : `${n} personas`;
</script>

<template>
    <div class="mt-4 grid grid-cols-12 items-start gap-5">
        <!-- Roles -->
        <div class="col-span-12 xl:sticky xl:top-24 xl:col-span-4">
            <div class="box box--stacked">
                <div :class="cardHeader">
                    <div
                        :class="sectionIcon"
                        class="border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShieldCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Roles</div>
                        <div class="text-xs text-slate-500">
                            {{ roles.length }} roles del hotel
                        </div>
                    </div>
                    <Button
                        variant="primary"
                        class="h-8 rounded-[0.5rem] px-3 text-xs shadow-md shadow-primary/20"
                        @click="openCreate"
                    >
                        <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                        Nuevo rol
                    </Button>
                </div>

                <div
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <button
                        v-for="role in roles"
                        :key="role.id"
                        type="button"
                        class="flex w-full items-center gap-3 border-l-2 px-4 py-3 text-left transition"
                        :class="
                            role.id === selectedId
                                ? 'border-primary bg-primary/5'
                                : 'border-transparent hover:bg-slate-50 dark:hover:bg-darkmode-400/40'
                        "
                        @click="selectRole(role.id)"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="roleTone(role.name)"
                        >
                            <Lucide
                                :icon="roleIcon(role.name)"
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{
                                    role.label
                                }}</span>
                                <span
                                    v-if="!role.system"
                                    class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400 dark:text-slate-300"
                                    >Propio</span
                                >
                                <span
                                    v-if="role.id === selectedId && dirty"
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning"
                                    title="Cambios sin guardar"
                                />
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ peopleLabel(role.users) }}
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-xs font-medium">
                                {{
                                    role.id === selectedId
                                        ? draftVisibleCount
                                        : visibleCount(role)
                                }}<span class="font-normal text-slate-400">
                                    / {{ areas.length }}</span
                                >
                            </div>
                            <div class="text-[11px] text-slate-400">áreas</div>
                        </div>
                    </button>
                </div>

                <p
                    class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                >
                    El rol de cada persona se asigna en Usuarios. Solo aparecen
                    las áreas de los módulos que
                    {{ hotelName ? hotelName : 'el hotel' }} tiene activos.
                </p>
            </div>
        </div>

        <!-- Editor -->
        <div ref="editorBox" class="col-span-12 scroll-mt-24 xl:col-span-8">
            <div v-if="selected" class="box box--stacked overflow-hidden">
                <!-- Identidad del rol -->
                <div
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:p-5"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="roleTone(selected.name)"
                        >
                            <Lucide
                                :icon="roleIcon(selected.name)"
                                class="h-5 w-5"
                            />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <h2 class="truncate text-base font-medium">
                                    {{ selected.label }}
                                </h2>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        selected.system
                                            ? 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300'
                                            : 'bg-primary/10 text-primary'
                                    "
                                    >{{
                                        selected.system
                                            ? 'De fábrica'
                                            : 'Propio del hotel'
                                    }}</span
                                >
                            </div>
                            <p
                                class="mt-0.5 line-clamp-2 text-xs text-slate-500"
                            >
                                {{ selected.description || 'Sin descripción.' }}
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="!selected.locked"
                        class="grid grid-cols-2 gap-2 sm:flex sm:shrink-0"
                    >
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="openEdit"
                        >
                            <Lucide icon="Pencil" class="mr-1.5 h-3.5 w-3.5" />
                            Nombre
                        </Button>
                        <Button
                            v-if="!selected.system"
                            variant="outline-danger"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="openDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar
                        </Button>
                    </div>
                </div>

                <!-- Aviso del propietario -->
                <div
                    v-if="selected.locked"
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-primary/5 px-5 py-3 text-xs text-primary dark:border-darkmode-400"
                >
                    <Lucide
                        icon="LockKeyhole"
                        class="mt-px h-3.5 w-3.5 shrink-0"
                    />
                    El propietario ve y puede todo, y es el único que cambia
                    roles. No se edita para que el hotel nunca se quede sin
                    quién lo administre.
                </div>

                <!-- Datos duros + pestañas -->
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-2 text-xs sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 transition"
                            :class="
                                tab === 'areas'
                                    ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-400'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = 'areas'"
                        >
                            <Lucide icon="Eye" class="h-3.5 w-3.5" />
                            Qué ve
                            <span class="text-[11px] text-slate-400"
                                >{{ draftVisibleCount }}/{{
                                    areas.length
                                }}</span
                            >
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 transition"
                            :class="
                                tab === 'capabilities'
                                    ? 'bg-white font-medium text-primary shadow-sm dark:bg-darkmode-400'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = 'capabilities'"
                        >
                            <Lucide icon="KeyRound" class="h-3.5 w-3.5" />
                            Qué puede hacer
                            <span class="text-[11px] text-slate-400"
                                >{{
                                    selected.locked
                                        ? capabilities.length
                                        : draft.permissions.filter((p) =>
                                              capabilities.some(
                                                  (c) => c.key === p,
                                              ),
                                          ).length
                                }}/{{ capabilities.length }}</span
                            >
                        </button>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 text-slate-500 md:ml-auto"
                    >
                        <Lucide icon="Users" class="h-3.5 w-3.5" />
                        <span
                            class="font-medium text-slate-700 dark:text-slate-300"
                            >{{ peopleLabel(selected.users) }}</span
                        >
                    </span>
                </div>

                <!-- Qué ve -->
                <div
                    v-if="tab === 'areas'"
                    class="border-t border-slate-200/60 dark:border-darkmode-400"
                >
                    <section
                        v-for="group in areasByGroup"
                        :key="group.key"
                        class="border-b border-slate-200/60 last:border-b-0 dark:border-darkmode-400"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 pt-3 pb-1.5 sm:px-5"
                        >
                            <span :class="groupLabel">{{ group.label }}</span>
                            <span
                                class="text-[11px] whitespace-nowrap text-slate-400"
                                >{{ groupState(group.key).on }} de
                                {{ groupState(group.key).total }}</span
                            >
                            <div
                                v-if="!selected.locked"
                                class="ml-auto flex items-center gap-3 text-[11px] whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="font-medium text-primary disabled:text-slate-300"
                                    :disabled="
                                        groupState(group.key).on ===
                                        groupState(group.key).total
                                    "
                                    @click="setGroup(group.key, true)"
                                >
                                    Mostrar todas
                                </button>
                                <button
                                    type="button"
                                    class="font-medium text-slate-500 hover:text-danger disabled:text-slate-300"
                                    :disabled="groupState(group.key).on === 0"
                                    @click="setGroup(group.key, false)"
                                >
                                    Ocultar todas
                                </button>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <label
                                v-for="area in group.areas"
                                :key="area.key"
                                class="flex items-center gap-3 px-4 py-2.5 sm:px-5"
                                :class="
                                    selected.locked
                                        ? ''
                                        : 'cursor-pointer hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30'
                                "
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border transition"
                                    :class="
                                        visibleNow(area)
                                            ? 'border-primary/10 bg-primary/10 text-primary'
                                            : 'border-slate-200 bg-slate-50 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-600'
                                    "
                                >
                                    <Lucide
                                        :icon="
                                            visibleNow(area)
                                                ? area.icon
                                                : 'EyeOff'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm"
                                        :class="
                                            visibleNow(area)
                                                ? 'font-medium'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{ area.label }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ area.description }}
                                    </div>
                                    <div
                                        v-if="
                                            !selected.locked &&
                                            !draft.hidden.includes(area.key) &&
                                            !draft.permissions.includes(
                                                area.permission,
                                            )
                                        "
                                        class="mt-0.5 text-[11px] text-slate-400"
                                    >
                                        Oculta porque no tiene el permiso "{{
                                            capabilityLabel(area.permission)
                                        }}"
                                    </div>
                                </div>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        type="checkbox"
                                        :checked="!!visibleNow(area)"
                                        :disabled="selected.locked"
                                        @change="
                                            setArea(area, !visibleNow(area))
                                        "
                                    />
                                </FormSwitch>
                            </label>
                        </div>
                    </section>
                </div>

                <!-- Qué puede hacer -->
                <div
                    v-else
                    class="border-t border-slate-200/60 dark:border-darkmode-400"
                >
                    <p
                        class="border-b border-slate-200/60 px-4 py-2.5 text-xs text-slate-500 sm:px-5 dark:border-darkmode-400"
                    >
                        Lo que el rol puede hacer dentro de las áreas que ve.
                        Sin "Crear, cobrar, modificar y cancelar reservas", por
                        ejemplo, ve las reservas pero no las toca.
                    </p>
                    <section
                        v-for="group in capabilitiesByGroup"
                        :key="group.key"
                        class="border-b border-slate-200/60 last:border-b-0 dark:border-darkmode-400"
                    >
                        <div class="px-4 pt-3 pb-1.5 sm:px-5">
                            <span :class="groupLabel">{{ group.label }}</span>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <label
                                v-for="cap in group.items"
                                :key="cap.key"
                                class="flex items-center gap-3 px-4 py-2.5 sm:px-5"
                                :class="
                                    selected.locked
                                        ? ''
                                        : 'cursor-pointer hover:bg-slate-50/70 dark:hover:bg-darkmode-400/30'
                                "
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm"
                                        :class="
                                            hasCapability(cap.key)
                                                ? 'font-medium'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{ cap.label }}
                                    </div>
                                    <div
                                        v-if="cap.description"
                                        class="text-xs text-slate-500"
                                    >
                                        {{ cap.description }}
                                    </div>
                                    <div
                                        v-if="
                                            !selected.locked &&
                                            areasUsing(cap.key).length
                                        "
                                        class="mt-0.5 text-[11px]"
                                        :class="
                                            hasCapability(cap.key)
                                                ? 'text-slate-400'
                                                : 'text-warning'
                                        "
                                    >
                                        {{
                                            hasCapability(cap.key)
                                                ? 'Abre: '
                                                : 'Sin esto no ve: '
                                        }}{{
                                            areasUsing(cap.key)
                                                .map((a) => a.label)
                                                .join(', ')
                                        }}
                                    </div>
                                </div>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        type="checkbox"
                                        :checked="!!hasCapability(cap.key)"
                                        :disabled="selected.locked"
                                        @change="
                                            setCapability(
                                                cap.key,
                                                !hasCapability(cap.key),
                                            )
                                        "
                                    />
                                </FormSwitch>
                            </label>
                        </div>
                    </section>
                </div>

                <!-- Pie: guardar -->
                <div
                    v-if="!selected.locked"
                    class="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-slate-200/70 bg-white/95 px-4 py-3 backdrop-blur sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/95"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-xs"
                        :class="dirty ? 'text-warning' : 'text-slate-400'"
                    >
                        <Lucide
                            :icon="dirty ? 'CircleDot' : 'CircleCheck'"
                            class="h-3.5 w-3.5"
                        />
                        {{ dirty ? 'Cambios sin guardar' : 'Todo guardado' }}
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-4 text-xs"
                            :disabled="!dirty || saving"
                            @click="loadDraft"
                        >
                            <Lucide icon="Undo2" class="mr-1.5 h-3.5 w-3.5" />
                            Descartar
                        </Button>
                        <Button
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            :disabled="!dirty || saving"
                            @click="save"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{ saving ? 'Guardando...' : 'Guardar cambios' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Crear / renombrar -->
    <Dialog :open="modal !== null" size="lg" @close="closeModal">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submitModal"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="modal === 'create' ? 'CopyPlus' : 'Pencil'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-base font-medium">
                            {{
                                modal === 'create'
                                    ? 'Nuevo rol'
                                    : `Editar ${selected?.label ?? ''}`
                            }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                modal === 'create'
                                    ? 'Parte de un rol parecido y luego ajusta qué ve.'
                                    : 'El nombre que ve el personal en Usuarios.'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="closeModal"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label
                            for="role-label"
                            class="mb-1.5 block text-xs font-medium"
                            >Nombre del rol</label
                        >
                        <FormInput
                            id="role-label"
                            v-model="form.label"
                            type="text"
                            maxlength="60"
                            class="h-9 text-xs"
                            placeholder="Recepción de noche"
                        />
                        <FormHelp v-if="formErrors.label" class="text-danger">{{
                            formErrors.label
                        }}</FormHelp>
                    </div>
                    <div>
                        <label
                            for="role-description"
                            class="mb-1.5 block text-xs font-medium"
                            >Para qué es</label
                        >
                        <FormTextarea
                            id="role-description"
                            v-model="form.description"
                            rows="2"
                            maxlength="200"
                            class="text-xs"
                            placeholder="Cubre el turno de 11 pm a 7 am: llegadas tarde y caja."
                        />
                        <FormHelp
                            v-if="formErrors.description"
                            class="text-danger"
                            >{{ formErrors.description }}</FormHelp
                        >
                    </div>
                    <div v-if="modal === 'create'">
                        <label
                            for="role-copy"
                            class="mb-1.5 block text-xs font-medium"
                            >Partir de</label
                        >
                        <FormSelect
                            id="role-copy"
                            v-model="form.copyFrom"
                            class="h-9 text-xs"
                        >
                            <option value="">
                                Ninguno: empieza sin ver nada
                            </option>
                            <option
                                v-for="r in roles"
                                :key="r.id"
                                :value="String(r.id)"
                            >
                                {{ r.label }}
                            </option>
                        </FormSelect>
                        <FormHelp class="text-slate-500">
                            Copia lo que ve y lo que puede hacer ese rol;
                            después lo ajustas sin tocar el original.
                        </FormHelp>
                    </div>
                    <p
                        v-if="formErrors._"
                        class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ formErrors._ }}
                    </p>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="saving"
                        @click="closeModal"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                        :disabled="saving || !form.label.trim()"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{
                            saving
                                ? 'Guardando...'
                                : modal === 'create'
                                  ? 'Crear rol'
                                  : 'Guardar'
                        }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>

    <!-- Cambios sin guardar al cambiar de rol -->
    <Dialog :open="pendingSwitch !== null" @close="pendingSwitch = null">
        <Dialog.Panel>
            <div class="p-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="CircleDot" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            {{ selected?.label }} tiene cambios sin guardar
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Si cambias de rol ahora se pierden. Guárdalos o
                            descártalos.
                        </p>
                    </div>
                </div>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="pendingSwitch = null"
                        >Seguir editando</Button
                    >
                    <Button
                        variant="outline-danger"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="
                            pendingSwitch !== null && switchTo(pendingSwitch)
                        "
                        >Descartar y cambiar</Button
                    >
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>

    <!-- Eliminar rol -->
    <Dialog :open="deleting" @close="!saving && (deleting = false)">
        <Dialog.Panel>
            <div v-if="selected" class="p-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="Trash2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            Eliminar el rol {{ selected.label }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Solo se puede si nadie lo tiene. A quien lo tenga,
                            cámbiale el rol primero en Usuarios.
                        </p>
                        <p
                            v-if="deleteError"
                            class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ deleteError }}
                        </p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="saving"
                        @click="deleting = false"
                        >Cancelar</Button
                    >
                    <Button
                        variant="danger"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="saving"
                        @click="destroy"
                    >
                        <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                        {{ saving ? 'Eliminando...' : 'Sí, eliminarlo' }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
