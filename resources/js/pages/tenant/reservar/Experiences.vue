<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDate,
    FormInput,
    FormLabel,
    FormTextarea,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import BackButton from '@/components/Booking/BackButton.vue';
import BookingCard from '@/components/Booking/BookingCard.vue';
import PaymentPanel from '@/components/Booking/PaymentPanel.vue';
import PhoneField from '@/components/Booking/PhoneField.vue';
import PhotoGallery from '@/components/Booking/PhotoGallery.vue';
import PublicShell from '@/components/Booking/PublicShell.vue';
import QtyStepper from '@/components/Booking/QtyStepper.vue';
import StaySummary from '@/components/Booking/StaySummary.vue';
import type { PublicContact } from '@/components/Booking/theme';
import { revealElement } from '@/composables/useEmbedResize';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import {
    downloadIcs,
    emailError,
    formatFriendlyDateTime,
    formatMoney,
    humanizeDurationLabel,
    internationalPhone,
    phoneError,
} from '@/lib/bookingFormat';

interface Photo {
    id: number;
    url: string;
    thumb_url: string;
}

interface SessionOption {
    id: number;
    starts_at: string;
    remaining: number;
}

interface ExperienceOption {
    id: number;
    name: string;
    description: string | null;
    includes: string[];
    duration_label: string | null;
    pricing_mode: 'per_person' | 'flat';
    price: number;
    price_label: string;
    min_people: number;
    max_people: number | null;
    photos: Photo[];
    available_dates: string[];
}

interface BookingResult {
    code: string;
    experience: string;
    starts_at: string;
    people: number;
    total: number;
}

const props = defineProps<{
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
        phone: string | null;
        currency: string;
        currency_secondary: string | null;
        exchange_rate: number | null;
    };
    hasWizard: boolean;
    hasLookup: boolean;
    hasGroups: boolean;
    contact: PublicContact;
}>();

const money = (n: number) => formatMoney(n, props.property.currency);
const formatDateChip = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
const formatDateLong = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
const formatHour = (iso: string) =>
    new Date(iso).toLocaleTimeString('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
    });

const loading = ref(true);
const loadError = ref<string | null>(null);
const experiences = ref<ExperienceOption[]>([]);

onMounted(async () => {
    try {
        const { data } = await axios.get<{ experiences: ExperienceOption[] }>(
            '/api/experiencias/list',
        );
        experiences.value = data.experiences;
    } catch {
        loadError.value =
            'No se pudieron cargar las experiencias. Intenta de nuevo en un momento.';
    } finally {
        loading.value = false;
    }
});

const STEPS = [
    { key: 'list', label: 'Experiencia' },
    { key: 'detail', label: 'Fecha y datos' },
    { key: 'done', label: 'Listo' },
];

const selected = ref<ExperienceOption | null>(null);
const result = ref<BookingResult | null>(null);
const currentStep = computed(() =>
    result.value ? 'done' : selected.value ? 'detail' : 'list',
);
const stepTop = ref<HTMLElement | null>(null);
watch(currentStep, async () => {
    await nextTick();
    revealElement(stepTop.value);
});

const sessionId = ref<number | null>(null);
const selectedDate = ref('');
const daySessions = ref<SessionOption[]>([]);
const sessionsLoading = ref(false);
const people = ref(1);
const guestName = ref('');
const guestPhone = ref('');
const phoneCountry = ref<'52' | '1' | 'otro'>('52');
const phoneLada = ref('');
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref('');
const renderedAt = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);
const attempted = ref(false);
const paymentPanel = ref<InstanceType<typeof PaymentPanel> | null>(null);
const sessionsEl = ref<HTMLElement | null>(null);

function choose(experience: ExperienceOption) {
    selected.value = experience;
    sessionId.value = null;
    selectedDate.value = '';
    daySessions.value = [];
    people.value = experience.min_people;
    attempted.value = false;
    honeypot.value = '';
    renderedAt.value = new Date().toISOString();
    submitError.value = null;
    if (experience.available_dates.length)
        selectDate(experience.available_dates[0]);
}

const quickDates = computed(
    () => selected.value?.available_dates.slice(0, 6) ?? [],
);

