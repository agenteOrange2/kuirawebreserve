# Plano operativo — paneles para trabajar sin salir del plano

Módulo `plano-operativo`. Nace de la reunión del 13-ago: hay clientes que
viven en `/plano` y quieren operar todo desde ahí sin cambiar de pantalla.

## El problema

Hoy el plano vende (registrar llegada, reservar), cambia estados, abre la
ficha, extiende, mueve de cuarto y dispara el check-out. Todo lo demás obliga
a irse: dar de alta un cuarto es la sección Habitaciones, cargarle una cerveza
al 203 navega al POS, revisar la caja es Cortes, y no hay a la vista un
contador de cuántos cuartos están en uso, sucios, en limpieza o en
mantenimiento.

## Nombre: paneles, no widgets

"Widget" ya significa otra cosa en este código: el embebido del sitio web
(`/widget.js`, `WidgetScriptController`). Estas piezas se llaman **paneles**
para que nadie confunda las dos cosas al leer el código o el menú.

## Decisiones

1. **Módulo aparte** (`plano-operativo`), para venderlo por separado y
   encenderlo solo a quien lo pide. Va incluido en el Empresarial y en los
   planes legacy o creados a mano; Esencial y Profesional lo contratan como
   servicio adicional. Es feature nueva: no dárselo a esos dos no le quita
   nada a nadie.
2. **Cada usuario elige sus paneles**, recordado por dispositivo y por id de
   usuario (`kuira.plan.panels.v1.{userId}`): el mostrador comparte tablet y
   el acomodo del gerente no debe caerle a quien entra en el turno de noche.
3. **El módulo abre el tablero; cada panel se gatea por su cuenta** con el
   permiso y el módulo de SU dominio. `/plano` solo exige `rooms.view`, y eso
   no alcanza para cobrar ni para ver la caja.
4. **Crear, editar y borrar habitaciones vive detrás del candado "Editar
   plano"**; operar (estados, cobros, check-out) sigue siempre disponible.
5. **Cobrar sin turno abierto ofrece abrirlo ahí mismo**, para que el cobro
   caiga en un turno y el corte cuadre.

## Reacomodo del 18-ago: un modal por habitación, con tabs

La primera versión apilaba tarjetas en la orilla derecha del plano y cada una
hablaba del mismo cuarto: "HABITACIONES" con un aviso de candado que ocupaba una
tarjeta entera, y debajo "CONSUMOS Y COBRO" diciendo que ese cuarto no tenía a
nadie adentro. El problema de fondo era que **una habitación tenía tres
superficies**: la hoja de acciones al tocarla, la ficha en slideover y las
tarjetas del dock — y la ficha, que debía ser LA superficie, era un scroll de
1.200 líneas con el dinero, las tarifas y el historial revueltos.

Ahora hay **una sola superficie por cuarto**: un modal centrado con cuatro tabs,
que reemplazó a la ficha y absorbió los paneles de habitación y consumos. Crece
por pasos —100% en teléfono, 720 px en tablet, 960 en laptop y **1200 en pantalla
grande**— porque a 900 px el catálogo y las tablas seguían apretados; el de caja
llega a 860 px con alto acotado y scroll propio.

| Tab | Qué lleva | Gate |
|---|---|---|
| **Resumen** | Vender si está libre, quién está adentro con su cuenta, próxima reserva, precios, y el semáforo | `rooms.view` |
| **Consumos y cobro** | Catálogo, carrito y la decisión *a la cuenta* / *cobrar ahora* | `orders.manage` + `pos` + **el módulo** |
| **Historial** | Cambios de hoy y enlace al historial completo | `rooms.view` |
| **Cuarto** | Cómo es la habitación y, tras el candado, alta / edición / duplicado / borrado | edición: `rooms.manage` + candado |

Detalles que costaron y conviene no deshacer:

- **Los tabs no cambian según el estado del cuarto.** Si aparecieran y
  desaparecieran, cambiar de habitación reacomodaría la fila bajo el dedo. Un
  tab que no aplica lo dice en una línea ("la 103 no tiene a nadie adentro").
  Lo único condicional es Consumos, que depende de módulo y permiso, no del
  estado.
