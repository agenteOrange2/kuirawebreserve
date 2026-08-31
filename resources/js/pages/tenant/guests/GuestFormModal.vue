<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormDate,
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';

interface MediaItem {
    id: number;
    name: string;
    url: string;
}
interface Vehicle {
    plate?: string | null;
    brand?: string | null;
    model?: string | null;
    color?: string | null;
    year?: number | null;
    notes?: string | null;
}
interface GuestData {
    id: number;
    first_name: string | null;
    last_name: string | null;
    phone: string | null;
    email: string | null;
    birth_date: string | null;
    nationality: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    id_document_type: string | null;
    id_document_number: string | null;
    notes: string | null;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
    marketing_consent: boolean;
}

const props = defineProps<{
    open: boolean;
    guest?: GuestData | null;
    documentTypes: string[];
    canViewDocuments: boolean;
    documents?: MediaItem[];
    vehiclePhotos?: MediaItem[];
    vehicle?: Vehicle | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved', id: number): void;
}>();

const isEdit = computed(() => !!props.guest);
const docLabels: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro',
};

type SectionKey = 'contact' | 'details' | 'vehicle';
const steps: {
    key: SectionKey;
    title: string;
    short: string;
    desc: string;
    icon: 'User' | 'IdCard' | 'Car';
}[] = [
    {
        key: 'contact',
        title: 'Contacto',
        short: 'Contacto',
        desc: 'Nombre, teléfono y correo.',
        icon: 'User',
    },
    {
        key: 'details',
        title: 'Identificación y dirección',
        short: 'Identificación',
        desc: 'Documento, fotos y residencia.',
        icon: 'IdCard',
    },
    {
        key: 'vehicle',
        title: 'Vehículo y notas',
        short: 'Vehículo',
        desc: 'Acceso, notas y alertas.',
        icon: 'Car',
    },
];

const blank = () => ({
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    birth_date: '',
    nationality: '',
    address: '',
    city: '',
    state: '',
    zip: '',
    id_document_type: '' as string,
    id_document_number: '',
    notes: '',
    is_blacklisted: false,
    blacklist_reason: '',
    marketing_consent: false,
});
const form = reactive(blank());
const vehicleForm = reactive<Vehicle>({
    plate: '',
    brand: '',
    model: '',
    color: '',
    year: null,
    notes: '',
});

const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const generalError = ref<string | null>(null);
const activeSection = ref<SectionKey>('contact');
const stepIndex = computed(() =>
    steps.findIndex((s) => s.key === activeSection.value),
);

function goStep(delta: number): void {
    const next = steps[stepIndex.value + delta];
    if (next) activeSection.value = next.key;
}

// Fotos existentes (edición) y nuevas por subir (staged, ambos modos).
const existingIne = ref<MediaItem[]>([]);
const existingVehicle = ref<MediaItem[]>([]);
interface Staged {
    file: File;
    url: string;
}
const stagedIne = ref<Staged[]>([]);
const stagedVehicle = ref<Staged[]>([]);
const phoneCountry = ref<'mx' | 'us' | 'other'>('mx');
const phoneDialCode = ref('+52');
const phoneNationalNumber = ref('');

// Marca en el riel los pasos que ya tienen información capturada.
const stepDone = computed<Record<SectionKey, boolean>>(() => ({
    contact: form.first_name.trim() !== '',
    details: !!(
        form.id_document_type ||
        form.id_document_number ||
        form.address ||
        form.city ||
        form.state ||
        form.zip ||
        existingIne.value.length ||
        stagedIne.value.length
    ),
    vehicle: !!(
        vehicleForm.plate ||
        vehicleForm.brand ||
        vehicleForm.model ||
        vehicleForm.color ||
        vehicleForm.year ||
        vehicleForm.notes ||
        form.notes ||
        existingVehicle.value.length ||
        stagedVehicle.value.length
    ),
}));