const dateAvailable = computed(
    () =>
        !!selected.value &&
        !!selectedDate.value &&
        selected.value.available_dates.includes(selectedDate.value),
);

// La fecha del calendario no opera: las tres fechas con lugar más cercanas.
const nearestDates = computed(() => {
    if (!selected.value || !selectedDate.value || dateAvailable.value)
        return [];
    const target = new Date(`${selectedDate.value}T00:00:00`).getTime();
    return [...selected.value.available_dates]
        .sort(
            (a, b) =>
                Math.abs(new Date(`${a}T00:00:00`).getTime() - target) -
                Math.abs(new Date(`${b}T00:00:00`).getTime() - target),
        )
        .slice(0, 3);
});

async function selectDate(date: string) {
    if (!selected.value || !date) return;
    selectedDate.value = date;
    sessionId.value = null;
    daySessions.value = [];
    if (!selected.value.available_dates.includes(date)) return;
    sessionsLoading.value = true;
    try {
        const { data } = await axios.get<{ sessions: SessionOption[] }>(
            '/api/experiencias/sessions',
            { params: { experience_id: selected.value.id, date } },
        );
        daySessions.value = data.sessions;
        if (data.sessions.length === 1) sessionId.value = data.sessions[0].id;
    } catch {
        daySessions.value = [];
    } finally {
        sessionsLoading.value = false;
    }
}

const currentSession = computed(
    () => daySessions.value.find((s) => s.id === sessionId.value) ?? null,
);

const maxPeople = computed(() => {
    if (!selected.value) return 1;
    const byExperience = selected.value.max_people ?? 100;
    const bySession = currentSession.value?.remaining ?? byExperience;
    return Math.max(
        selected.value.min_people,
        Math.min(byExperience, bySession),
    );
});

// Un horario con menos lugares baja las personas al tope: antes se quedaba
// el número alto y el servidor rechazaba el apartado.
watch(maxPeople, (max) => {
    if (people.value > max) people.value = max;
});

const total = computed(() => {
    if (!selected.value) return 0;
    return selected.value.pricing_mode === 'flat'
        ? selected.value.price
        : selected.value.price * people.value;
});

const nameError = computed(() =>
    guestName.value.trim().length >= 3 ? null : 'Escribe tu nombre completo.',
);
const guestPhoneError = computed(() =>
    phoneError(phoneCountry.value, guestPhone.value, phoneLada.value),
);
const guestEmailError = computed(() => emailError(guestEmail.value));
const formValid = computed(
    () =>
        !!sessionId.value &&
        !nameError.value &&
        !guestPhoneError.value &&
        !guestEmailError.value,
);

