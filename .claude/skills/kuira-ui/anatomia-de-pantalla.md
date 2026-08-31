# Anatomía de pantalla: la densidad chica del panel

Canon de diseño acordado con el dueño del producto (ago-2026) y aplicado ya a
reservas, grupos, huéspedes, habitaciones, incidencias, catálogo, inventario,
cupones, encuestas, limpieza y vehículos. **Toda pantalla nueva o retocada del
panel nace con esta anatomía**; si una vieja no la tiene, se migra al tocarla.

El resumen en una frase: *chico, parejo y sin cajas que repitan lo mismo*.

---

## 1. Escala (esto es "el diseño pequeño")

| Elemento | Medida |
|---|---|
| Botón de acción | `h-9 rounded-[0.5rem] text-xs` · icono `mr-1.5 h-3.5 w-3.5` |
| Botón secundario dentro de una tarjeta o franja | `h-8 ... text-xs` |
| Botón fantasma de renglón | `h-8 w-8 rounded-full` · icono `h-4 w-4` |
| Campo (`FormInput`, `FormSelect`) | `h-9 text-xs` · con icono `pl-9` |
| Icono dentro del campo | `absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400` |
| `FormDate` / `FormTime` | `input-class="h-9 text-xs"` (la `class` dimensiona el envoltorio) |
| Círculo del encabezado de página | `h-10 w-10` · icono `h-4 w-4` |
| Círculo del encabezado de ficha (detalle) | `h-11 w-11` · icono `h-5 w-5` |
| Círculo de cabecera de tarjeta | `h-9 w-9` · icono `h-4 w-4` |
| Avatar de renglón de lista | `h-9 w-9` (mini-tarjetas anidadas: `h-8 w-8`) |
| `h1` de la página | `text-base font-medium` |
| Subtítulo del encabezado | `mt-0.5 text-xs text-slate-500` |
| Título de tarjeta | `text-sm font-medium` + subtítulo `text-xs text-slate-500` |
| Nombre en un renglón | `text-sm font-medium`; sus datos, `text-xs` |
| Badges, chips y rótulos | `text-[11px]` (`px-2 py-0.5` o `px-2.5 py-1`) |
| Rótulo de sección | `text-[11px] font-medium tracking-wide text-slate-400 uppercase` |

**Nunca** `size="sm"` en Button (da altura inconsistente): siempre `h-8`/`h-9`
más `text-xs`. **Nunca** `min-h-11`, `text-lg`/`text-xl`/`text-2xl` en cifras de
tarjeta, ni círculos de 12/14.

Espaciado: raíz `mt-2`; entre bloques `mt-4`; retícula de cifras `gap-4`, de
columnas `gap-5`. Paddings: encabezado `p-4 sm:p-5`, cabecera de tarjeta
`px-4 py-3`, cuerpo `px-4 py-3` (o `p-4`), renglón de lista `px-4 py-3 sm:px-5`.

Constantes que conviene declarar en el `<script setup>` y reusar con `:class`:

```ts
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';
```

---

## 2. Encabezado de página (índices)

```html
<div class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between">
    <div class="flex min-w-0 items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary">
            <Lucide icon="Users" class="h-4 w-4" />
        </div>
        <div class="min-w-0">
            <h1 class="text-base font-medium">Directorio de huéspedes</h1>
            <p class="mt-0.5 text-xs text-slate-500">Una línea que diga para qué sirve.</p>
        </div>
    </div>
    <!-- Móvil: cuadrícula de 2; escritorio: fila -->
    <div class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2">
        ...botones h-9...
    </div>
</div>
```

## 3. Encabezado de ficha (detalle): franjas, no cajas sueltas

`box box--stacked overflow-hidden` partido en franjas separadas por
`border-t border-slate-200/60`:

1. **Identidad + acciones** (`p-5`): círculo teñido por estado, `h1`, badges de
   estado en `text-[11px]`, y debajo el contacto en pastillas
   (`rounded-full bg-slate-100 px-2.5 py-1 text-xs`), con teléfono `tel:` y
   correo `mailto:`. Acciones a la derecha.
2. **Avisos** que cambian cómo se atiende (archivado, veto, lista negra):
   franja propia, `px-5 py-3 text-xs`, nunca un bloque suelto abajo.
3. **Nota larga**: franja con caja `bg-slate-50`, rótulo en 11px, `line-clamp-2`
   y un "Ver nota completa" cuando pase de ~120 caracteres (las notas migradas
   son párrafos enteros).
4. **Datos duros** (`border-t bg-slate-50/70 px-5 py-3 text-xs`): los hechos de
   la operación separados por `stripDivider`, y a la derecha, con `md:ml-auto`,
   el badge que resume el saldo/estado.

### El botón "Volver"

Va **dentro del encabezado, como primer elemento del bloque de acciones**, con
forma de pastilla y a la misma altura que los botones vecinos. Nunca flotando
encima de la tarjeta ni como `Button` compitiendo con la acción principal:

```html
<Link :href="route('tenant.rooms')"
    class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600">
    <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
    Volver a habitaciones
</Link>
```

Si el bloque de acciones era condicional (`v-if="canManage"`), la condición se
baja a los botones: el volver siempre debe verse.

## 4. Tarjetas de cifras (KPIs)

Renglón compacto, nunca la caja alta con el número en `text-2xl`:

