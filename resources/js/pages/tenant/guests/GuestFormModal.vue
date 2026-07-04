<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormHelp, FormInput, FormLabel, FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';

interface MediaItem { id: number; name: string; url: string }
interface Vehicle { plate?: string | null; brand?: string | null; model?: string | null; color?: string | null; year?: number | null; notes?: string | null }
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

const emit = defineEmits<{ (e: 'close'): void; (e: 'saved', id: number): void }>();

const isEdit = computed(() => !!props.guest);
const docLabels: Record<string, string> = { ine: 'INE', pasaporte: 'Pasaporte', licencia: 'Licencia', otro: 'Otro' };

const blank = () => ({
    first_name: '', last_name: '', phone: '', email: '', birth_date: '', nationality: '',
    address: '', city: '', state: '', zip: '',
    id_document_type: '' as string, id_document_number: '',
    notes: '', is_blacklisted: false, blacklist_reason: '', marketing_consent: false,
});
const form = reactive(blank());
const vehicleForm = reactive<Vehicle>({ plate: '', brand: '', model: '', color: '', year: null, notes: '' });

const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const generalError = ref<string | null>(null);

// Fotos existentes (edición) y nuevas por subir (staged, ambos modos).
const existingIne = ref<MediaItem[]>([]);
const existingVehicle = ref<MediaItem[]>([]);
interface Staged { file: File; url: string }
const stagedIne = ref<Staged[]>([]);
const stagedVehicle = ref<Staged[]>([]);

