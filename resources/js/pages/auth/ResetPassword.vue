<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import { computed, ref, toRef } from 'vue';
import AuthShell from '@/components/auth/AuthShell.vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import {
    type PasswordRequirement,
    usePasswordChecks,
} from '@/composables/usePasswordChecks';
import type { TenantBrand } from '@/types/auth';

const props = defineProps<{
    token: string;
    email: string;
    /** El enlace existe y no ha vencido (se revisa al abrirlo). */
    tokenValid?: boolean;
    requirements?: PasswordRequirement[];
    tenantBrand?: TenantBrand | null;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);

const { checks, allMet } = usePasswordChecks(
    toRef(form, 'password'),
    computed(() => props.requirements ?? []),
);
const matches = computed(
    () =>
        form.password.length > 0 &&
        form.password === form.password_confirmation,
);
const canSubmit = computed(
    () => allMet.value && matches.value && !form.processing,
);

// Vencido al abrirlo, o el servidor lo rechazó al enviar (ya usado, de otro
// correo): en los dos casos la única salida útil es pedir otro.
const expired = computed(
    () => props.tokenValid === false || Boolean(form.errors.token),
);
const newLinkHref = computed(() =>
    route('password.request', props.email ? { email: props.email } : {}),
);

const submit = () => {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Crear contraseña nueva" />
    <AuthShell
        :tenant-brand="tenantBrand"
        :heading="
            expired ? 'Este enlace ya no sirve' : 'Crea tu contraseña nueva'
        "
        :hint="
            expired
                ? 'Los enlaces vencen y se usan una sola vez. Pide otro y usa el más reciente.'
                : 'Elige una que no uses en otros sitios. Al guardarla se cierran tus otras sesiones.'
        "
    >
        <!-- Enlace vencido o usado -->
        <template v-if="expired">
            <div
                class="mt-6 flex items-start gap-3 rounded-xl border border-danger/20 bg-danger/5 p-4"
            >
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                >
                    <Lucide icon="Link2Off" class="h-4 w-4" />
                </div>
                <div class="min-w-0 text-sm text-slate-600">
                    {{
                        form.errors.token ||
                        'Venció o ya se usó. Por seguridad cada enlace sirve una sola vez.'
                    }}
                    <div
                        v-if="email"
                        class="mt-1 truncate text-xs text-slate-500"
                    >
                        Cuenta: {{ email }}
                    </div>
                </div>
            </div>
            <Link :href="newLinkHref" class="mt-5 block">
                <Button
                    type="button"
                    variant="primary"
                    rounded
                    class="w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3.5"
                >
                    <Lucide icon="Mail" class="mr-2 h-4 w-4" />
                    Pedir otro enlace
                </Button>
            </Link>
        </template>

        <!-- Formulario -->
        <form v-else class="mt-6" @submit.prevent="submit">
            <div
                class="mb-5 flex items-center gap-2.5 rounded-lg border border-slate-200/80 bg-slate-50/80 px-3.5 py-2.5 text-sm"
            >
                <Lucide icon="Mail" class="h-4 w-4 shrink-0 text-slate-400" />
                <span class="text-slate-500">Cuenta</span>
                <span class="min-w-0 truncate font-medium">{{ email }}</span>
            </div>

            <div
                v-if="form.errors.email || form.errors.password"
                class="mb-4 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger"
            >
                <p v-if="form.errors.email">{{ form.errors.email }}</p>
                <p v-if="form.errors.password">{{ form.errors.password }}</p>
            </div>

            <FormLabel htmlFor="reset-password">Contraseña nueva</FormLabel>
            <div class="relative">
                <FormInput
                    id="reset-password"
                    v-model="form.password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    autofocus
                    class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5 pr-11"
                    placeholder="************"
                />
                <button
                    type="button"
                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition hover:text-slate-600"
                    :title="
                        showPassword
                            ? 'Ocultar contraseña'
                            : 'Mostrar contraseña'
                    "
                    @click="showPassword = !showPassword"
                >
                    <Lucide
                        :icon="showPassword ? 'EyeOff' : 'Eye'"
                        class="h-4 w-4"
                    />
                </button>
            </div>

            <ul
                v-if="checks.length"
                class="mt-3 grid gap-1.5 text-xs sm:grid-cols-2"
            >
                <li
                    v-for="check in checks"
                    :key="check.key"
                    class="flex items-center gap-1.5"
                    :class="
                        check.met === true
                            ? 'text-success'
                            : check.met === null
                              ? 'text-slate-400'
                              : 'text-slate-500'
                    "
                >
                    <Lucide
                        :icon="
                            check.met === true
                                ? 'CircleCheck'
                                : check.met === null
                                  ? 'ShieldCheck'
                                  : 'Circle'
                        "
                        class="h-3.5 w-3.5 shrink-0"
                    />
                    {{ check.label }}
                </li>
            </ul>

            <FormLabel htmlFor="reset-password-confirmation" class="mt-5"
                >Confirma la contraseña</FormLabel
            >
            <div class="relative">
                <FormInput
                    id="reset-password-confirmation"
                    v-model="form.password_confirmation"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5 pr-11"
                    placeholder="************"
                />
                <Lucide
                    v-if="form.password_confirmation"
                    :icon="matches ? 'CircleCheck' : 'CircleX'"
                    :class="[
                        'absolute inset-y-0 right-0 my-auto mr-4 h-4 w-4',
                        matches ? 'text-success' : 'text-danger',
                    ]"
                />
            </div>
            <div
                v-if="form.password_confirmation && !matches"
                class="mt-1.5 text-xs text-danger"
            >
                Las dos contraseñas no coinciden.
            </div>

            <Button
                type="submit"
                variant="primary"
                rounded
                class="mt-6 w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3.5"
                :disabled="!canSubmit"
            >
                <Lucide
                    v-if="form.processing"
                    icon="Loader"
                    class="mr-2 h-5 w-5 animate-spin"
                />
                {{ form.processing ? 'Guardando...' : 'Guardar contraseña' }}
            </Button>
        </form>

        <Link
            :href="route('login')"
            class="mt-6 inline-flex items-center gap-1.5 text-sm text-slate-500 transition hover:text-primary"
        >
            <Lucide icon="ArrowLeft" class="h-4 w-4" />
            Volver a iniciar sesión
        </Link>
    </AuthShell>
</template>