- **Etiquetas cortas en teléfono** (`short` en el catálogo de tabs): con las
  largas, los últimos dos quedaban fuera de la vista.
- **Dos gates para el POS**: `canChargeConsumption` (permiso + módulo `pos`)
  habilita el atajo "Cargar consumo" que navega a /pos y existe desde antes;
  `canChargeHere` exige además `plano-operativo` y es el que muestra el tab.
  Sin el módulo, el plano se comporta como antes.
- **Nada de modal sobre modal**: pedir la salida desde el modal lo cierra y abre
  el de cuenta final, porque en HeadlessUI dos diálogos se pelean el foco.
- **El dock quedó con una sola tarjeta**, el contador, así que el diálogo de
  preferencias de paneles y el botón "Paneles" se eliminaron; la única
  preferencia que se guarda es contador a la vista u oculto.
- **La caja del turno** pasó a un chip con el total en la barra que abre su
  modal. El chip y el modal leen `useCashSnapshot` (un solo estado y un solo
  reloj): dos consultas darían dos cifras distintas con dinero a la vista.

### Dónde se encienden los módulos (no cambió)

El botón "Paneles" que desapareció **no encendía nada**: era la preferencia de
cada usuario sobre qué tarjetas flotaban. Los módulos se encienden en tres
lugares, todos del lado de la plataforma:

1. `/admin → hotel → Módulos` — heredar del plan o forzar on/off por hotel
   (`tenant_modules`, `PATCH /admin/tenants/{tenant}/modules`).
2. `/admin/planes` — qué módulos trae cada plan (tabla central `plans`; ojo:
   `config/plans.php` es solo semilla).
3. `/admin/servicios` — servicio adicional que enciende módulos al contratarse.

Del lado del hotel, `/ajustes` lista los módulos con candado y un botón para
**pedir activación** (`ModuleActivationRequest`), que le llega a la plataforma.

### Cerrar la caja desde el plano (18-ago)

Dos cierres, que son dos momentos distintos:

- **Cerrar turno y cortar** — el cambio de guardia. `PATCH /api/shifts/{id}/close`
  con `auto_cut`: cierra el turno y guarda un corte por cada caja con
  movimiento, con el periodo exacto del turno. Es lo que ya hacía /turnos.
- **Cerrar solo esta caja** — cortar un ámbito a media jornada, con **motivo
  obligatorio** (queda en las notas del corte) y **efectivo contado opcional**.
  Con conteo queda el arqueo y la diferencia (que se ve en vivo antes de
  confirmar); sin conteo el corte se guarda igual. Frenar el cierre por no poder
  contar el cajón —o por ser un ámbito de pura tarjeta— era peor que un corte
  sin arqueo. El detalle contable con PDF sigue en /cortes.

Para poder cortar desde ahí, `GET /api/cash-cuts/current` ahora devuelve el
periodo también en ISO (`from_iso`/`to_iso`) y el `shift_id`; el `POST
/api/cash-cuts` que guarda el corte no cambió.

### Más detalle desde el plano (18-ago)

- **Movimientos y pendientes** en el modal de caja, bajo demanda
  (`?detail=pos|rooms`): transacción por transacción, y lo que queda por cobrar.
  Sigue fuera del refresco por defecto porque `pendingSnapshot()` recorre las
  estancias activas llamando `folio()` una por una.
- **Consumos de la estancia** en su tab: qué se entregó, cuándo, con qué método
  y si quedó pendiente, más cancelar la venta (devuelve la mercancía al
  inventario). El folio filtraba cargos a habitación sin liquidar, así que un
  refresco cobrado al momento no aparecía en ninguna pantalla.
- **Pagos y abonos** en Resumen: el saldo dice cuánto falta; esto dice por qué.

