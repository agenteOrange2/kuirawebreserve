<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

const page = usePage();
const auth = computed(() => page.props.auth as any);

const stats = [
  {
    title: 'Servicios Activos',
    value: '142',
    change: '+12%',
    changeType: 'up',
    icon: 'Truck',
    color: 'primary',
  },
  {
    title: 'Clientes',
    value: '3,721',
    change: '+8.5%',
    changeType: 'up',
    icon: 'Users',
    color: 'success',
  },
  {
    title: 'Ingresos del Mes',
    value: '$48,200',
    change: '+22%',
    changeType: 'up',
    icon: 'DollarSign',
    color: 'primary',
  },
  {
    title: 'Órdenes Pendientes',
    value: '28',
    change: '-3%',
    changeType: 'down',
    icon: 'Clock',
    color: 'warning',
  },
];

const recentOrders = [
  { id: 'ORD-2024-001', client: 'Carlos Méndez', service: 'Transporte Local', status: 'Completado', statusColor: 'text-success', date: '05 Mar 2026', amount: '$1,250' },
  { id: 'ORD-2024-002', client: 'María López', service: 'Carga Nacional', status: 'En Proceso', statusColor: 'text-warning', date: '04 Mar 2026', amount: '$3,400' },
  { id: 'ORD-2024-003', client: 'José Hernández', service: 'Mudanza', status: 'Completado', statusColor: 'text-success', date: '03 Mar 2026', amount: '$2,100' },
  { id: 'ORD-2024-004', client: 'Ana García', service: 'Transporte Especial', status: 'Pendiente', statusColor: 'text-pending', date: '03 Mar 2026', amount: '$5,800' },
  { id: 'ORD-2024-005', client: 'Roberto Díaz', service: 'Carga Nacional', status: 'Completado', statusColor: 'text-success', date: '02 Mar 2026', amount: '$1,900' },
];

const activities = [
  { icon: 'CheckCircle', text: 'Orden ORD-2024-001 completada', time: 'Hace 2 horas', color: 'text-success' },
  { icon: 'Truck', text: 'Nuevo servicio asignado a unidad T-15', time: 'Hace 3 horas', color: 'text-primary' },
  { icon: 'UserPlus', text: 'Nuevo cliente registrado: María López', time: 'Hace 5 horas', color: 'text-info' },
  { icon: 'AlertTriangle', text: 'Unidad T-08 requiere mantenimiento', time: 'Hace 8 horas', color: 'text-warning' },
  { icon: 'FileText', text: 'Factura FV-2024-089 generada', time: 'Hace 12 horas', color: 'text-slate-500' },
];
</script>

