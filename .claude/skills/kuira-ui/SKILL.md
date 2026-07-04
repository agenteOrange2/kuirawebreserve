---
name: kuira-ui
description: Reglas de UI de KuiraWebReserve. Usar SIEMPRE al crear o editar páginas Vue, componentes, selects, badges, toasts, mensajes del bot o cualquier texto visible al usuario. Sin emojis y solo theme Raze/Midone.
---

# UI de KuiraWebReserve: theme estricto y sin emojis

## Prohibido

- **Emojis** en cualquier texto visible: páginas Vue, `<option>` de selects, badges, toasts, placeholders, mensajes del bot y plantillas de follow-up, saludos del webchat, documentos para clientes. La iconografía se resuelve con el componente `Lucide` del theme, nunca con emojis. Antes de usar un icono, verificar que existe: `grep "@name NombreIcono$" node_modules/lucide-vue-next/dist/lucide-vue-next.d.ts` (los alias también valen, buscar sin `$` si falla).
- **Colores tailwind crudos** (`red-500`, `blue-600`, `emerald-*`, etc.). Solo tokens del theme: `primary`, `secondary`, `success`, `info`, `warning`, `pending`, `danger`, `dark`; `slate-*` únicamente para texto/bordes neutros y `darkmode-*` para modo oscuro.
- **Componentes fuera del theme**. Usar los de `resources/js/components/Base`: Button, FormInput/FormSelect/FormSwitch/FormTextarea/FormHelp/FormLabel, Dialog y Menu (Headless), Table, Lucide.
- **Tippy** (rompe el build de vite por su CSS): tooltips con atributo nativo `title`.

## Layouts e iconos: SIEMPRE los del theme

- Toda página del panel (tenant o admin) se envuelve en `RazeLayout` (`@/layouts/RazeLayout.vue`) con su prop `title`. Nunca un layout propio ni página "suelta" (única excepción: el webchat público `Chat.vue`, que es standalone a propósito).
- La retícula es la del theme: `grid grid-cols-12 gap-5` (o `gap-6`) con `col-span-12 sm:col-span-6 xl:col-span-N`. No inventar sistemas de columnas propios.
- Iconos: SOLO el componente `Lucide` del theme (`@/components/Base/Lucide`, envuelve lucide-vue-next). Nada de SVG inline, ni otras librerías de iconos, ni emojis como iconos. Verificar el nombre antes de usarlo: `grep "@name NombreIcono$" node_modules/lucide-vue-next/dist/lucide-vue-next.d.ts` (si falla, buscar sin `$`: puede existir como alias, p.ej. AlertTriangle → TriangleAlert).

## Obligatorio

- Referencias de diseño en `estructura/diseño/` (DashboardOverview8.vue es la hotelera). Replicar estructura, no inventar layouts.
- Cards: `box box--stacked`; círculos de icono `border-X/10 bg-X/10 text-X` (X = token del theme).
- Headers de página estilo reportes: título a la izquierda, botones de acción a la derecha.
- Alineación isométrica: alturas iguales entre cards vecinas (`h-full`, `flex-1`, `auto-rows-fr`); tablas anchas con `overflow-auto lg:overflow-visible` para no recortar dropdowns.
- Semáforo de habitaciones: available→success, reserved→info, occupied→primary, dirty→pending, cleaning→warning, maintenance→dark.
- Modales: header con icono en círculo + cuerpo scrolleable (`max-h-[85vh]`) + footer fijo; inputs con icono usan `pl-9`.
- Mensajes del bot y plantillas: tono cálido y profesional, en español, **sin emojis** (el system prompt del bot también lo prohíbe).

## Verificación antes de dar por terminado

1. `npx vue-tsc --noEmit | grep -cE "archivo-tocado"` debe dar 0.
2. `npm run build` sin errores.
3. Grep de emojis sobre lo tocado debe dar 0:
   `grep -rnP "[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{FE0F}]" <archivos>`