Los tres salen de dos lugares que ya existían: `GET /api/stays/{stay}/folio`
—que ganó `consumption` y `payments`— y `CashCutService`. **El folio se pide una
sola vez al abrir el cuarto** y lo miran los tabs y el diálogo de salida: dos
consultas darían dos cifras distintas con dinero enfrente.

### Historial clickeable y features traídas al modal (18-ago)

**Historial**: dejó de ser solo "cambios de hoy". Ahora lista las últimas diez
estancias del cuarto —con placa, fechas, importe y consumo— y **cada una se
abre** con su cuenta: hospedaje, pagado, saldo, lo que consumió y lo que pagó.
La que está adentro reusa la cuenta que el plano ya tiene en mano; las
anteriores piden su folio. Arriba, **lo que viene**: las reservas vivas del
cuarto, que es como se sabe hasta cuándo se puede extender a quien está dentro.
Endpoint nuevo: `GET /api/rooms/{room}/stays` (`rooms.view`), que devuelve
`stays` y `upcoming`. Abrir una cuenta exige `reservations.view`, así que el
plano manda `canViewStays` y sin eso las filas no se abren.

**Traído de otras pantallas** (todo reusa endpoints que ya existían):

| Qué | Dónde | Reusa |
|---|---|---|
| Reportar falla con foto | tab Cuarto | `POST /api/incidents` + `/photos`, módulo `incidencias` |
| Programar y retirar mantenimiento | tab Cuarto | `rooms.blocks` (store/destroy) |
| Contador de usos y liberar candado | tab Cuarto | `POST /api/rooms/{room}/usage-reset` |
| Fotos del tipo de habitación | tab Cuarto | `RoomType::photosPayload()` (con `roomType.media` en el eager load) |
| Ficha del vehículo | Resumen e Historial | `stay.vehicle_id` → `/vehiculos/{id}` |
| Imprimir o compartir la cuenta | Consumos e Historial | nuevo `GET /estancias/{stay}/cuenta.pdf` + wa.me |

El catálogo de tipos de falla viaja desde el servidor (`Incident::CATEGORIES`)
para que la UI no lo duplique, y llega vacío sin el módulo.

**La cuenta se comparte como TEXTO, no como link al PDF.** El PDF vive detrás
del permiso del panel; publicar la cuenta de un huésped en una URL abierta para
poder adjuntarla sería regalar sus datos. El mensaje de wa.me lleva el resumen
escrito, y el botón solo aparece si el huésped tiene teléfono (el contacto vive
en `Guest`, no en la estancia).

### Qué archivos quedaron

`floorplan/room/RoomDialog.vue` + `room/tabs/{SummaryTab,ChargesTab,HistoryTab,RoomTab}.vue`,
con `floorplan/context.ts` (un `provide` en vez de veinte props; viaja por el
árbol de componentes, así que sobrevive al `<Teleport>` de pantalla completa) y
tres módulos compartidos que salieron de FloorPlan.vue: `types.ts` (payload),
`status.ts` (colores del semáforo y transiciones) y `format.ts` (importes y
cuentas regresivas, con el reloj como argumento para que no haya dos tics).

**FloorPlan.vue bajó de 4.177 a ~2.830 líneas.** La guardia de diseño
(`tests/Unit/FloorPlanRoomPanelTest.php`) ahora apunta a los tabs y verifica que
el plano ya no tenga ficha adentro.

## Catálogo de paneles (primera versión, ya reacomodada)

| Panel | Gate | Reusa | Estado |
|---|---|---|---|
| **Estado de la casa** — conteo por estado y % de ocupación | `rooms.view` | `props.rooms` del plano | **HECHO** |
| **Habitaciones** — alta, edición, duplicado, borrado, estado | `rooms.manage` + candado | `POST/PATCH/DELETE /api/rooms`, `duplicate`, `updateStatus` | **HECHO** |
| **Consumos y cobro** | `orders.manage` + módulo `pos` | `POST /api/orders` | **HECHO** |
| **Caja del turno** | `orders.manage` + módulo `corte-caja` | `CashCutService`, `POST /api/shifts` | **HECHO** |

