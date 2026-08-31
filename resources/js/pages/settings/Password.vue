<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import SettingsNav from '@/components/SettingsNav.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { useSettingsRoutes } from '@/composables/useSettingsRoutes';

const props = defineProps<{
    requirements: { key: string; label: string }[];
}>();

const { routes } = useSettingsRoutes();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route(routes.value.passwordUpdate), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () =>
            form.reset('password', 'password_confirmation', 'current_password'),
    });
};

// Los requisitos se van marcando mientras escribe. "uncompromised" no se
// puede comprobar aquí (lo revisa el servidor contra filtraciones), así que
// se muestra sin palomita.
const checks = computed(() => {
    const value = form.password;

    return props.requirements.map((requirement) => {
        const met = ((): boolean | null => {
            switch (requirement.key) {
                case 'length8':
                    return value.length >= 8;
                case 'length12':
                    return value.length >= 12;
                case 'mixedCase':
                    return (
                        /[a-záéíóúñ]/.test(value) && /[A-ZÁÉÍÓÚÑ]/.test(value)
                    );
                case 'numbers':
                    return /\d/.test(value);
                case 'symbols':
                    return /[^\p{L}\d\s]/u.test(value);
                default:
                    return null; // lo valida el servidor
            }
        })();

        return { ...requirement, met };
    });
});

const matches = computed(
    () =>
        form.password.length > 0 &&
        form.password === form.password_confirmation,
);
</script>

<template>
    <Head title="Contraseña" />

    <RazeLayout title="Contraseña">
        <div class="mt-2">
            <!-- Encabezado en franja, con el dato que importa antes de
                 escribir nada: se pide la contraseña actual. -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-3 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Lock" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Cambiar contraseña
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Te pedimos la actual para confirmar que eres tú.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-col gap-5 lg:flex-row">
                <SettingsNav />

                <div class="min-w-0 flex-1">
                    <div class="grid grid-cols-12 items-stretch gap-5">
                        <div class="col-span-12 flex flex-col xl:col-span-7">
                            <form
                                class="box box--stacked flex flex-1 flex-col overflow-hidden"
                                @submit.prevent="submit"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                    >
                                        <Lucide
                                            icon="KeyRound"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">
                                            Tu nueva contraseña
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Que no la uses en otros sitios.
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-1 px-4 py-3">
                                    <FormLabel
                                        for="current_password"
                                        class="text-xs"
                                    >
                                        Contraseña actual
                                    </FormLabel>
                                    <div class="relative">
                                        <Lucide
                                            icon="Lock"
                                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                        />
                                        <FormInput
                                            id="current_password"
                                            v-model="form.current_password"
                                            type="password"
                                            autocomplete="current-password"
                                            class="h-9 pl-9 text-xs"
                                        />
                                    </div>
                                    <div
                                        v-if="form.errors.current_password"
                                        class="mt-1 text-[11px] text-danger"
                                    >
                                        {{ form.errors.current_password }}
                                    </div>

                                    <div
                                        class="mt-4 grid grid-cols-12 gap-4 border-t border-slate-200/60 pt-4 dark:border-darkmode-400"
                                    >
                                        <div class="col-span-12 sm:col-span-6">
                                            <FormLabel
                                                for="password"
                                                class="text-xs"
                                            >
                                                Nueva contraseña
                                            </FormLabel>
                                            <FormInput
                                                id="password"
                                                v-model="form.password"
                                                type="password"
                                                autocomplete="new-password"
                                                class="h-9 text-xs"
                                            />
                                            <div
                                                v-if="form.errors.password"
                                                class="mt-1 text-[11px] text-danger"
                                            >
                                                {{ form.errors.password }}
                                            </div>
                                        </div>

                                        <div class="col-span-12 sm:col-span-6">
                                            <FormLabel
                                                for="password_confirmation"
                                                class="text-xs"
                                            >
                                                Repite la nueva
                                            </FormLabel>
                                            <FormInput
                                                id="password_confirmation"
                                                v-model="
                                                    form.password_confirmation
                                                "
                                                type="password"
                                                autocomplete="new-password"
                                                class="h-9 text-xs"
                                            />
                                            <div
                                                v-if="
                                                    form.password_confirmation &&
                                                    !matches
                                                "
                                                class="mt-1 flex items-center gap-1 text-[11px] text-danger"
                                            >
                                                <Lucide
                                                    icon="X"
                                                    class="h-3 w-3"
                                                />
                                                No coinciden
                                            </div>
                                            <div
                                                v-else-if="matches"
                                                class="mt-1 flex items-center gap-1 text-[11px] text-success"
                                            >
                                                <Lucide
                                                    icon="Check"
                                                    class="h-3 w-3"
                                                />
                                                Coinciden
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="flex items-center justify-end gap-2.5 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                                >
                                    <Transition
                                        enter-active-class="transition ease-in-out"
                                        enter-from-class="opacity-0"
                                        leave-active-class="transition ease-in-out"
                                        leave-to-class="opacity-0"
                                    >
                                        <span
                                            v-show="form.recentlySuccessful"
                                            class="flex items-center gap-1 text-xs text-success"
                                        >
                                            <Lucide
                                                icon="Check"
                                                class="h-3.5 w-3.5"
                                            />
                                            Contraseña actualizada
                                        </span>
                                    </Transition>
                                    <Button
                                        variant="primary"
                                        type="submit"
                                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                                        :disabled="form.processing"
                                    >
                                        {{
                                            form.processing
                                                ? 'Guardando…'
                                                : 'Guardar contraseña'
                                        }}
                                    </Button>
                                </div>
                            </form>
                        </div>

                        <!-- Los requisitos REALES del servidor, marcándose
                             mientras escribe: antes se descubrían a base de
                             errores al guardar. -->
                        <div class="col-span-12 flex flex-col xl:col-span-5">
                            <div
                                class="box box--stacked flex flex-1 flex-col overflow-hidden"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                                    >
                                        <Lucide
                                            icon="ShieldCheck"
                                            class="h-4 w-4"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium">
                                            Qué debe cumplir
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Se revisa al guardar.
                                        </div>
                                    </div>
                                </div>
                                <ul class="flex-1 px-4 py-3">
                                    <li
                                        v-for="check in checks"
                                        :key="check.key"
                                        class="flex items-start gap-2 py-1.5 text-xs"
                                    >
                                        <Lucide
                                            :icon="
                                                check.met === null
                                                    ? 'Circle'
                                                    : check.met
                                                      ? 'CircleCheck'
                                                      : 'Circle'
                                            "
                                            :class="[
                                                'mt-0.5 h-3.5 w-3.5 shrink-0',
                                                check.met === true
                                                    ? 'text-success'
                                                    : 'text-slate-300',
                                            ]"
                                        />
                                        <span
                                            :class="
                                                check.met === true
                                                    ? 'text-slate-700 dark:text-slate-300'
                                                    : 'text-slate-500'
                                            "
                                        >
                                            {{ check.label }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