const guestPhonePreview = computed(() => {
    const number = phoneNationalNumber.value.replace(/\D/g, '');

    if (!number) {
        return '';
    }

    if (phoneCountry.value === 'other') {
        const dialCode = phoneDialCode.value.replace(/\D/g, '');

        if (!dialCode) {
            return phoneNationalNumber.value.trim().startsWith('+')
                ? `+${number}`
                : '';
        }

        return `+${dialCode}${number}`;
    }

    return `+${phoneCountry.value === 'mx' ? '52' : '1'}${number}`;
});

function syncGuestPhone(): void {
    form.phone = guestPhonePreview.value;
}

function changePhoneCountry(): void {
    phoneDialCode.value =
        phoneCountry.value === 'mx'
            ? '+52'
            : phoneCountry.value === 'us'
              ? '+1'
              : '';
    syncGuestPhone();
}

function setPhoneFields(phone: string | null): void {
    const value = phone?.trim() ?? '';

    if (value.startsWith('+52')) {
        phoneCountry.value = 'mx';
        phoneDialCode.value = '+52';
        phoneNationalNumber.value = value.slice(3);
    } else if (value.startsWith('+1')) {
        phoneCountry.value = 'us';
        phoneDialCode.value = '+1';
        phoneNationalNumber.value = value.slice(2);
    } else if (value.startsWith('+')) {
        phoneCountry.value = 'other';
        phoneDialCode.value = '';
        phoneNationalNumber.value = value;
    } else {
        phoneCountry.value = 'mx';
        phoneDialCode.value = '+52';
        phoneNationalNumber.value = value;
    }

    form.phone = value;
}

function resetFrom() {
    activeSection.value = 'contact';
    Object.assign(form, blank());
    Object.assign(vehicleForm, {
        plate: '',
        brand: '',
        model: '',
        color: '',
        year: null,
        notes: '',
    });
    if (props.guest) {
        Object.assign(form, {
            first_name: props.guest.first_name ?? '',
            last_name: props.guest.last_name ?? '',
            phone: props.guest.phone ?? '',
            email: props.guest.email ?? '',
            birth_date: props.guest.birth_date ?? '',
            nationality: props.guest.nationality ?? '',
            address: props.guest.address ?? '',
            city: props.guest.city ?? '',
            state: props.guest.state ?? '',
            zip: props.guest.zip ?? '',
            id_document_type: props.guest.id_document_type ?? '',
            id_document_number: props.guest.id_document_number ?? '',
            notes: props.guest.notes ?? '',
            is_blacklisted: props.guest.is_blacklisted,
            blacklist_reason: props.guest.blacklist_reason ?? '',
            marketing_consent: props.guest.marketing_consent,
        });
    }
    setPhoneFields(props.guest?.phone ?? null);
    if (props.vehicle) Object.assign(vehicleForm, props.vehicle);
    existingIne.value = [...(props.documents ?? [])];
    existingVehicle.value = [...(props.vehiclePhotos ?? [])];
    stagedIne.value.forEach((s) => URL.revokeObjectURL(s.url));
    stagedVehicle.value.forEach((s) => URL.revokeObjectURL(s.url));
    stagedIne.value = [];
    stagedVehicle.value = [];
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}

watch(
    () => props.open,
    (open) => {
        if (open) resetFrom();
    },
);

function stageFiles(event: Event, target: 'ine' | 'vehicle') {
    const files = (event.target as HTMLInputElement).files;
    if (!files) return;
    const bucket = target === 'ine' ? stagedIne : stagedVehicle;
    Array.from(files).forEach((file) => {
        if (file.type.startsWith('image/'))
            bucket.value.push({ file, url: URL.createObjectURL(file) });
    });
    (event.target as HTMLInputElement).value = '';
}

function removeStaged(target: 'ine' | 'vehicle', index: number) {
    const bucket = target === 'ine' ? stagedIne : stagedVehicle;
    URL.revokeObjectURL(bucket.value[index].url);
    bucket.value.splice(index, 1);
}