async function submit() {
    attempted.value = true;
    if (!sessionId.value) {
        revealElement(sessionsEl.value);
        return;
    }
    if (!selected.value || !formValid.value) return;
    submitting.value = true;
    submitError.value = null;
    try {
        const { data } = await axios.post<BookingResult>(
            '/api/experiencias/bookings',
            {
                experience_session_id: sessionId.value,
                people: people.value,
                guest_name: guestName.value.trim(),
                guest_phone: internationalPhone(
                    phoneCountry.value,
                    guestPhone.value,
                    phoneLada.value,
                ),
                guest_email: guestEmail.value.trim() || null,
                notes: notes.value || null,
                website: honeypot.value,
                rendered_at: renderedAt.value,
            },
        );
        result.value = data;
        await nextTick();
        paymentPanel.value?.start();
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;
        submitError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo apartar. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

function addToCalendar() {
    if (!result.value || !selected.value) return;
    const start = new Date(result.value.starts_at);
    // Sin duración en minutos, el evento ocupa dos horas en el calendario.
    const end = new Date(start.getTime() + 2 * 3600000);
    downloadIcs({
        uid: result.value.code,
        title: `${result.value.experience} · ${props.property.name}`,
        start: start.toISOString(),
        end: end.toISOString(),
        description: `Código: ${result.value.code} · ${result.value.people} personas`,
        location: props.property.name,
    });
}

const summary = computed(() => {
    const exp = selected.value;
    if (!exp) return null;
    const when = result.value
        ? formatFriendlyDateTime(result.value.starts_at)
        : currentSession.value
          ? formatFriendlyDateTime(currentSession.value.starts_at)
          : selectedDate.value
            ? `${formatDateLong(selectedDate.value)} · elige horario`
            : 'Elige fecha y horario';
    const count = result.value?.people ?? people.value;
    return {
        photo: exp.photos[0]?.thumb_url ?? null,
        name: exp.name,
        stay: when,
        occupancy: `${count} ${count === 1 ? 'persona' : 'personas'}`,
        lines: [
            {
                id: 'exp',
                label:
                    exp.pricing_mode === 'flat'
                        ? 'Precio por grupo'
                        : `${count} × ${money(exp.price)}`,
                amount: result.value?.total ?? total.value,
            },
        ],
        total: result.value?.total ?? total.value,
    };
});

const sectionLabel =
    'mb-2 text-xs font-medium tracking-wide text-slate-500 uppercase';
const primaryBtn = 'h-11 rounded-full px-6 text-sm shadow-md shadow-primary/20';
const linkPill =
    'inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20';
</script>

<template>
    <PublicShell
        title="Experiencias"
        tagline="Recorridos y actividades con cupo limitado"
        :appearance="appearance"
        :property="property"
        :contact="contact"
        :steps="STEPS"
        :current-step="currentStep"
    >
        <div ref="stepTop" class="scroll-mt-4" />

        <!-- ═══ Lista ═══ -->
        <BookingCard
            v-if="!selected"
            icon="Compass"
            title="Vive la experiencia completa"
            subtitle="Recorridos y actividades con cupo limitado: aparta tu lugar en línea."
        >
            <div
                v-if="loading"
                class="flex flex-col items-center gap-3 py-10 text-center"
            >
                <Lucide
                    icon="LoaderCircle"
                    class="h-8 w-8 animate-spin text-primary"
                />
                <p class="text-sm text-slate-500">Cargando experiencias…</p>
            </div>
            <p
                v-else-if="loadError"
                class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
            >
                <Lucide icon="CircleAlert" class="mt-0.5 h-4 w-4 shrink-0" />
                {{ loadError }}
            </p>
            <div
                v-else-if="!experiences.length"
                class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 py-10 text-center"
            >
                <Lucide icon="Compass" class="h-8 w-8 text-slate-300" />
                <p class="text-sm text-slate-500">
                    Por ahora no hay experiencias con fechas disponibles. Vuelve
                    pronto.
                </p>
            </div>
            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <article
                    v-for="experience in experiences"
                    :key="experience.id"
                    class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-primary/40 hover:shadow-lg hover:shadow-slate-200/60"
                >
                    <button
                        type="button"
                        class="relative block w-full shrink-0 bg-slate-100"
                        :class="experience.photos.length ? 'h-44' : 'h-28'"
                        @click="choose(experience)"
                    >
                        <img
                            v-if="experience.photos.length"
                            :src="experience.photos[0].thumb_url"
                            :alt="experience.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <span
                            v-else
                            class="flex h-full w-full items-center justify-center"
                        >
                            <Lucide
                                icon="Compass"
                                class="h-10 w-10 text-slate-300"
                            />
                        </span>
                        <span
                            v-if="experience.photos.length > 1"
                            class="absolute right-2 bottom-2 inline-flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium text-white"
                        >
                            <Lucide icon="Images" class="h-3.5 w-3.5" />
                            {{ experience.photos.length }} fotos
                        </span>
                    </button>
                    <div class="flex flex-1 flex-col p-4">
                        <h3 class="text-base font-medium text-slate-800">
                            {{ experience.name }}
                        </h3>
                        <div
                            class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-500"
                        >
                            <span class="font-medium text-slate-700">{{
                                experience.price_label
                            }}</span>
                            <span
                                v-if="experience.duration_label"
                                class="inline-flex items-center gap-1.5"
                            >
                                <Lucide icon="Clock" class="h-4 w-4" />
                                {{
                                    humanizeDurationLabel(
                                        experience.duration_label,
                                    )
                                }}
                            </span>
                        </div>
                        <p
                            v-if="experience.description"
                            class="mt-2 line-clamp-2 text-sm text-slate-500"
                        >
                            {{ experience.description }}
                        </p>
                        <div
                            class="mt-auto flex items-center justify-between gap-3 pt-4"
                        >
                            <span class="text-xs text-slate-500">
                                {{
                                    experience.available_dates.length === 1
                                        ? '1 fecha disponible'
                                        : `${experience.available_dates.length} fechas disponibles`
                                }}
                            </span>
                            <Button
                                variant="primary"
                                class="h-11 rounded-full px-5 text-sm shadow-md shadow-primary/20"
                                @click="choose(experience)"
                            >
                                Reservar
                            </Button>
                        </div>
                    </div>
                </article>
            </div>
        </BookingCard>

        <!-- ═══ Detalle + reserva / listo, con el resumen al lado ═══ -->
        <div
            v-else
            class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_330px]"
        >
            <div class="min-w-0 space-y-5">
                <template v-if="!result">
                    <BookingCard
                        icon="Compass"
                        :title="selected.name"
                        :subtitle="
                            selected.duration_label
                                ? `${selected.price_label} · ${humanizeDurationLabel(selected.duration_label)}`
                                : selected.price_label
                        "
                    >
                        <PhotoGallery
                            :photos="selected.photos"
                            :alt="selected.name"
                            placeholder-icon="Compass"
                        />
                        <p
                            v-if="selected.description"
                            class="mt-4 text-sm leading-relaxed text-slate-600"
                        >
                            {{ selected.description }}
                        </p>
                        <div v-if="selected.includes.length" class="mt-4">
                            <div :class="sectionLabel">Incluye</div>
                            <ul
                                class="grid grid-cols-1 gap-x-4 gap-y-1.5 sm:grid-cols-2"
                            >
                                <li
                                    v-for="item in selected.includes"
                                    :key="item"
                                    class="flex items-center gap-2 text-sm text-slate-600"
                                >
                                    <Lucide
                                        icon="Check"
                                        class="h-4 w-4 shrink-0 text-success"
                                    />
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </BookingCard>

                    <BookingCard
                        icon="CalendarDays"
                        title="Fecha, horario y personas"
                        subtitle="Primero el día; luego el horario con lugares."
                    >
                        <div ref="sessionsEl" class="scroll-mt-4">
                            <div :class="sectionLabel">Fecha</div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-for="date in quickDates"
                                    :key="date"
                                    type="button"
                                    class="h-10 rounded-full border px-4 text-sm font-medium transition"
                                    :class="
                                        selectedDate === date
                                            ? 'border-primary bg-primary/5 text-primary'
                                            : 'border-slate-200 text-slate-600 hover:border-primary/40'
                                    "
                                    @click="selectDate(date)"
                                >
                                    {{ formatDateChip(date) }}
                                </button>
                                <FormDate
                                    :model-value="selectedDate"
                                    :min="selected.available_dates[0]"
                                    :max="
                                        selected.available_dates[
                                            selected.available_dates.length - 1
                                        ]
                                    "
                                    :clearable="false"
                                    placeholder="Otra fecha"
                                    class="w-40"
                                    input-class="h-10 rounded-full border-dashed border-slate-300 text-sm shadow-none"
                                    @update:model-value="
                                        (v: string) => v && selectDate(v)
                                    "
                                />
                            </div>

                            <template v-if="selectedDate && !dateAvailable">
                                <p
                                    class="mt-3 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                                >
                                    El {{ formatDateLong(selectedDate) }} no hay
                                    salidas. Fechas cercanas con lugares:
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="date in nearestDates"
                                        :key="date"
                                        type="button"
                                        class="h-10 rounded-full border border-slate-200 px-4 text-sm font-medium text-slate-600 capitalize transition hover:border-primary/40"
                                        @click="selectDate(date)"
                                    >
                                        {{ formatDateChip(date) }}
                                    </button>
                                </div>
                            </template>

                            <template v-else-if="selectedDate">
                                <div :class="sectionLabel" class="mt-5">
                                    Horario · {{ formatDateLong(selectedDate) }}
                                </div>
                                <div
                                    v-if="sessionsLoading"
                                    class="flex items-center gap-2 py-3 text-sm text-slate-500"
                                >
                                    <Lucide
                                        icon="LoaderCircle"
                                        class="h-4 w-4 animate-spin"
                                    />
                                    Buscando horarios…
                                </div>
                                <div
                                    v-else-if="daySessions.length"
                                    class="grid grid-cols-2 gap-2 sm:grid-cols-3"
                                >
                                    <button
                                        v-for="session in daySessions"
                                        :key="session.id"
                                        type="button"
                                        class="flex items-center justify-between gap-2 rounded-xl border p-3.5 text-left text-sm transition"
                                        :class="
                                            sessionId === session.id
                                                ? 'border-primary bg-primary/5 ring-2 ring-primary/15'
                                                : 'border-slate-200 hover:border-primary/40'
                                        "
                                        :aria-pressed="sessionId === session.id"
                                        @click="sessionId = session.id"
                                    >
                                        <span
                                            class="font-medium text-slate-800"
                                            >{{
                                                formatHour(session.starts_at)
                                            }}</span
                                        >
                                        <span
                                            class="shrink-0 rounded-full px-2.5 py-1 text-xs"
                                            :class="
                                                session.remaining <= 3
                                                    ? 'bg-warning/10 text-warning'
                                                    : 'bg-slate-100 text-slate-500'
                                            "
                                            >{{
                                                session.remaining === 1
                                                    ? '1 lugar'
                                                    : `${session.remaining} lugares`
                                            }}</span
                                        >
                                    </button>
                                </div>
                                <p
                                    v-else
                                    class="rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                                >
                                    Los horarios de ese día se acaban de llenar;
                                    elige otra fecha.
                                </p>
                                <p
                                    v-if="
                                        attempted &&
                                        !sessionId &&
                                        daySessions.length
                                    "
                                    class="mt-2 text-xs text-danger"
                                >
                                    Elige un horario.
                                </p>
                            </template>
                        </div>

                        <div
                            class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3"
                        >
                            <div>
                                <div class="text-sm font-medium text-slate-700">
                                    Personas
                                </div>
                                <div class="text-xs text-slate-500">
                                    Mínimo {{ selected.min_people }}, máximo
                                    {{ maxPeople
                                    }}<template
                                        v-if="selected.pricing_mode === 'flat'"
                                    >
                                        · precio por grupo</template
                                    >
                                </div>
                            </div>
                            <QtyStepper
                                v-model="people"
                                :min="selected.min_people"
                                :max="maxPeople"
                                label="persona"
                            />
                        </div>
                    </BookingCard>

                    <BookingCard
                        icon="UserRound"
                        title="Tus datos"
                        subtitle="Para confirmarte tu lugar y avisarte cualquier cambio."
                    >
                        <form
                            class="space-y-5"
                            novalidate
                            @submit.prevent="submit"
                        >
                            <div>
                                <FormLabel for="x-name"
                                    >Nombre completo</FormLabel
                                >
                                <FormInput
                                    id="x-name"
                                    v-model="guestName"
                                    type="text"
                                    autocomplete="name"
                                    class="h-11 text-sm"
                                    :class="
                                        attempted && nameError
                                            ? 'border-danger'
                                            : ''
                                    "
                                    placeholder="Como aparece en tu identificación"
                                />
                                <p
                                    v-if="attempted && nameError"
                                    class="mt-1.5 text-xs text-danger"
                                >
                                    {{ nameError }}
                                </p>
                            </div>
                            <div>
                                <FormLabel for="x-phone"
                                    >Teléfono (WhatsApp)</FormLabel
                                >
                                <PhoneField
                                    id="x-phone"
                                    v-model:country="phoneCountry"
                                    v-model:number="guestPhone"
                                    v-model:lada="phoneLada"
                                    :show-errors="attempted"
                                    hint="Aquí te confirmamos tu lugar por WhatsApp."
                                />
                            </div>
                            <div>
                                <FormLabel for="x-email">Correo</FormLabel>
                                <FormInput
                                    id="x-email"
                                    v-model="guestEmail"
                                    type="email"
                                    autocomplete="email"
                                    class="h-11 text-sm"
                                    :class="
                                        attempted && guestEmailError
                                            ? 'border-danger'
                                            : ''
                                    "
                                    placeholder="tu@correo.com"
                                />
                                <p
                                    v-if="attempted && guestEmailError"
                                    class="mt-1.5 text-xs text-danger"
                                >
                                    {{ guestEmailError }}
                                </p>
                            </div>
                            <div>
                                <FormLabel for="x-notes"
                                    >Algo que debamos saber</FormLabel
                                >
                                <FormTextarea
                                    id="x-notes"
                                    v-model="notes"
                                    rows="2"
                                    class="text-sm"
                                    placeholder="Alguna condición, alergia, petición…"
                                />
                            </div>
                            <div
                                class="h-px w-px overflow-hidden opacity-0"
                                style="clip: rect(0, 0, 0, 0)"
                                aria-hidden="true"
                            >
                                <label for="x-website">No llenar</label>
                                <input
                                    id="x-website"
                                    v-model="honeypot"
                                    type="text"
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <p
                                v-if="submitError"
                                class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
                            >
                                <Lucide
                                    icon="CircleAlert"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                />
                                {{ submitError }}
                            </p>
                            <p
                                v-else-if="attempted && !formValid"
                                class="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                            >
                                <Lucide
                                    icon="CircleAlert"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                                />
                                Revisa lo marcado para continuar.
                            </p>
                        </form>
                        <template #footer>
                            <BackButton
                                label="Todas las experiencias"
                                @click="selected = null"
                            />
                            <Button
                                variant="primary"
                                class="ml-auto flex-1 sm:flex-none"
                                :class="primaryBtn"
                                :disabled="submitting"
                                @click="submit"
                            >
                                <Lucide
                                    :icon="
                                        submitting
                                            ? 'LoaderCircle'
                                            : 'ShieldCheck'
                                    "
                                    class="mr-2 h-4 w-4"
                                    :class="submitting && 'animate-spin'"
                                />
                                {{
                                    submitting
                                        ? 'Apartando…'
                                        : 'Apartar mi lugar'
                                }}
                            </Button>
                        </template>
                    </BookingCard>
                </template>

                <!-- ═══ Listo ═══ -->
                <template v-else>
                    <BookingCard
                        icon="CircleCheck"
                        tone="success"
                        title="¡Tu lugar quedó apartado!"
                        subtitle="Guarda tu código: te lo pueden pedir al llegar."
                    >
                        <div class="rounded-xl bg-slate-50 px-4 py-3.5">
                            <div class="text-xs text-slate-500">Tu código</div>
                            <div
                                class="font-mono text-2xl font-semibold tracking-wide text-slate-800"
                            >
                                {{ result.code }}
                            </div>
                        </div>
                        <template #footer>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-medium text-slate-600 shadow-sm transition hover:border-slate-300"
                                @click="addToCalendar"
                            >
                                <Lucide icon="CalendarPlus" class="h-4 w-4" />
                                Agregar a mi calendario
                            </button>
                        </template>
                    </BookingCard>

                    <BookingCard icon="Wallet" title="Pago">
                        <PaymentPanel
                            ref="paymentPanel"
                            :code="result.code"
                            :total="result.total"
                            :deposit="0"
                            :currency="property.currency"
                            :payment-optional="false"
                            :phone="property.phone"
                            contact-notice="Tu lugar quedó registrado; el hotel te escribe para confirmar y coordinar el pago."
                            :urls="{
                                options: '/api/experiencias/payment-options',
                                payment: `/api/experiencias/bookings/${result.code}/payment`,
                                payLater: null,
                            }"
                        />
                    </BookingCard>
                </template>
            </div>

            <aside v-if="summary" :class="result ? '' : 'lg:sticky lg:top-6'">
                <BookingCard icon="ReceiptText" title="Tu experiencia">
                    <StaySummary
                        v-bind="summary"
                        :currency="property.currency"
                        :secondary-currency="property.currency_secondary"
                        :exchange-rate="property.exchange_rate"
                    />
                </BookingCard>
            </aside>
        </div>

        <template #links>
            <a v-if="hasWizard" href="/reservar" :class="linkPill">
                <Lucide icon="BedDouble" class="h-4 w-4" />
                Reservar habitación
            </a>
            <a v-if="hasGroups" href="/reservar/grupos" :class="linkPill">
                <Lucide icon="Users" class="h-4 w-4" />
                ¿Vienen en grupo?
            </a>
            <a v-if="hasLookup" href="/reserva" :class="linkPill">
                <Lucide icon="TicketCheck" class="h-4 w-4" />
                Consultar una reserva
            </a>
        </template>
    </PublicShell>
</template>