function resetFrom() {
    Object.assign(form, blank());
    Object.assign(vehicleForm, { plate: '', brand: '', model: '', color: '', year: null, notes: '' });
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

watch(() => props.open, (open) => { if (open) resetFrom(); });

function stageFiles(event: Event, target: 'ine' | 'vehicle') {
    const files = (event.target as HTMLInputElement).files;
    if (!files) return;
    const bucket = target === 'ine' ? stagedIne : stagedVehicle;
    Array.from(files).forEach((file) => {
        if (file.type.startsWith('image/')) bucket.value.push({ file, url: URL.createObjectURL(file) });
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
        phone: form.phone || null,
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
            Object.entries(data.errors).forEach(([key, msgs]) => (errors[key.replace('vehicle.', 'vehicle_')] = (msgs as string[])[0]));
            generalError.value = 'Revisa los campos marcados.';
        } else {
            generalError.value = data?.message ?? 'No se pudo guardar.';
        }
    } finally {
        saving.value = false;
    }
}

const iconInput = 'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';
</script>

<template>
    <Dialog size="xl" :open="open" @close="emit('close')">
        <Dialog.Panel>
            <form class="flex max-h-[85vh] flex-col" @submit.prevent="submit">
                <!-- Header -->
                <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Lucide :icon="isEdit ? 'Pencil' : 'UserPlus'" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">{{ isEdit ? 'Editar huésped' : 'Nuevo huésped' }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Datos, identificación y vehículo</p>
                    </div>
                    <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400" @click="emit('close')">
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 space-y-7 overflow-y-auto px-6 py-6">
                    <!-- Datos personales -->
                    <div class="space-y-4">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="User" class="h-3.5 w-3.5" /> Datos personales
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="g-first">Nombre(s) *</FormLabel>
                                <div class="relative">
                                    <Lucide icon="User" :class="iconInput" />
                                    <FormInput id="g-first" v-model="form.first_name" type="text" class="pl-9" placeholder="María" />
                                </div>
                                <FormHelp v-if="errors.first_name" class="text-danger">{{ errors.first_name }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-last">Apellidos</FormLabel>
                                <div class="relative">
                                    <Lucide icon="User" :class="iconInput" />
                                    <FormInput id="g-last" v-model="form.last_name" type="text" class="pl-9" placeholder="Domínguez" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-phone">Teléfono</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Phone" :class="iconInput" />
                                    <FormInput id="g-phone" v-model="form.phone" type="text" class="pl-9" placeholder="+52…" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-email">Email</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Mail" :class="iconInput" />
                                    <FormInput id="g-email" v-model="form.email" type="email" class="pl-9" placeholder="correo@ejemplo.com" />
                                </div>
                                <FormHelp v-if="errors.email" class="text-danger">{{ errors.email }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-birth">Fecha de nacimiento</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Cake" :class="iconInput" />
                                    <FormInput id="g-birth" v-model="form.birth_date" type="date" class="pl-9" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-nat">Nacionalidad</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Flag" :class="iconInput" />
                                    <FormInput id="g-nat" v-model="form.nationality" type="text" class="pl-9" placeholder="Mexicana" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dirección -->
                    <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="MapPin" class="h-3.5 w-3.5" /> Dirección
                        </div>
                        <div>
                            <FormLabel htmlFor="g-addr">Calle y número</FormLabel>
                            <div class="relative">
                                <Lucide icon="MapPin" :class="iconInput" />
                                <FormInput id="g-addr" v-model="form.address" type="text" class="pl-9" placeholder="Av. Reforma 123" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="g-city">Ciudad</FormLabel>
                                <FormInput id="g-city" v-model="form.city" type="text" />
                            </div>
                            <div>
                                <FormLabel htmlFor="g-state">Estado</FormLabel>
                                <FormInput id="g-state" v-model="form.state" type="text" />
                            </div>
                            <div>
                                <FormLabel htmlFor="g-zip">C.P.</FormLabel>
                                <FormInput id="g-zip" v-model="form.zip" type="text" />
                            </div>
                        </div>
                    </div>

                    <!-- Identificación (INE) -->
                    <div v-if="canViewDocuments" class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="IdCard" class="h-3.5 w-3.5" /> Identificación
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="g-doctype">Tipo de documento</FormLabel>
                                <div class="relative">
                                    <Lucide icon="IdCard" :class="iconInput" />
                                    <FormSelect id="g-doctype" v-model="form.id_document_type" class="pl-9">
                                        <option value="">—</option>
                                        <option v-for="t in documentTypes" :key="t" :value="t">{{ docLabels[t] ?? t }}</option>
                                    </FormSelect>
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="g-docnum">Número de documento</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Hash" :class="iconInput" />
                                    <FormInput id="g-docnum" v-model="form.id_document_number" type="text" class="pl-9" placeholder="IDMEX…" />
                                </div>
                            </div>
                        </div>
                        <div>
                            <FormLabel>Fotos del documento (frente / reverso)</FormLabel>
                            <div class="flex flex-wrap gap-3">
                                <div v-for="m in existingIne" :key="m.id" class="group relative">
                                    <img :src="m.url" class="h-20 w-32 rounded-lg border border-slate-200 object-cover dark:border-darkmode-400" />
                                    <button type="button" class="absolute -right-2 -top-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex" @click="removeExisting('ine', m)">
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </div>
                                <div v-for="(s, i) in stagedIne" :key="s.url" class="group relative">
                                    <img :src="s.url" class="h-20 w-32 rounded-lg border border-primary/30 object-cover" />
                                    <span class="absolute bottom-1 left-1 rounded bg-primary/80 px-1 text-[9px] text-white">nueva</span>
                                    <button type="button" class="absolute -right-2 -top-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex" @click="removeStaged('ine', i)">
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </div>
                                <label class="flex h-20 w-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400">
                                    <Lucide icon="Camera" class="h-5 w-5" />
                                    <span class="mt-1 text-[10px]">Agregar foto</span>
                                    <input type="file" accept="image/*" multiple class="hidden" @change="stageFiles($event, 'ine')" />
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Vehículo -->
                    <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Car" class="h-3.5 w-3.5" /> Vehículo en el que ingresó
                        </div>
                        <div class="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="v-plate">Placa</FormLabel>
                                <div class="relative">
                                    <Lucide icon="RectangleHorizontal" :class="iconInput" />
                                    <FormInput id="v-plate" v-model="vehicleForm.plate" type="text" class="pl-9 uppercase" placeholder="ABC-123-D" />
                                </div>
                                <FormHelp v-if="errors.vehicle_plate" class="text-danger">{{ errors.vehicle_plate }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="v-color">Color</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Palette" :class="iconInput" />
                                    <FormInput id="v-color" v-model="vehicleForm.color" type="text" class="pl-9" placeholder="Gris" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="v-year">Año</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Calendar" :class="iconInput" />
                                    <FormInput id="v-year" v-model.number="vehicleForm.year" type="number" min="1950" :max="new Date().getFullYear() + 1" class="pl-9" placeholder="2021" />
                                </div>
                                <FormHelp v-if="errors.vehicle_year" class="text-danger">{{ errors.vehicle_year }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="v-brand">Marca</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Car" :class="iconInput" />
                                    <FormInput id="v-brand" v-model="vehicleForm.brand" type="text" class="pl-9" placeholder="Nissan" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="v-model">Modelo</FormLabel>
                                <div class="relative">
                                    <Lucide icon="CarFront" :class="iconInput" />
                                    <FormInput id="v-model" v-model="vehicleForm.model" type="text" class="pl-9" placeholder="Versa" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="v-notes">Detalle</FormLabel>
                                <FormInput id="v-notes" v-model="vehicleForm.notes" type="text" placeholder="Golpe puerta izq., calcomanía…" />
                            </div>
                        </div>
                        <div v-if="canViewDocuments">
                            <FormLabel>Fotos del vehículo (placa, color, daños…)</FormLabel>
                            <div class="flex flex-wrap gap-3">
                                <div v-for="m in existingVehicle" :key="m.id" class="group relative">
                                    <img :src="m.url" class="h-20 w-32 rounded-lg border border-slate-200 object-cover dark:border-darkmode-400" />
                                    <button type="button" class="absolute -right-2 -top-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex" @click="removeExisting('vehicle', m)">
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </div>
                                <div v-for="(s, i) in stagedVehicle" :key="s.url" class="group relative">
                                    <img :src="s.url" class="h-20 w-32 rounded-lg border border-primary/30 object-cover" />
                                    <span class="absolute bottom-1 left-1 rounded bg-primary/80 px-1 text-[9px] text-white">nueva</span>
                                    <button type="button" class="absolute -right-2 -top-2 hidden h-5 w-5 items-center justify-center rounded-full bg-danger text-white group-hover:flex" @click="removeStaged('vehicle', i)">
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </div>
                                <label class="flex h-20 w-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 text-slate-400 transition hover:border-primary hover:text-primary dark:border-darkmode-400">
                                    <Lucide icon="Camera" class="h-5 w-5" />
                                    <span class="mt-1 text-[10px]">Agregar foto</span>
                                    <input type="file" accept="image/*" multiple class="hidden" @change="stageFiles($event, 'vehicle')" />
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Notas y flags -->
                    <div class="space-y-4 border-t border-slate-200/60 pt-6 dark:border-darkmode-400">
                        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="StickyNote" class="h-3.5 w-3.5" /> Notas y preferencias
                        </div>
                        <div>
                            <FormLabel htmlFor="g-notes">Notas del staff</FormLabel>
                            <FormTextarea id="g-notes" v-model="form.notes" rows="2" placeholder="Prefiere piso alto, alérgico a…" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400">
                                <FormCheck>
                                    <FormCheck.Input id="g-marketing" v-model="form.marketing_consent" type="checkbox" />
                                    <FormCheck.Label htmlFor="g-marketing">Acepta marketing</FormCheck.Label>
                                </FormCheck>
                            </div>
                            <div class="rounded-lg border p-3" :class="form.is_blacklisted ? 'border-danger/30 bg-danger/5' : 'border-slate-200/70 dark:border-darkmode-400'">
                                <FormCheck>
                                    <FormCheck.Input id="g-blacklist" v-model="form.is_blacklisted" type="checkbox" />
                                    <FormCheck.Label htmlFor="g-blacklist" class="text-danger">Lista negra</FormCheck.Label>
                                </FormCheck>
                                <div v-if="form.is_blacklisted" class="mt-2">
                                    <FormInput v-model="form.blacklist_reason" type="text" placeholder="Motivo (obligatorio)" />
                                    <FormHelp v-if="errors.blacklist_reason" class="text-danger">{{ errors.blacklist_reason }}</FormHelp>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="generalError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ generalError }}</p>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                    <Button type="button" variant="outline-secondary" @click="emit('close')">Cancelar</Button>
                    <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="saving">
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear huésped' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
