<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import AuthShell from '@/components/auth/AuthShell.vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { TenantBrand } from '@/types/auth';

const props = defineProps<{
    status?: string;
    /** Correo que traía escrito en el login (o el del envío anterior). */
    email?: string;
    /** Vigencia del enlace (config auth.passwords.*.expire). */
    expireMinutes?: number;
    tenantBrand?: TenantBrand | null;
}>();

const form = useForm({ email: props.email ?? '' });

// El servidor responde lo mismo exista o no la cuenta (no delata quién
// trabaja en el hotel): con status se pasa a "revisa tu correo".
const sent = ref(Boolean(props.status));
const sentTo = ref(props.email ?? '');

// El broker no deja pedir otro enlace antes de 60 s; el botón lo respeta en
// vez de dejar que la persona choque con el error.
const RESEND_SECONDS = 60;
const cooldown = ref(sent.value ? RESEND_SECONDS : 0);
let timer: ReturnType<typeof setInterval> | null = null;

function startCooldown() {
    cooldown.value = RESEND_SECONDS;
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
        cooldown.value = Math.max(0, cooldown.value - 1);
        if (cooldown.value === 0 && timer) {
            clearInterval(timer);
            timer = null;
        }
    }, 1000);
}
if (sent.value) startCooldown();
onBeforeUnmount(() => timer && clearInterval(timer));

// Cada envío (también el reenvío) vuelve a la vista de "revisa tu correo"
// y reinicia la espera; el status puede repetirse idéntico, así que no se
// vigila el prop sino el éxito de la petición.
const submit = () => {
    const email = form.email.trim();
    form.post(route('password.email'), {
        preserveScroll: true,
        onSuccess: () => {
            if (!form.hasErrors) {
                sent.value = true;
                sentTo.value = email;
                startCooldown();
            }
        },
    });
};

function useAnotherEmail() {
    sent.value = false;
    form.clearErrors();
}

const minutes = computed(() => props.expireMinutes ?? 60);
const loginHref = computed(() => route('login'));
</script>

<template>
    <Head title="Recuperar contraseña" />
    <AuthShell
        :tenant-brand="tenantBrand"
        :heading="sent ? 'Revisa tu correo' : '¿Olvidaste tu contraseña?'"
        :hint="
            sent
                ? 'Si el correo tiene una cuenta, el enlace ya va en camino.'
                : 'Escribe el correo con el que entras al panel y te mandamos un enlace para crear una nueva.'
        "
    >
        <!-- Enviado -->
        <template v-if="sent">
            <div
                class="mt-6 rounded-xl border border-slate-200/80 bg-slate-50/80 p-4"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="MailCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs text-slate-500">Enviado a</div>
                        <div class="truncate text-sm font-medium">
                            {{ sentTo || 'tu correo' }}
                        </div>
                    </div>
                </div>
                <ul class="mt-4 space-y-2 text-xs text-slate-600">
                    <li class="flex items-start gap-2">
                        <Lucide
                            icon="Clock"
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        El enlace vence en {{ minutes }} minutos y sirve una
                        sola vez.
                    </li>
                    <li class="flex items-start gap-2">
                        <Lucide
                            icon="Inbox"
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        Si no aparece en unos minutos, busca en spam o en
                        promociones.
                    </li>
                    <li class="flex items-start gap-2">
                        <Lucide
                            icon="UserRound"
                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        ¿Tampoco llega? Pide a la gerencia que revise tu correo
                        en Usuarios.
                    </li>
                </ul>
            </div>

            <div
                v-if="form.errors.email"
                class="mt-4 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger"
            >
                {{ form.errors.email }}
            </div>

            <div class="mt-5 grid gap-2.5 sm:grid-cols-2">
                <Button
                    type="button"
                    variant="primary"
                    rounded
                    class="w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3"
                    :disabled="form.processing || cooldown > 0"
                    @click="submit"
                >
                    <Lucide
                        :icon="form.processing ? 'Loader' : 'RotateCw'"
                        :class="[
                            'mr-2 h-4 w-4',
                            form.processing && 'animate-spin',
                        ]"
                    />
                    {{
                        cooldown > 0
                            ? `Reenviar en ${cooldown} s`
                            : 'Reenviar enlace'
                    }}
                </Button>
                <Button
                    type="button"
                    variant="outline-secondary"
                    rounded
                    class="w-full bg-white py-3"
                    @click="useAnotherEmail"
                >
                    Usar otro correo
                </Button>
            </div>
        </template>

        <!-- Pedir enlace -->
        <form v-else class="mt-6" @submit.prevent="submit">
            <div
                v-if="form.errors.email"
                class="mb-4 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger"
            >
                {{ form.errors.email }}
            </div>

            <FormLabel htmlFor="forgot-email">Correo electrónico</FormLabel>
            <div class="relative">
                <Lucide
                    icon="Mail"
                    class="absolute inset-y-0 left-0 z-10 my-auto ml-4 h-4 w-4 text-slate-400"
                />
                <FormInput
                    id="forgot-email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    autofocus
                    required
                    class="block rounded-[0.6rem] border-slate-300/80 py-3.5 pr-4 pl-11"
                    placeholder="correo@ejemplo.com"
                />
            </div>

            <Button
                type="submit"
                variant="primary"
                rounded
                class="mt-6 w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3.5"
                :disabled="form.processing || !form.email.trim()"
            >
                <Lucide
                    v-if="form.processing"
                    icon="Loader"
                    class="mr-2 h-5 w-5 animate-spin"
                />
                {{ form.processing ? 'Enviando...' : 'Enviar enlace' }}
            </Button>
        </form>

        <Link
            :href="loginHref"
            class="mt-6 inline-flex items-center gap-1.5 text-sm text-slate-500 transition hover:text-primary"
        >
            <Lucide icon="ArrowLeft" class="h-4 w-4" />
            Volver a iniciar sesión
        </Link>
    </AuthShell>
</template>
