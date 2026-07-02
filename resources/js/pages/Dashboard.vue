<script setup lang="ts">
import RazeLayout from '@/layouts/RazeLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
  <Head title="Dashboard" />
  
  <RazeLayout>
    <div class="grid grid-cols-12 gap-6">
      <div class="col-span-12">
        <h2 class="text-2xl font-medium">Dashboard</h2>
        <p class="text-slate-600 mt-1">Bienvenido a tu aplicación</p>
      </div>

      <!-- Welcome Card -->
      <div class="col-span-12">
        <div class="box p-8">
          <div class="flex items-center">
            <div class="w-16 h-16 flex items-center justify-center rounded-full bg-primary/10">
              <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
            </div>
            <div class="ml-6">
              <h3 class="text-xl font-medium">¡Plantilla lista para usar!</h3>
              <p class="text-slate-600 mt-1">
                Esta es tu plantilla base con Laravel + Inertia + Vue 3 + Tema Raze.
                Comienza a construir tu aplicación desde aquí.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Features -->
      <div class="col-span-12 mt-4">
        <h3 class="text-lg font-medium mb-4">Características incluidas</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-primary/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary">
                  <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">Tema Raze</h4>
                <p class="text-slate-600 text-sm mt-1">Layout profesional con menú lateral colapsable</p>
              </div>
            </div>
          </div>

          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-success/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-success">
                  <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">Autenticación</h4>
                <p class="text-slate-600 text-sm mt-1">Login, registro y gestión de perfil incluidos</p>
              </div>
            </div>
          </div>

          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-warning/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-warning">
                  <polyline points="16 18 22 12 16 6"/>
                  <polyline points="8 6 2 12 8 18"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">Componentes Base</h4>
                <p class="text-slate-600 text-sm mt-1">Librería completa de componentes UI reutilizables</p>
              </div>
            </div>
          </div>

          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-info/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                  <polyline points="3.29 7 12 12 20.71 7"/>
                  <line x1="12" x2="12" y1="22" y2="12"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">Tailwind CSS</h4>
                <p class="text-slate-600 text-sm mt-1">Framework CSS utility-first configurado</p>
              </div>
            </div>
          </div>

          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-danger/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-danger">
                  <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                  <polyline points="14 2 14 8 20 8"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">TypeScript</h4>
                <p class="text-slate-600 text-sm mt-1">Tipado estático para mayor seguridad</p>
              </div>
            </div>
          </div>

          <div class="box p-5">
            <div class="flex items-start">
              <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-primary/10 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary">
                  <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                  <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="font-medium">Inertia.js</h4>
                <p class="text-slate-600 text-sm mt-1">SPA sin complejidad de API separada</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </RazeLayout>
</template>