<template>
  <Head title="Dashboard" />

  <RazeLayout>
    <div class="grid grid-cols-12 gap-y-10 gap-x-6">
      <!-- Header -->
      <div class="col-span-12">
        <div class="flex flex-col md:h-10 gap-y-3 md:items-center md:flex-row">
          <div class="text-base font-medium">
            Reporte General
          </div>
          <div class="flex flex-col sm:flex-row gap-x-3 gap-y-2 md:ml-auto">
            <div class="text-slate-500 text-sm">
              Bienvenido, {{ auth.user?.name }}
            </div>
          </div>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="col-span-12">
        <div class="grid grid-cols-12 gap-5">
          <!-- Featured Card -->
          <div class="col-span-12 p-1 md:col-span-6 2xl:col-span-3 box box--stacked">
            <div class="overflow-hidden relative flex flex-col w-full h-full p-5 rounded-[0.5rem] bg-linear-to-b from-theme-2/90 to-theme-1/[0.85] min-h-[244px]">
              <Lucide
                icon="Medal"
                class="absolute top-0 right-0 w-36 h-36 -mt-5 -mr-5 text-white/20 fill-white/[0.03] transform rotate-[-10deg] stroke-[0.3]"
              />
              <div class="mt-12 mb-9">
                <div class="text-2xl font-medium leading-snug text-white">
                  EFService
                  <br />
                  Dashboard
                </div>
                <div class="mt-1.5 text-lg text-white/70">
                  Sistema de Transporte
                </div>
              </div>
              <a class="flex items-center font-medium text-white" href="#">
                Ver reportes
                <Lucide icon="MoveRight" class="w-4 h-4 ml-1.5" />
              </a>
            </div>
          </div>

          <!-- Stat Cards -->
          <template v-for="(stat, index) in stats.slice(0, 3)" :key="index">
            <div class="flex flex-col col-span-12 p-5 md:col-span-6 2xl:col-span-3 box box--stacked">
              <Menu class="absolute top-0 right-0 mt-5 mr-5">
                <Menu.Button class="w-5 h-5 text-slate-500">
                  <Lucide
                    icon="MoreVertical"
                    class="w-6 h-6 stroke-slate-400/70 fill-slate-400/70"
                  />
                </Menu.Button>
                <Menu.Items class="w-40">
                  <Menu.Item>
                    <Lucide icon="Copy" class="w-4 h-4 mr-2" /> Copiar
                  </Menu.Item>
                  <Menu.Item>
                    <Lucide icon="FileText" class="w-4 h-4 mr-2" /> Exportar
                  </Menu.Item>
                </Menu.Items>
              </Menu>
              <div class="flex items-center">
                <div
                  :class="[
                    'flex items-center justify-center w-12 h-12 border rounded-full',
                    stat.color === 'primary' ? 'border-primary/10 bg-primary/10' : '',
                    stat.color === 'success' ? 'border-success/10 bg-success/10' : '',
                    stat.color === 'warning' ? 'border-warning/10 bg-warning/10' : '',
                  ]"
                >
                  <Lucide
                    :icon="stat.icon"
                    :class="[
                      'w-6 h-6',
                      stat.color === 'primary' ? 'text-primary fill-primary/10' : '',
                      stat.color === 'success' ? 'text-success fill-success/10' : '',
                      stat.color === 'warning' ? 'text-warning fill-warning/10' : '',
                    ]"
                  />
                </div>
                <div class="ml-4">
                  <div class="text-base font-medium">{{ stat.value }}</div>
                  <div class="text-slate-500 mt-0.5">{{ stat.title }}</div>
                </div>
              </div>
              <div class="relative mt-5 mb-6 overflow-hidden">
                <div class="absolute inset-0 h-px my-auto tracking-widest text-slate-400/60 whitespace-nowrap leading-[0] text-xs">
                  .......................................................................
                </div>
                <!-- Simple bar chart placeholder -->
                <div class="relative z-10 flex items-end h-[100px] gap-1.5 pt-8">
                  <template v-for="n in 12" :key="n">
                    <div
                      :class="[
                        'flex-1 rounded-t-sm',
                        stat.color === 'primary' ? 'bg-primary/20' : '',
                        stat.color === 'success' ? 'bg-success/20' : '',
                        stat.color === 'warning' ? 'bg-warning/20' : '',
                      ]"
                      :style="{ height: `${20 + Math.random() * 80}%` }"
                    ></div>
                  </template>
                </div>
              </div>
              <div class="flex flex-wrap items-center justify-center gap-y-3 gap-x-5">
                <div class="flex items-center">
                  <div
                    :class="[
                      'w-2 h-2 rounded-full',
                      stat.color === 'primary' ? 'bg-primary/70' : '',
                      stat.color === 'success' ? 'bg-success/70' : '',
                      stat.color === 'warning' ? 'bg-warning/70' : '',
                    ]"
                  ></div>
                  <div class="ml-2.5 text-sm">{{ stat.title }}</div>
                </div>
                <div class="flex items-center">
                  <Lucide
                    :icon="stat.changeType === 'up' ? 'TrendingUp' : 'TrendingDown'"
                    :class="[
                      'w-4 h-4 mr-1',
                      stat.changeType === 'up' ? 'text-success' : 'text-danger',
                    ]"
                  />
                  <div class="text-sm">{{ stat.change }}</div>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Quick Stats Row -->
      <div class="col-span-12">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
          <template v-for="(stat, index) in stats" :key="'quick-' + index">
            <div class="p-5 box box--stacked">
              <div class="flex items-center justify-between">
                <div>
                  <div class="text-2xl font-medium">{{ stat.value }}</div>
                  <div class="text-slate-500 mt-1 text-sm">{{ stat.title }}</div>
                </div>
                <div
                  :class="[
                    'flex items-center text-sm font-medium',
                    stat.changeType === 'up' ? 'text-success' : 'text-danger',
                  ]"
                >
                  <Lucide
                    :icon="stat.changeType === 'up' ? 'ChevronUp' : 'ChevronDown'"
                    class="w-4 h-4 mr-0.5"
                  />
                  {{ stat.change }}
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Recent Orders -->
      <div class="col-span-12 xl:col-span-8">
        <div class="flex flex-col md:h-10 gap-y-3 md:items-center md:flex-row">
          <div class="text-base font-medium">Órdenes Recientes</div>
          <a href="#" class="flex items-center text-sm font-medium text-primary md:ml-auto">
            Ver todas
            <Lucide icon="ArrowRight" class="w-4 h-4 ml-1.5" />
          </a>
        </div>
        <div class="mt-3.5 overflow-auto">
          <table class="w-full border-spacing-y-[10px] border-separate">
            <tbody>
              <tr v-for="order in recentOrders" :key="order.id">
                <td class="box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600 px-5 py-4">
                  <div class="flex items-center">
                    <Lucide icon="Package" class="w-5 h-5 text-theme-1 fill-primary/10 stroke-[1.3]" />
                    <div class="ml-3.5">
                      <a href="#" class="font-medium whitespace-nowrap">{{ order.id }}</a>
                      <div class="mt-0.5 text-xs text-slate-500 whitespace-nowrap">{{ order.service }}</div>
                    </div>
                  </div>
                </td>
                <td class="w-44 box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600 px-5 py-4">
                  <div class="mb-1 text-xs text-slate-500 whitespace-nowrap">Cliente</div>
                  <div class="flex items-center text-primary">
                    <Lucide icon="ExternalLink" class="w-3.5 h-3.5 stroke-[1.7]" />
                    <div class="ml-1.5 whitespace-nowrap">{{ order.client }}</div>
                  </div>
                </td>
                <td class="w-36 box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600 px-5 py-4">
                  <div class="mb-1 text-xs text-slate-500 whitespace-nowrap">Estado</div>
                  <div :class="['flex items-center', order.statusColor]">
                    <Lucide
                      :icon="order.status === 'Completado' ? 'CheckCircle' : order.status === 'Pendiente' ? 'Clock' : 'Loader'"
                      class="w-3.5 h-3.5 stroke-[1.7]"
                    />
                    <div class="ml-1.5 whitespace-nowrap">{{ order.status }}</div>
                  </div>
                </td>
                <td class="w-36 box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600 px-5 py-4">
                  <div class="mb-1 text-xs text-slate-500 whitespace-nowrap">Fecha</div>
                  <div class="whitespace-nowrap">{{ order.date }}</div>
                </td>
                <td class="w-28 box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600 px-5 py-4">
                  <div class="mb-1 text-xs text-slate-500 whitespace-nowrap">Monto</div>
                  <div class="font-medium whitespace-nowrap">{{ order.amount }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Activity Feed -->
      <div class="col-span-12 xl:col-span-4">
        <div class="flex flex-col md:h-10 gap-y-3 md:items-center md:flex-row">
          <div class="text-base font-medium">Actividad Reciente</div>
        </div>
        <div class="p-5 mt-3.5 box box--stacked">
          <div class="flex flex-col gap-5">
            <div
              v-for="(activity, index) in activities"
              :key="index"
              class="flex items-start"
              :class="{ 'pb-5 border-b border-dashed dark:border-darkmode-400': index < activities.length - 1 }"
            >
              <div
                :class="[
                  'flex items-center justify-center flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 dark:bg-darkmode-400',
                  activity.color,
                ]"
              >
                <Lucide :icon="activity.icon" class="w-4 h-4" />
              </div>
              <div class="ml-3.5">
                <div class="text-sm font-medium">{{ activity.text }}</div>
                <div class="text-xs text-slate-500 mt-1">{{ activity.time }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </RazeLayout>
</template>
