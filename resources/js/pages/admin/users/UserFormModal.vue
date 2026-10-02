<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { AdminUser } from './types';

// Alta y edición de un usuario del panel. Lo usan el listado y la ficha;
// los resguardos (uno mismo, último admin) los repite el backend.
const props = defineProps<{
    open: boolean;
    user: AdminUser | null;
    isSelf: boolean;
    isLastAdmin: boolean;
}>();

const emit = defineEmits<{
    close: [];
    saved: [user: AdminUser];
}>();

const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const form = reactive({
    name: '',
    email: '',
    phone: '',
    password: '',
    is_admin: true,
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.name = props.user?.name ?? '';
        form.email = props.user?.email ?? '';
        form.phone = props.user?.phone ?? '';
        form.password = '';
        form.is_admin = props.user?.is_admin ?? true;
        Object.keys(errors).forEach((k) => delete errors[k]);
    },
    { immediate: true },
);

const switchLocked = computed(
    () => props.user !== null && (props.isSelf || props.isLastAdmin),
);

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        const payload: Record<string, unknown> = {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
            is_admin: form.is_admin,
        };
        if (form.password) payload.password = form.password;

        const { data } = props.user
            ? await axios.patch<AdminUser>(
                  route('admin.users.update', props.user.id),
                  payload,
              )
            : await axios.post<AdminUser>(route('admin.users.store'), payload);

        emit('saved', data);
    } catch (e: any) {
        const d = e.response?.data;
        if (d?.errors) {
            Object.entries(d.errors).forEach(
                ([k, msgs]) => (errors[k] = (msgs as string[])[0]),
            );
        } else {
            errors._ = d?.message ?? 'No se pudo guardar el usuario.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
            <form
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="user ? 'UserCog' : 'UserPlus'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            {{ user ? 'Editar usuario' : 'Nuevo usuario' }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuenta del panel de plataforma
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <div>
                        <div
                            class="mb-2.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Quién es
                        </div>
                        <div class="grid grid-cols-12 gap-4">
                            <div class="col-span-12">
                                <FormLabel htmlFor="user-name" class="text-xs"
                                    >Nombre</FormLabel
                                >
                                <FormInput
                                    id="user-name"
                                    v-model="form.name"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="Ana López"
                                />
                                <FormHelp
                                    v-if="errors.name"
                                    class="text-danger"
                                    >{{ errors.name }}</FormHelp
                                >
                            </div>
                            <div class="col-span-12 sm:col-span-7">
                                <FormLabel htmlFor="user-email" class="text-xs"
                                    >Correo de acceso</FormLabel
                                >
                                <FormInput
                                    id="user-email"
                                    v-model="form.email"
                                    type="email"
                                    class="h-9 text-xs"
                                    placeholder="ana@kuiraweb.com"
                                />
                                <FormHelp
                                    v-if="errors.email"
                                    class="text-danger"
                                    >{{ errors.email }}</FormHelp
                                >
                            </div>
                            <div class="col-span-12 sm:col-span-5">
                                <FormLabel htmlFor="user-phone" class="text-xs"
                                    >Teléfono</FormLabel
                                >
                                <FormInput
                                    id="user-phone"
                                    v-model="form.phone"
                                    type="text"
                                    class="h-9 text-xs"
                                    placeholder="614 123 4567"
                                />
                                <FormHelp
                                    v-if="errors.phone"
                                    class="text-danger"
                                    >{{ errors.phone }}</FormHelp
                                >
                            </div>
                        </div>
                    </div>

                    <div>
                        <div
                            class="mb-2.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Acceso
                        </div>
                        <FormLabel htmlFor="user-password" class="text-xs"
                            >Contraseña</FormLabel
                        >
                        <FormInput
                            id="user-password"
                            v-model="form.password"
                            type="password"
                            class="h-9 text-xs"
                            :placeholder="
                                user
                                    ? 'Déjala vacía para conservar la actual'
                                    : 'Mínimo 8 caracteres'
                            "
                            autocomplete="new-password"
                        />
                        <FormHelp v-if="errors.password" class="text-danger">{{
                            errors.password
                        }}</FormHelp>

                        <div
                            class="mt-4 flex items-start justify-between gap-4 rounded-lg border border-slate-200/80 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div>
                                <div class="text-sm font-medium">
                                    Administrador de plataforma
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        switchLocked
                                            ? isSelf
                                                ? 'No puedes quitarte el acceso a ti mismo.'
                                                : 'Es el único administrador; da acceso a otro antes.'
                                            : 'Entra a este panel y a los hoteles con "Entrar como".'
                                    }}
                                </div>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="form.is_admin"
                                    :disabled="switchLocked"
                                    type="checkbox"
                                    @change="form.is_admin = !form.is_admin"
                                />
                            </FormSwitch>
                        </div>
                    </div>

                    <p
                        v-if="errors._"
                        class="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                    >
                        {{ errors._ }}
                    </p>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{
                            saving
                                ? 'Guardando...'
                                : user
                                  ? 'Guardar cambios'
                                  : 'Crear usuario'
                        }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
