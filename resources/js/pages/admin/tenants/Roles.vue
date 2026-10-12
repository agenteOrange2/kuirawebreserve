<script setup lang="ts">
import RolesEditor from '@/components/staff-access/RolesEditor.vue';
import type { StaffAccessPayload } from '@/components/staff-access/types';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

// Roles y accesos de un hotel vistos desde la plataforma: el mismo editor
// que el propietario tiene en /usuarios/roles.
const props = defineProps<
    StaffAccessPayload & {
        tenant: TenantShell;
        plans: PlanOption[];
    }
>();

const endpoints = {
    store: route('admin.tenants.roles.store', props.tenant.id),
    update: (id: number) =>
        route('admin.tenants.roles.update', [props.tenant.id, id]),
    destroy: (id: number) =>
        route('admin.tenants.roles.destroy', [props.tenant.id, id]),
};
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Roles y accesos`">
        <TenantHeader :tenant="tenant" :plans="plans" active="roles" />

        <RolesEditor
            :roles="roles"
            :areas="areas"
            :area-groups="areaGroups"
            :capabilities="capabilities"
            :capability-groups="capabilityGroups"
            :endpoints="endpoints"
            :hotel-name="tenant.name"
        />
    </RazeLayout>
</template>