```html
<div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
    <div class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success">
            <Lucide icon="CircleCheck" class="h-4 w-4" />
        </div>
        <div class="min-w-0">
            <div class="text-sm font-medium">12</div>
            <div class="truncate text-xs text-slate-500">Disponibles ahora</div>
            <div class="truncate text-[11px] text-slate-400">Contexto opcional</div>
        </div>
    </div>
</div>
```

`auto-rows-fr` para que todas midan igual aunque una traiga tercera línea.

## 5. Listados

- Los renglones van **a ras dentro del box**, separados por
  `divide-y divide-slate-200/60 dark:divide-darkmode-400`. Nada de tarjetas
  sueltas con borde y sombra dentro de `space-y-3`.
- El **buscador y los filtros viven en el mismo box**, en franja gris
  (`border-b bg-slate-50/70 px-4 py-3`) pegada arriba de la lista.
- **Paginación** en franja propia: `border-t px-4 py-3`, enlaces
  `rounded-md px-2.5 py-1 text-xs`.
- **Selección múltiple**: los controles van en línea al final de la fila de
  filtros — contador en `text-xs text-slate-500`, "Quitar selección" como
  enlace primario y el botón `variant="danger" class="h-8 ... text-xs"`.
  (Patrón de `/habitaciones`.)
- **Acciones por renglón**: botones fantasma, gris en reposo y color al pasar
  encima. Nunca enlaces `<a>` de colores fijos.

```html
<button type="button"
    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
    title="Eliminar">
    <Lucide icon="Trash2" class="h-4 w-4" />
</button>
```

- Checkboxes: **`FormCheck.Input` del theme**. La clase `form-check-input` es de
  Bootstrap, no existe aquí y deja el cuadrito nativo del navegador.
- Nada de listas que crecen con la operación: tope + página propia paginada.

## 6. Tarjetas de contenido

Cabecera con círculo, título y subtítulo (`cardHeader` + `sectionIcon`), cuerpo
`px-4 py-3`. Para que dos columnas queden parejas: la retícula con
`items-stretch` y cada columna `flex flex-col` + la tarjeta
`flex flex-1 flex-col`. Si un bloque hace que una columna crezca de más (un QR,
una tabla larga), se baja a `col-span-12` debajo de las dos.

## 7. Modales

```html
<Dialog :open="open" size="lg" @close="...">
    <Dialog.Panel class="sm:w-[94vw] lg:w-[720px]">
        <form class="flex max-h-[calc(100dvh-6rem)] flex-col" @submit.prevent="submit">
            <!-- Cabecera fija -->
            <div class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400">
                círculo h-10 + título text-base + subtítulo text-xs + botón X (h-8 w-8)
            </div>
            <!-- Cuerpo scrolleable -->
            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                secciones con rótulo de 11px, retículas gap-4, campos h-9 text-xs
            </div>
            <!-- Pie fijo -->
            <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400">
                botones h-9 px-5 text-xs
            </div>
        </form>
    </Dialog.Panel>
</Dialog>
```

- El panel del theme se ancla a 64px del borde y **no tiene scroll propio**: sin
  `max-h` + cuerpo `overflow-y-auto`, en laptop el formulario se sale y el botón
  de guardar queda fuera de alcance.
- Formulario largo = **secciones rotuladas** ("Qué es", "Precio y control",
  "Condiciones (opcionales)"), no una pila de campos.
- Un interruptor de publicación ("Activo") queda bien a la izquierda del pie,
  frente a los botones.
- Listas de sugerencias (amenidades, etiquetas): lo elegido primero en su caja,
  y las sugerencias abajo en un cajón con tope (`max-h-28 overflow-y-auto`).
- Foto: renglón con miniatura de 56-64px y los botones a la derecha; nunca un
  bloque de 96px que empuje los campos.

## 8. Confirmación de borrado

Siempre la misma: círculo rojo `h-10 w-10`, título `text-base`, explicación
`text-xs`, todo a la izquierda, y los botones `h-9 px-5 text-xs` a la derecha.
En borrado masivo, la lista de lo seleccionado en caja punteada
`max-h-48 overflow-y-auto`. Nada de `AlertTriangle` gigante centrado.

## 9. Organización del contenido (lo que suele faltar)

- Una ficha responde primero **qué pasa ahora** (quién está dentro, qué debe),
  luego **lo que viene**, luego **lo que ya pasó**. La analítica y la ficha
  técnica van a la derecha.
- Si el encabezado ya trae las cifras, **borrar** las tarjetas que las repiten.
- Enlazar hacia donde se sigue trabajando: habitación → su ficha, huésped → CRM,
  incidencia → su ticket, y acciones que abren el flujo real
  (`/reservas?intent=reserve&room=ID`).
- Textos en español, tono directo, **sin emojis**, y sin "(opcional)" repetido en
  cada etiqueta: una nota al pie del bloque basta.

## 10. Antes de dar por terminado

1. `npx prettier --write <archivos>`
2. `npx vue-tsc --noEmit | grep <archivo>` sin salida
3. `npm run build`
4. Grep de emojis en 0
5. Si tocaste el controlador, correr los tests del módulo en el contenedor:
   `docker exec webserver-php-1 sh -c "cd /var/www/laravel/kuirawebreserve && php artisan test --filter=..."`