Además, dos cosas que no son panel pero completan el "operar desde el plano":
**la salida cobrando** (diálogo de cuenta final) y el **historial corto del
día** dentro del panel de habitación.

El catálogo lleva `ready` por panel: uno que todavía no existe vive ahí pero
no se ofrece — un interruptor que no prende nada es peor que no tenerlo.

**Lo que NO va en un panel** y sigue enviando a su pantalla: el perfil
completo de la habitación (20 campos, altas masivas, bloqueos por fechas), el
historial largo con gráficas, el arqueo con conteo de efectivo y el detalle de
cortes guardados con PDF. El plano es para operar, no para administrar.

## Arquitectura

- `resources/js/composables/usePlanPanels.ts` — catálogo, gates y preferencia
  por usuario. Nunca se confía en lo guardado: si al hotel le quitan el POS,
  el panel de consumos desaparece en vez de quedar como tarjeta rota.
- `resources/js/pages/tenant/floorplan/PlanDock.vue` — contenedor. Cuelga del
  mismo div del canvas, que es `relative` en los dos modos, así que **viaja
  con el `<Teleport>` a pantalla completa sin volver a montarse**.
- `resources/js/pages/tenant/floorplan/panels/*` — un componente por panel.
- `FloorPlan.vue` solo monta el dock y le pasa lo que ya tiene. Nada de lógica
  de paneles ahí adentro: ese archivo ya pasa de 4.000 líneas.

### Tres ajustes que hubo que hacer en el plano

1. **El canvas se separó en su propia capa** (`absolute inset-0`) para poder
   mover ahí el `touch-action: none` de presentación. Estaba en el contenedor
   y **apaga el scroll táctil de todo lo que cuelgue debajo**: un panel con
   lista quedaría muerto al dedo en el teléfono.
2. **Mapa de z dentro del canvas**: paneles `z-20`, botones de zoom `z-30`,
   hoja de acciones `z-40` (era `z-10`). Todo por debajo del overlay de
   presentación (`z-[55]`) y de los diálogos del theme (`z-[60]`).
3. **El viewport lleva `relative` también fuera de presentación**, que es lo
   que ancla el dock al rectángulo del canvas en los dos modos.

### Acomodo (vigente para el contador)

Columna derecha arriba en escritorio (abajo a la izquierda están los controles
de zoom, abajo a la derecha el minimapa, y por abajo sube la hoja de
acciones). En el teléfono se apila al pie dejando libre la columna del zoom, y
**arranca colapsado**: ahí el plano es lo único que cabe y el panel se abre
cuando hace falta. La columna scrollea en vez de crecer — con dos o tres
paneles encendidos el último quedaba cortado contra el borde del canvas.

El dock tapa la orilla derecha del plano; para alcanzar un cuarto de esa
franja se colapsa con "Ocultar" (los paneles siguen encendidos).

## Infraestructura: el 502 del header `Link`

Al crecer los chunks de `/plano`, el header `Link` de precarga de Vite pasó de
3 KB y, con las cookies de sesión, la respuesta rebasó el búfer de fábrica de
nginx (4 KB): **502 "upstream sent too big header" para cualquiera con
sesión**. Se subió `fastcgi_buffer_size 64k` en `nginx/conf.d/kuirawebreserve.conf`,
igual que ya lo traen crmkuiraweb y efservicevue. Si vuelve a aparecer un 502
solo al estar logueado, es esto.

## Etapas

1. **Módulo y dock** con el panel Estado de la casa — **HECHO**.
2. Panel de habitación (alta, edición, duplicado, borrado, estado) — **HECHO**.
   El formulario son tres campos: número, tipo y zona; el cuarto nuevo nace en
   el centro de lo que se está viendo (no en el origen del lienzo, que con el
   plano desplazado queda fuera de pantalla y parece que no se creó). El
   plano entrega `roomTypes` y `zones` solo a quien tiene `rooms.manage`.

   **El panel trabaja sobre su propia selección** (`panelRoomId`), aparte de la
   de la ficha: cerrar la ficha no debe vaciar el panel, y acomodando el plano
   el clic no abre ficha pero sí tiene que apuntar el panel.
