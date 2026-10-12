<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import RolesEditor from '@/components/staff-access/RolesEditor.vue';
import type { StaffAccessPayload } from '@/components/staff-access/types';
import RazeLayout from '@/layouts/RazeLayout.vue';

// Roles y accesos del hotel: solo el propietario entra (role:owner).
defineProps<StaffAccessPayload>();

const page = usePage();
const hotelName = computed(
    () =>
        (page.props.panelTenant as { name?: string } | null)?.name ?? undefined,
);

const endpoints = {
    store: route('tenant.roles.store'),
    update: (id: number) => route('tenant.roles.update', id),
    destroy: (id: number) => route('tenant.roles.destroy', id),
};
</script>

<template>
    <RazeLayout title="Roles y accesos">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShieldCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Roles y accesos</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Qué áreas del panel ve cada rol y qué puede hacer en
                            ellas. Lo oculto desaparece del menú y su página no
                            abre aunque se teclee.
                        </p>
                    </div>
                </div>
                <div class="flex w-full md:w-auto md:shrink-0 md:justify-end">
                    <Link
                        :href="route('tenant.users')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a usuarios
                    </Link>
                </div>
            </div>

            <RolesEditor
                :roles="roles"
                :areas="areas"
                :area-groups="areaGroups"
                :capabilities="capabilities"
                :capability-groups="capabilityGroups"
                :endpoints="endpoints"
                :hotel-name="hotelName"
            />
        </div>
    </RazeLayout>
</template>