async function removeExisting(target: 'ine' | 'vehicle', media: MediaItem) {
    if (!props.guest) return;
    await axios.delete(`/api/guests/${props.guest.id}/documents/${media.id}`);
    const bucket = target === 'ine' ? existingIne : existingVehicle;
    bucket.value = bucket.value.filter((m) => m.id !== media.id);
}

async function uploadStaged(guestId: number) {
    const jobs: Promise<unknown>[] = [];
    const push = (bucket: Staged[], collection: string) => {
        bucket.forEach((s) => {
            const fd = new FormData();
            fd.append('file', s.file);
            fd.append('collection', collection);
            jobs.push(axios.post(`/api/guests/${guestId}/documents`, fd));
        });
    };
    push(stagedIne.value, 'documents');
    push(stagedVehicle.value, 'vehicle');
    if (jobs.length) await Promise.all(jobs);
}

const vehiclePayload = () => ({
    plate: vehicleForm.plate || null,
    brand: vehicleForm.brand || null,
    model: vehicleForm.model || null,
    color: vehicleForm.color || null,
    year: vehicleForm.year || null,
    notes: vehicleForm.notes || null,
});

async function submit() {
    saving.value = true;
    generalError.value = null;
    Object.keys(errors).forEach((k) => delete errors[k]);

    const payload = {
        first_name: form.first_name,
        last_name: form.last_name || null,
        phone: guestPhonePreview.value || form.phone || null,
        email: form.email || null,
        birth_date: form.birth_date || null,
        nationality: form.nationality || null,
        address: form.address || null,
        city: form.city || null,
        state: form.state || null,
        zip: form.zip || null,
        id_document_type: form.id_document_type || null,
        id_document_number: form.id_document_number || null,
        notes: form.notes || null,
        is_blacklisted: form.is_blacklisted,
        blacklist_reason: form.is_blacklisted ? form.blacklist_reason : null,
        marketing_consent: form.marketing_consent,
        vehicle: vehiclePayload(),
    };

    try {
        let id: number;
        if (props.guest) {
            await axios.patch(`/api/guests/${props.guest.id}`, payload);
            id = props.guest.id;
        } else {
            const { data } = await axios.post('/api/guests', payload);
            id = data.id;
        }
        await uploadStaged(id);
        emit('saved', id);
    } catch (error: any) {
        const data = error.response?.data;
        if (data?.errors) {
            const errorKeys = Object.keys(data.errors);

            if (
                errorKeys.some(
                    (key) =>
                        key.startsWith('vehicle.') ||
                        [
                            'notes',
                            'is_blacklisted',
                            'blacklist_reason',
                            'marketing_consent',
                        ].includes(key),
                )
            ) {
                activeSection.value = 'vehicle';
            } else if (
                errorKeys.some((key) =>
                    [
                        'address',
                        'city',
                        'state',
                        'zip',
                        'id_document_type',
                        'id_document_number',
                    ].includes(key),
                )
            ) {
                activeSection.value = 'details';
            } else {
                activeSection.value = 'contact';
            }

            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('vehicle.', 'vehicle_')] = (
                        msgs as string[]
                    )[0]),
            );
            generalError.value = 'Revisa los campos marcados.';
        } else {
            generalError.value = data?.message ?? 'No se pudo guardar.';
        }
    } finally {
        saving.value = false;
    }
}

const iconInput =
    'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-5 w-5 stroke-[1.5] text-slate-400';
const sectionIcon =
    'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border';
</script>