3. Consumos y cobro — **HECHO**. Incluye:
   - `charge_to_room` en `POST /api/orders` (ver abajo);
   - `GET /api/pos/catalog` (`orders.manage` + módulo `pos`), porque
     `/api/products` exige `inventory.manage` —administrar el catálogo— y
     quien cobra en la caseta no lo tiene ni tiene por qué tenerlo. El mapeo
     salió a `Product::posPayload()` y lo usan el panel y la página del POS,
     para que no diverja el precio entre la caseta y el mostrador;
   - el carrito del panel vive **en memoria y con clave propia**: la página
     del POS guarda el suyo en `kuira.pos.cart` y compartirlo haría aparecer
     allá una venta a medias del plano, cargada a otra habitación;
   - **arreglado de paso**: `/pos?stay=N` se descartaba (el cajero llegaba y
     volvía a elegir la habitación, con riesgo de cargarle a otra) y el botón
     "Cargar consumo" no comprobaba el módulo `pos`, así que con el módulo
     apagado llevaba a un 403.
4. Caja — **HECHO**. `GET /api/cash-cuts/current` (`orders.manage` +
   `corte-caja`) reusa `CashCutService`: el corte en curso solo viajaba como
   prop de Inertia. La resolución de ámbito y de periodo salió del controlador
   de la página a `CashCutService::availableScopes()` y `openContext()`, que
   ahora comparten los dos. El panel también abre turno cuando no hay (un 422
   de "ya tiene un turno abierto" se trata como éxito y solo recarga).

   **No trae movimientos ni pendientes**: `pendingSnapshot()` recorre las
   estancias activas llamando `folio()` una por una, y este endpoint se
   refresca cada minuto. El arqueo y el PDF siguen en /cortes: cerrar una caja
   de prisa desde un plano es justo lo que no queremos.
5. Salida cobrando — **HECHO**. El plano mandaba el check-out sin cuerpo, así
   que con saldo pendiente recibía 422 y el mostrador se quedaba sin salida;
   el endpoint siempre aceptó método de pago y fianza. Ahora abre la cuenta
   (`GET /api/stays/{stay}/folio`), cobra y registra la salida en un paso.
   "Salir sin cobrar" existe pero es explícito (`force`).
6. Historial corto del día en el panel de habitación — **HECHO**. Sale del
   `today_history` que el payload ya traía; el historial largo (meses, ciclos
   de limpieza, gráficas) sigue enlazando a `/habitaciones/{id}/history`.

### Detalle del cambio de `CreateOrder` (etapa 3)

`CreateOrder:40` hace `$method = $stay ? 'room' : (...)`. La venta a un cuarto
en uso pasa a aceptar una bandera explícita: **sin ella todo queda como hoy**
(se carga al folio y se cobra en el check-out); **con ella** la venta conserva
su `stay_id` —para que siga contando en el historial del cuarto y del
vehículo— pero se guarda con el método real, así que el corte la cuenta y el
folio deja de verla, porque ese filtro es `payment_method = 'room'` con
`settled_at` nulo.

**No hay que sellar `settled_at`**: ese sello significa "se liquidó en el
check-out" y `VoidOrder` lo usa para prohibir cancelar. Una venta de mostrador
cobrada al momento tiene que poder cancelarse como cualquier otra.

El modo de operación decide el valor por defecto en la UI (motel cobra al
momento, hotel carga a la habitación, en "ambos" manda quien atiende), pero el
backend acepta las dos siempre.

**El default del backend es "cargar a la habitación"**, así que ningún llamador
existente (`TransitionReservation` con los extras del wizard,
`DispatchMenuRequest` con el cargo a habitación, la página del POS) cambia de
comportamiento. Se descartó a propósito la idea de que el motel puro RECHACE
el crédito a habitación: eso rompería los extras del wizard en un motel y
mete un 422 nuevo donde antes no había ninguno. La regla vive en la UI.