<template>
    <Dialog size="xl" :open="open" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[960px]">
            <form class="flex max-h-[90vh] flex-col" @submit.prevent="submit">
                <!-- Header -->
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="isEdit ? 'Pencil' : 'UserPlus'"
                            class="h-6 w-6"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-medium">
                            {{ isEdit ? 'Editar huésped' : 'Nuevo huésped' }}
                        </h2>
                        <p class="text-sm text-slate-500">
                            Solo el nombre es obligatorio; puedes guardar desde
                            cualquier paso.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <!-- Pasos en móvil -->
                <div
                    class="grid grid-cols-3 gap-2 border-b border-slate-200/70 px-4 py-3 lg:hidden dark:border-darkmode-400"
                    aria-label="Secciones del formulario"
                >
                    <button
                        v-for="(s, i) in steps"
                        :key="s.key"
                        type="button"
                        class="flex min-h-10 items-center justify-center gap-2 rounded-lg border px-2 text-xs font-medium transition"
                        :class="
                            activeSection === s.key
                                ? 'border-primary/30 bg-primary/5 text-primary'
                                : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-600'
                        "
                        @click="activeSection = s.key"
                    >
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px]"
                            :class="
                                activeSection === s.key
                                    ? 'bg-primary text-white'
                                    : stepDone[s.key]
                                      ? 'bg-success/10 text-success'
                                      : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                            "
                        >
                            <Lucide
                                v-if="
                                    stepDone[s.key] && activeSection !== s.key
                                "
                                icon="Check"
                                class="h-3 w-3"
                            />
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        <span class="truncate">{{ s.short }}</span>
                    </button>
                </div>

                <!-- Cuerpo: riel de pasos + contenido -->
                <div class="flex min-h-0 flex-1">
                    <nav
                        class="hidden w-60 shrink-0 flex-col justify-between gap-4 overflow-y-auto border-r border-slate-200/70 bg-slate-50/70 p-4 lg:flex dark:border-darkmode-400 dark:bg-darkmode-700/40"
                        aria-label="Secciones del formulario"
                    >
                        <div class="space-y-1.5">
                            <button
                                v-for="(s, i) in steps"
                                :key="s.key"
                                type="button"
                                class="flex w-full items-start gap-3 rounded-xl border p-3 text-left transition"
                                :class="
                                    activeSection === s.key
                                        ? 'border-primary/30 bg-primary/5'
                                        : 'border-transparent hover:bg-white dark:hover:bg-darkmode-600'
                                "
                                @click="activeSection = s.key"
                            >
                                <span
                                    class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-medium"
                                    :class="
                                        activeSection === s.key
                                            ? 'border-primary bg-primary text-white'
                                            : stepDone[s.key]
                                              ? 'border-success/20 bg-success/10 text-success'
                                              : 'border-slate-200 bg-white text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-600'
                                    "
                                >
                                    <Lucide
                                        v-if="
                                            stepDone[s.key] &&
                                            activeSection !== s.key
                                        "
                                        icon="Check"
                                        class="h-4 w-4"
                                    />
                                    <template v-else>{{ i + 1 }}</template>
                                </span>
                                <span class="min-w-0">
                                    <span
                                        class="block text-sm leading-snug font-medium"
                                        :class="
                                            activeSection === s.key
                                                ? 'text-primary'
                                                : ''
                                        "
                                    >
                                        {{ s.title }}
                                    </span>
                                    <span
                                        class="mt-0.5 block text-xs leading-snug text-slate-500"
                                    >
                                        {{ s.desc }}
                                    </span>
                                </span>
                            </button>
                        </div>
                        <div
                            class="flex items-start gap-2 rounded-xl border border-dashed border-slate-300/70 bg-white/60 p-3 text-xs leading-relaxed text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-600/60"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            El resto de la información se puede completar
                            después, al editar la ficha.
                        </div>
                    </nav>

                    <div
                        class="min-h-0 flex-1 overflow-y-auto px-6 py-6 [&_input:not([type=checkbox]):not([type=radio])]:min-h-11 [&_select]:min-h-11 [&_textarea]:min-h-24"
                    >
                        <p
                            v-if="generalError"
                            class="mb-5 flex items-center gap-2 rounded-lg bg-danger/10 px-4 py-3 text-sm text-danger"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="h-4 w-4 shrink-0"
                            />
                            {{ generalError }}
                        </p>

                        <!-- Paso 1: contacto -->
                        <section
                            v-show="activeSection === 'contact'"
                            class="space-y-5"
                        >
                            <header class="flex items-center gap-3">
                                <div
                                    :class="sectionIcon"
                                    class="border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="User" class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="font-medium">
                                        Contacto principal
                                    </h3>
                                    <p class="text-xs text-slate-500">
                                        Nombre y medios para localizar al
                                        huésped.
                                    </p>
                                </div>
                            </header>
                            <div
                                class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2"
                            >
                                <div>
                                    <FormLabel htmlFor="g-first"
                                        >Nombre(s) *</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="User"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-first"
                                            v-model="form.first_name"
                                            type="text"
                                            class="pl-9"
                                            placeholder="María"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.first_name"
                                        class="text-danger"
                                        >{{ errors.first_name }}</FormHelp
                                    >
                                </div>
                                <div>
                                    <FormLabel htmlFor="g-last"
                                        >Apellidos</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="User"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-last"
                                            v-model="form.last_name"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Domínguez"
                                        />
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <FormLabel htmlFor="g-phone"
                                        >Teléfono</FormLabel
                                    >
                                    <div class="flex flex-wrap gap-2">
                                        <FormSelect
                                            id="g-phone-country"
                                            v-model="phoneCountry"
                                            class="w-40 shrink-0"
                                            aria-label="Lada del país"
                                            @change="changePhoneCountry"
                                        >
                                            <option value="mx">
                                                +52 · México
                                            </option>
                                            <option value="us">
                                                +1 · EU / Canadá
                                            </option>
                                            <option value="other">
                                                Otra lada
                                            </option>
                                        </FormSelect>
                                        <FormInput
                                            v-if="phoneCountry === 'other'"
                                            id="g-phone-dial-code"
                                            v-model="phoneDialCode"
                                            type="tel"
                                            inputmode="numeric"
                                            class="w-20 shrink-0"
                                            placeholder="+34"
                                            aria-label="Lada internacional"
                                            @input="syncGuestPhone"
                                        />
                                        <div
                                            class="relative min-w-[10rem] flex-1"
                                        >
                                            <Lucide
                                                icon="Phone"
                                                :class="iconInput"
                                            />
                                            <FormInput
                                                id="g-phone"
                                                v-model="phoneNationalNumber"
                                                type="tel"
                                                inputmode="tel"
                                                autocomplete="tel-national"
                                                class="pl-9"
                                                placeholder="Número telefónico"
                                                @input="syncGuestPhone"
                                            />
                                        </div>
                                    </div>
                                    <FormHelp>
                                        {{
                                            guestPhonePreview
                                                ? `Se guardará como ${guestPhonePreview}`
                                                : 'Selecciona el país o escribe la lada internacional.'
                                        }}
                                    </FormHelp>
                                    <FormHelp
                                        v-if="errors.phone"
                                        class="text-danger"
                                    >
                                        {{ errors.phone }}
                                    </FormHelp>
                                </div>
                                <div>
                                    <FormLabel htmlFor="g-email"
                                        >Email</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Mail"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-email"
                                            v-model="form.email"
                                            type="email"
                                            class="pl-9"
                                            placeholder="correo@ejemplo.com"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.email"
                                        class="text-danger"
                                        >{{ errors.email }}</FormHelp
                                    >
                                </div>
                                <div>
                                    <FormLabel htmlFor="g-birth"
                                        >Fecha de nacimiento</FormLabel
                                    >
                                    <div class="relative">
                                        <FormDate
                                            id="g-birth"
                                            v-model="form.birth_date"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <FormLabel htmlFor="g-nat"
                                        >Nacionalidad</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Flag"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-nat"
                                            v-model="form.nationality"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Mexicana"
                                        />
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Paso 2: identificación y dirección -->
                        <section
                            v-show="activeSection === 'details'"
                            class="space-y-5"
                        >
                            <header class="flex items-center gap-3">
                                <div
                                    :class="sectionIcon"
                                    class="border-warning/10 bg-warning/10 text-warning"
                                >
                                    <Lucide icon="IdCard" class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="font-medium">Identificación</h3>
                                    <p class="text-xs text-slate-500">
                                        Documento y fotografías de respaldo.
                                    </p>
                                </div>
                            </header>
                            <div
                                class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2"
                            >
                                <div>
                                    <FormLabel htmlFor="g-doctype"
                                        >Tipo de documento</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="IdCard"
                                            :class="iconInput"
                                        />
                                        <FormSelect
                                            id="g-doctype"
                                            v-model="form.id_document_type"
                                            class="pl-9"
                                        >
                                            <option value="">—</option>
                                            <option
                                                v-for="t in documentTypes"
                                                :key="t"
                                                :value="t"
                                            >
                                                {{ docLabels[t] ?? t }}
                                            </option>
                                        </FormSelect>
                                    </div>
                                </div>
                                <div>
                                    <FormLabel htmlFor="g-docnum"
                                        >Número de documento</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Hash"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-docnum"
                                            v-model="form.id_document_number"
                                            type="text"
                                            class="pl-9"
                                            placeholder="IDMEX…"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div v-if="canViewDocuments">
                                <FormLabel
                                    >Fotos del documento (frente /
                                    reverso)</FormLabel
                                >
                                <div class="flex flex-wrap gap-3">
                                    <div
                                        v-for="m in existingIne"
                                        :key="m.id"
                                        class="group relative"
                                    >
                                        <img
                                            :src="m.url"
                                            class="h-20 w-32 rounded-lg border border-slate-200 object-cover dark:border-darkmode-400"
                                        />
                                        <button
                                            type="button"
                                            class="absolute -top-2 -right-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex"
                                            @click="removeExisting('ine', m)"
                                        >
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <div
                                        v-for="(s, i) in stagedIne"
                                        :key="s.url"
                                        class="group relative"
                                    >
                                        <img
                                            :src="s.url"
                                            class="h-20 w-32 rounded-lg border border-primary/30 object-cover"
                                        />
                                        <span
                                            class="absolute bottom-1 left-1 rounded bg-primary/80 px-1 text-[9px] text-white"
                                            >nueva</span
                                        >
                                        <button
                                            type="button"
                                            class="absolute -top-2 -right-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex"
                                            @click="removeStaged('ine', i)"
                                        >
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <label
                                        class="flex h-20 w-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                    >
                                        <Lucide icon="Camera" class="h-5 w-5" />
                                        <span class="mt-1 text-[10px]"
                                            >Agregar foto</span
                                        >
                                        <input
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            class="hidden"
                                            @change="stageFiles($event, 'ine')"
                                        />
                                    </label>
                                </div>
                            </div>

                            <div
                                class="space-y-4 border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
                            >
                                <header class="flex items-center gap-3">
                                    <div
                                        :class="sectionIcon"
                                        class="border-info/10 bg-info/10 text-info"
                                    >
                                        <Lucide icon="MapPin" class="h-5 w-5" />
                                    </div>
                                    <div>
                                        <h3 class="font-medium">Dirección</h3>
                                        <p class="text-xs text-slate-500">
                                            Información opcional de residencia.
                                        </p>
                                    </div>
                                </header>
                                <div>
                                    <FormLabel htmlFor="g-addr"
                                        >Calle y número</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="MapPin"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="g-addr"
                                            v-model="form.address"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Av. Reforma 123"
                                        />
                                    </div>
                                </div>
                                <div
                                    class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-3"
                                >
                                    <div>
                                        <FormLabel htmlFor="g-city"
                                            >Ciudad</FormLabel
                                        >
                                        <FormInput
                                            id="g-city"
                                            v-model="form.city"
                                            type="text"
                                        />
                                    </div>
                                    <div>
                                        <FormLabel htmlFor="g-state"
                                            >Estado</FormLabel
                                        >
                                        <FormInput
                                            id="g-state"
                                            v-model="form.state"
                                            type="text"
                                        />
                                    </div>
                                    <div>
                                        <FormLabel htmlFor="g-zip"
                                            >C.P.</FormLabel
                                        >
                                        <FormInput
                                            id="g-zip"
                                            v-model="form.zip"
                                            type="text"
                                        />
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Paso 3: vehículo y notas -->
                        <section
                            v-show="activeSection === 'vehicle'"
                            class="space-y-5"
                        >
                            <header class="flex items-center gap-3">
                                <div
                                    :class="sectionIcon"
                                    class="border-success/10 bg-success/10 text-success"
                                >
                                    <Lucide icon="Car" class="h-5 w-5" />
                                </div>
                                <div>
                                    <h3 class="font-medium">Vehículo</h3>
                                    <p class="text-xs text-slate-500">
                                        Datos para reconocerlo al ingresar.
                                    </p>
                                </div>
                            </header>
                            <div
                                class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-3"
                            >
                                <div>
                                    <FormLabel htmlFor="v-plate"
                                        >Placa</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="RectangleHorizontal"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="v-plate"
                                            v-model="vehicleForm.plate"
                                            type="text"
                                            class="pl-9 uppercase"
                                            placeholder="ABC-123-D"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.vehicle_plate"
                                        class="text-danger"
                                        >{{ errors.vehicle_plate }}</FormHelp
                                    >
                                </div>
                                <div>
                                    <FormLabel htmlFor="v-brand"
                                        >Marca</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide icon="Car" :class="iconInput" />
                                        <FormInput
                                            id="v-brand"
                                            v-model="vehicleForm.brand"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Nissan"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <FormLabel htmlFor="v-model"
                                        >Modelo</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="CarFront"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="v-model"
                                            v-model="vehicleForm.model"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Versa"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <FormLabel htmlFor="v-color"
                                        >Color</FormLabel
                                    >
                                    <div class="relative">
                                        <Lucide
                                            icon="Palette"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="v-color"
                                            v-model="vehicleForm.color"
                                            type="text"
                                            class="pl-9"
                                            placeholder="Gris"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <FormLabel htmlFor="v-year">Año</FormLabel>
                                    <div class="relative">
                                        <Lucide
                                            icon="Calendar"
                                            :class="iconInput"
                                        />
                                        <FormInput
                                            id="v-year"
                                            v-model.number="vehicleForm.year"
                                            type="number"
                                            min="1950"
                                            :max="new Date().getFullYear() + 1"
                                            class="pl-9"
                                            placeholder="2021"
                                        />
                                    </div>
                                    <FormHelp
                                        v-if="errors.vehicle_year"
                                        class="text-danger"
                                        >{{ errors.vehicle_year }}</FormHelp
                                    >
                                </div>
                                <div>
                                    <FormLabel htmlFor="v-notes"
                                        >Detalle</FormLabel
                                    >
                                    <FormInput
                                        id="v-notes"
                                        v-model="vehicleForm.notes"
                                        type="text"
                                        placeholder="Golpe puerta izq., calcomanía…"
                                    />
                                </div>
                            </div>
                            <div v-if="canViewDocuments">
                                <FormLabel
                                    >Fotos del vehículo (placa, color,
                                    daños…)</FormLabel
                                >
                                <div class="flex flex-wrap gap-3">
                                    <div
                                        v-for="m in existingVehicle"
                                        :key="m.id"
                                        class="group relative"
                                    >
                                        <img
                                            :src="m.url"
                                            class="h-20 w-32 rounded-lg border border-slate-200 object-cover dark:border-darkmode-400"
                                        />
                                        <button
                                            type="button"
                                            class="absolute -top-2 -right-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex"
                                            @click="
                                                removeExisting('vehicle', m)
                                            "
                                        >
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <div
                                        v-for="(s, i) in stagedVehicle"
                                        :key="s.url"
                                        class="group relative"
                                    >
                                        <img
                                            :src="s.url"
                                            class="h-20 w-32 rounded-lg border border-primary/30 object-cover"
                                        />
                                        <span
                                            class="absolute bottom-1 left-1 rounded bg-primary/80 px-1 text-[9px] text-white"
                                            >nueva</span
                                        >
                                        <button
                                            type="button"
                                            class="absolute -top-2 -right-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex"
                                            @click="removeStaged('vehicle', i)"
                                        >
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <label
                                        class="flex h-20 w-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                    >
                                        <Lucide icon="Camera" class="h-5 w-5" />
                                        <span class="mt-1 text-[10px]"
                                            >Agregar foto</span
                                        >
                                        <input
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            class="hidden"
                                            @change="
                                                stageFiles($event, 'vehicle')
                                            "
                                        />
                                    </label>
                                </div>
                            </div>

                            <div
                                class="space-y-4 border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
                            >
                                <header class="flex items-center gap-3">
                                    <div
                                        :class="sectionIcon"
                                        class="border-pending/10 bg-pending/10 text-pending"
                                    >
                                        <Lucide
                                            icon="StickyNote"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div>
                                        <h3 class="font-medium">
                                            Notas y seguimiento
                                        </h3>
                                        <p class="text-xs text-slate-500">
                                            Preferencias, permisos y alertas
                                            internas.
                                        </p>
                                    </div>
                                </header>
                                <div>
                                    <FormLabel htmlFor="g-notes"
                                        >Notas del staff</FormLabel
                                    >
                                    <FormTextarea
                                        id="g-notes"
                                        v-model="form.notes"
                                        rows="2"
                                        placeholder="Prefiere piso alto, alérgico a…"
                                    />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div
                                        class="rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                                    >
                                        <FormCheck>
                                            <FormCheck.Input
                                                id="g-marketing"
                                                v-model="form.marketing_consent"
                                                type="checkbox"
                                            />
                                            <FormCheck.Label
                                                htmlFor="g-marketing"
                                                >Acepta
                                                marketing</FormCheck.Label
                                            >
                                        </FormCheck>
                                    </div>
                                    <div
                                        class="rounded-lg border p-3"
                                        :class="
                                            form.is_blacklisted
                                                ? 'border-danger/30 bg-danger/5'
                                                : 'border-slate-200/70 dark:border-darkmode-400'
                                        "
                                    >
                                        <FormCheck>
                                            <FormCheck.Input
                                                id="g-blacklist"
                                                v-model="form.is_blacklisted"
                                                type="checkbox"
                                            />
                                            <FormCheck.Label
                                                htmlFor="g-blacklist"
                                                class="text-danger"
                                                >Lista negra</FormCheck.Label
                                            >
                                        </FormCheck>
                                        <div
                                            v-if="form.is_blacklisted"
                                            class="mt-2"
                                        >
                                            <FormInput
                                                v-model="form.blacklist_reason"
                                                type="text"
                                                placeholder="Motivo (obligatorio)"
                                            />
                                            <FormHelp
                                                v-if="errors.blacklist_reason"
                                                class="text-danger"
                                                >{{
                                                    errors.blacklist_reason
                                                }}</FormHelp
                                            >
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <!-- Footer -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="hidden items-center gap-2 text-sm text-slate-500 sm:flex"
                    >
                        Paso {{ stepIndex + 1 }} de {{ steps.length }}
                        <span class="text-slate-300 dark:text-slate-600"
                            >·</span
                        >
                        {{ steps[stepIndex].title }}
                    </div>
                    <div
                        class="flex flex-1 flex-wrap items-center justify-end gap-2.5"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-4"
                            @click="emit('close')"
                            >Cancelar</Button
                        >
                        <Button
                            v-if="stepIndex > 0"
                            type="button"
                            variant="outline-secondary"
                            class="min-h-11 px-4"
                            @click="goStep(-1)"
                        >
                            <Lucide icon="ChevronLeft" class="mr-1.5 h-4 w-4" />
                            Anterior
                        </Button>
                        <Button
                            v-if="stepIndex < steps.length - 1"
                            type="button"
                            variant="outline-primary"
                            class="min-h-11 px-4"
                            @click="goStep(1)"
                        >
                            Siguiente
                            <Lucide
                                icon="ChevronRight"
                                class="ml-1.5 h-4 w-4"
                            />
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            class="min-h-11 px-5 shadow-md shadow-primary/20"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-2 h-5 w-5" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : isEdit
                                      ? 'Guardar cambios'
                                      : 'Crear huésped'
                            }}
                        </Button>
                    </div>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
