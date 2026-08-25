# Plano en pantalla completa (modo presentación) y móvil

## Contexto

Feedback de la reunión del 12-ago sobre `/plano`: **"se ve muy amontonado"**. Lo que pidieron, en sus palabras:

1. Una **pantalla completa de verdad**: que no se vea ni el menú lateral, solo los recuadros de las habitaciones.
2. Que el **zoom arranque más alejado**, para ver el plano completo de un vistazo.
3. **Botones para reservar desde ahí mismo**, sin salir del plano.
4. Que en pantalla completa **no se vean** el menú lateral ni la barra de buscador y filtros (lo marcado en su captura). **No se eliminan**: siguen existiendo fuera del modo presentación.
5. Que en **celulares también se vea el plano** y se pueda manipular (hoy en móvil no hay plano).
6. Que se arreglen los **desfases** (lo que circularon: el badge "Pago vencido" encimado sobre el precio de la habitación).

Ese es el alcance de este documento. Nada de esto cambia reglas de negocio: es superficie, gestos y encuadre.

## Diagnóstico verificado en el código

- **Altura del canvas calculada a mano**: `FloorPlan.vue:1412-1415` — `height: calc(100vh - 230px)` con `hidden lg:block`. Los 230px asumen encabezado + filtros en una sola línea; cuando los filtros envuelven o aparece la línea "N de M habitaciones" (`:1400-1407`) el alto real ya no cuadra, y el plano compite además con el menú lateral (275px) y el top bar (65px, `RazeLayout:430`).
- **En móvil no hay plano**: el canvas es `lg:block` y la lista es `lg:hidden` (`:1584`). El comentario del propio código lo explica: "en móvil el arrastre y el zoom son hostiles". Es exactamente lo que el cliente quiere revertir.
- **El encuadre se hace una sola vez**: `fit-view-on-init` con `:min-zoom="0.3" :max-zoom="2.5"` (`:1419-1421`). Nadie vuelve a encuadrar cuando el contenedor cambia de tamaño (abrir/cerrar filtros, rotar el teléfono, entrar a pantalla completa), así que el "zoom alejado" que piden hoy no existe como estado.
- **Origen del desfase que circularon**: la tarjeta de habitación mide 120×80 por default (`create_rooms_table.php:22-23`) y encima lleva hasta **seis badges absolutos con offsets negativos** (`:1476-1570`): bloqueo, llega hoy, consumos/usos, excedida, sale hoy y dinero. El de dinero ("Debe $X" / "Pago vencido", `:1550-1570`) vive en `-right-2 -bottom-2` y se encima con el chip de `nodeHint` ("Desde $650.00", `:1532-1536`), que es `max-w-full truncate`. No es un caso raro: pasa siempre que una reserva próxima trae el pago vencido.
- **Minimapa con estilos de fábrica**: `<MiniMap pannable zoomable />` (`:1430`) sin overrides en `app.css` — es el recuadro gris grande de la esquina inferior derecha que suma al "amontonado".
- **Capas z**: menú lateral `z-50` (`RazeLayout:166`), top bar fijo sin z propio (`:430`), y `Dialog`/`Slideover` en `relative z-[60]` (`Headless/Dialog.vue:41`, `Headless/Slideover.vue:41`). Un overlay en **z-[55]** tapa el chrome y deja pasar por encima la ficha y los modales.
- **El drag ya está protegido**: los nodos son arrastrables solo con `canManage && editMode` (`:297`, `:390-396`). Hay que conservarlo así en táctil.

## Decisiones de diseño

1. **Modo presentación = estado del front, no una página nueva.** Un `presenting = ref(false)` en `FloorPlan.vue` que monta el canvas dentro de un `<Teleport to="body">` con `fixed inset-0 z-[55] h-[100dvh]`. El menú lateral, el top bar y la caja de buscador/filtros **no se tocan ni se borran**: quedan debajo del overlay y vuelven al salir. Kill-switch natural: si no convence, no se entra al modo y el plano queda idéntico a hoy.
2. **Teleport a `body`, no `fixed` dentro del layout.** Un ancestro con `transform`/`filter` convierte a `fixed` en relativo a ese ancestro; teleportando el overlay es hermano del layout y el `fixed` siempre es respecto al viewport.
3. **Nada de `requestFullscreen` sobre el contenedor del plano.** Los paneles de HeadlessUI se portalean a `body`: si pusiéramos en pantalla completa un div del plano, la ficha y los modales quedarían **fuera** del elemento y serían invisibles. El único fullscreen nativo seguro es sobre `document.documentElement` — que es justo lo que ya hace el botón del top bar (`RazeLayout:147-152`). Se pide de forma **oportunista** al entrar (para quitar también la barra del navegador) y se sale con `document.exitFullscreen()`; en iOS Safari no existe para elementos, así que **el overlay es la fuente de verdad** y el fullscreen nativo es un extra.
4. **Encuadre explícito, no `fit-view-on-init`.** Con `useVueFlow()` se llama `fitView({ padding: 0.12, duration: 200 })` al entrar a presentación, al salir, en `resize` y en `orientationchange` (debounce ~150ms). En presentación el `min-zoom` baja a `0.15` para que quepan plantas grandes. "Zoom más alejado" deja de ser un valor mágico: es *el plano completo con margen*, calculado.
5. **Móvil: el plano vive en presentación.** El canvas inline sigue siendo `lg:block` (en una página con scroll, el canvas pelea con el scroll del dedo), pero el botón "Ver plano" **sí se muestra en móvil** y abre el modo presentación a pantalla completa, donde el canvas es dueño de los gestos: pinch-zoom y arrastre nativos de VueFlow, `touch-action: none` en el contenedor, `:nodes-draggable="false"` salvo `editMode` (que se queda solo en escritorio, para que nadie mueva un cuarto con el pulgar — se guarda solo y ese sí sería un desfase real). **La lista móvil se conserva** como vista por default del mostrador.
6. **Acciones desde el plano: hoja inferior + la ficha de siempre.** Tocar un cuarto en presentación abre una hoja de acciones abajo (alcance del pulgar) con las mismas acciones que ya existen en la ficha: "Registrar llegada"/"Llegó sin reserva", "Crear una reserva" y "Ver ficha completa". Reusa los handlers actuales (`openReservations`, `expressOpen`, `reserveOpen`, `checkInReservation`, `checkOutStay`) — **cero lógica de negocio nueva**. "Ver ficha completa" abre el `Slideover` de hoy, que funciona sobre el overlay por el z-index de la decisión 1.
7. **Densidad por zoom.** Debajo de `zoom < 0.6` los micro-badges se ocultan y la tarjeta deja solo color, número y tipo; entre 0.6 y 1 se ocultan los secundarios (sale hoy, consumos) y se conservan bloqueo y dinero. Alejar el zoom tiene que significar *leer menos cosas*, no *ver manchas ilegibles*.
8. **Un badge por esquina, con presupuesto fijo.** El nodo gana una canaleta de 10px (`p-2.5 -m-2.5`, para que la cara de la tarjeta siga cayendo exactamente en `pos_x/pos_y` y no se mueva respecto a las zonas ni a lo ya guardado) y cada esquina admite **un solo** badge con prioridad fija:

   | Posición | Badge | Prioridad si compiten |
   |---|---|---|
   | Superior izquierda | Llega hoy | — |
   | Superior centro | Bloqueada (mantenimiento o candado de usos) | mantenimiento > candado |
   | Superior derecha | Consumos o usos | consumos > usos |
   | Inferior izquierda | Sale hoy | — |
   | Inferior centro | Excedida | — |
   | Inferior derecha | Dinero | Debe > Pago vencido |

   Además el chip de `nodeHint` deja de ser `max-w-full` y pasa a `max-w-[calc(100%-1.25rem)]`, para que ni con la canaleta se le encime nada.
9. **Minimapa solo en escritorio** dentro de presentación, con tamaño acotado; en móvil se oculta (se come pantalla y el pinch ya resuelve la navegación).
10. **Kiosco**: la preferencia se recuerda en `localStorage` y se acepta `?presentacion=1` en la URL, para que la pantalla del mostrador arranque sola en modo plano. Salir: botón visible arriba a la derecha y tecla `Esc`.

## Etapas

### E1 — Modo presentación (escritorio) [HECHO 2026-08-13]
- `presenting` + overlay teleportado (`fixed inset-0 z-[55]`, `h-[100dvh]`, fondo del theme).
- Botón "Pantalla completa" en la barra de acciones del encabezado (`FloorPlan.vue:1272-1312`), junto a "Editar plano".
- Dentro del overlay: el mismo `<VueFlow>` (se mueve el bloque, no se duplica), barra superior mínima (nombre de la propiedad, semáforo de estados, buscador compacto opcional, botón salir) y controles de zoom propios con `Button` + `Lucide` (los `<Controls>` del paquete son diminutos para táctil).
- `fitView` al entrar/salir y en `resize`; `min-zoom` 0.15 en presentación.
- `Esc` para salir; `localStorage` + `?presentacion=1`.
- El chrome del layout no se modifica: **queda tapado, no eliminado**.

### E2 — Desfases y densidad [HECHO 2026-08-13]
- Canaleta de badges + presupuesto por esquina (decisión 8) y `nodeHint` acotado: arregla lo que circularon.
- Densidad por zoom (decisión 7), leyendo el zoom con `useVueFlow().viewport`.
- Fuera el `calc(100vh - 230px)`: el canvas inline pasa a `flex-1` dentro de un contenedor con altura real, y en presentación ocupa `100dvh`.
- Minimapa acotado en escritorio, oculto en móvil.
- **Verificar en hoteltest**: en la captura, el tercer grupo (203/204) aparece sin su etiqueta de zona mientras "Planta baja" y "Piso 1" sí la tienen. Confirmar si es zona sin nombre (`buildZoneNodes:305-347` descarta las que no tienen `zone`) o un badge de la habitación tapándola; si es lo segundo, entra al presupuesto de badges.

### E3 — Táctil y móvil [HECHO 2026-08-13]
- Botón "Ver plano" visible en móvil que entra directo a presentación.
- `touch-action: none` en el contenedor del canvas dentro de presentación; pinch-zoom y arrastre de VueFlow habilitados; doble tap = acercar.
- `:nodes-draggable="false"` en táctil y en presentación salvo `editMode` de escritorio.
- `100dvh` (no `100vh`): en Safari/Chrome de celular `100vh` se mete debajo de la barra de direcciones y recorta el plano.
- La lista móvil actual se conserva intacta como vista por default.

### E4 — Acciones desde el plano [HECHO 2026-08-13]
- Hoja de acciones inferior al tocar un cuarto en presentación, con acciones por estado (libre: registrar llegada / crear reserva; reservada: check-in; ocupada: check-out, extender, cobrar; bloqueada: solo informativo), reusando los handlers existentes.
- "Ver ficha completa" abre el `Slideover` actual por encima del overlay.
- `ExpressCheckInModal` y `ReserveModal` funcionan igual dentro de presentación (mismo z-60); verificar que al cerrarlos el foco vuelva al plano y se refresquen los cuartos (`onExpressRegistered`, `onReserved`).

### E5 — Verificación [HECHO 2026-08-13]
- `vue-tsc --noEmit` limpio en tocados, `npm run build`, grep de emojis = 0.
- Smoke en hoteltest **escritorio**: entrar/salir de presentación, `Esc`, encuadre correcto al entrar y al rotar/redimensionar, ficha y modales visibles por encima, tiempo real (Echo) y refresco automático siguiendo vivos dentro del overlay.
- Smoke en **teléfono real** (Android Chrome + iOS Safari): abrir presentación, pinch-zoom, arrastre, tocar cuarto, crear una reserva completa sin salir del plano, y que la barra de direcciones no recorte el plano.
- Comprobar que **fuera** de presentación el plano queda igual que hoy salvo los arreglos de E2.

## Orden de implementación

E2 primero si se quiere una mejora visible en una tarde (arregla el desfase que ellos circularon y sirve igual en el plano de hoy); si no, el orden natural es **E1 → E2 → E3 → E4 → E5**. E1 y E2 son independientes entre sí; E3 y E4 dependen de E1.

## Riesgos

- **z-index**: el overlay debe quedar en 55. Si algún día sube por encima de 60, la ficha y los modales desaparecen detrás — es el error más fácil de cometer aquí.
- **`fixed` y ancestros con `transform`**: por eso el Teleport. Si alguien "simplifica" quitándolo, el overlay se ancla al layout y deja de cubrir el menú.
- **iOS**: sin fullscreen nativo de elementos y con `100vh` mentiroso. El overlay + `dvh` es lo que sostiene el modo en iPhone.
- **Gestos**: `touch-action: none` solo dentro de presentación; aplicarlo al canvas inline rompería el scroll de la página en tablets.
- **editMode en táctil**: si se deja activo, un arrastre accidental mueve el cuarto y **se guarda solo** (`onNodeDragStop`). Queda de escritorio.
- **FloorPlan.vue ya son 3,225 líneas**: el overlay, la hoja de acciones y la tarjeta de habitación salen como componentes en `resources/js/pages/tenant/floorplan/` (igual que `ExpressCheckInModal` y `ReserveModal`), no como más líneas en el archivo.
- **Pantalla de kiosco**: si el mostrador deja el plano abierto todo el día, confirmar que `refreshIfIdle` y el reloj (`nowMs`) no se pausen dentro del overlay.

## Archivos críticos

- `resources/js/pages/tenant/FloorPlan.vue` — canvas (`:1412-1579`), tarjeta de habitación y badges (`:1457-1576`), filtros (`:1334-1408`), lista móvil (`:1584+`), ficha (`:1887+`).
- `resources/js/pages/tenant/floorplan/` — destino de los componentes nuevos (overlay de presentación, hoja de acciones, tarjeta).
- `resources/js/layouts/RazeLayout.vue` — capas del chrome (`:166`, `:430`) y fullscreen nativo (`:147-152`). **No se modifica**.
- `app/Http/Controllers/Tenant/FloorPlanController.php` — props del plano; no debería cambiar en este spec.
- `app/Models/Room.php:400-410` — `toFloorPlanPayload` (`pos_x/pos_y/width/height`).

## Decisiones que quedan al cliente

- ¿La barra superior de presentación conserva el buscador, o va completamente limpia (solo salir y zoom)?
- ¿El modo presentación debe recordarse por dispositivo (kiosco del mostrador) o arrancar siempre apagado?
- Con el zoom alejado, ¿prefieren ver el precio "Desde $X" o el estado en texto dentro de la tarjeta cuando solo cabe una línea?

---

## Implementado [2026-08-13] — qué quedó y en qué se desvió del plan

Todo el encadenado E1-E5 está construido y verificado en hoteltest (escritorio 1440×900 e iPhone 13, con navegador real). Archivos: `resources/js/pages/tenant/FloorPlan.vue`, `floorplan/RoomActionSheet.vue` (nuevo), `floorplan/ExpressCheckInModal.vue` y `floorplan/ReserveModal.vue` (solo el pie).

**Desviación de diseño (decisión 8): la línea de abajo absorbe el dinero en vez de un badge por esquina.** Reservarle una esquina al badge de dinero seguía dejando la puerta abierta a que "Pago vencido" pisara el precio, y sumaba ruido a una tarjeta de 120×80 que el cliente quiere del mismo tamaño. Ahora la tarjeta tiene **una sola línea inferior, dentro de la tarjeta**, que muestra lo más urgente por prioridad: `Excedida > Debe $X > Pago vencido > pista normal (precio, hora o etiqueta)`. "Sale hoy" dejó de ser un badge de esquina y viaja como icono de esa misma línea. Arriba quedan tres: llega hoy (izquierda), bloqueada (centro) y consumos o usos (derecha), donde el contador **cede** si hay bloqueo porque en 120px no caben juntos. Resultado: el encimado es imposible por construcción, no por ajuste fino de anchos.

**Umbrales de densidad: 0.4 y 0.75** (no 0.6/1 como decía el plan). Con el plano completo en un teléfono el zoom se queda cerca de 0.55, y con los umbrales originales la línea inferior nunca aparecía en móvil. En rojo, "Debe" o "Excedida" se leen como alarma desde lejos aunque la palabra no se distinga, y eso es justo lo que se quiere ver de un vistazo.

**Encuadre: `fitWhenMeasured()` en vez de un `setTimeout` a ciegas.** En móvil el canvas vive oculto (`hidden lg:block`) y mide 0×0 hasta que se entra a presentación; encuadrar antes de que mida no hace nada. Se espera a que `dimensions` del store tenga tamaño real (hasta 12 intentos de 60ms).

**Esc retrocede un paso**: primero suelta el cuarto abierto y solo después sale de pantalla completa. Con fullscreen nativo el navegador se queda la primera tecla, y ahí manda `fullscreenchange` (que también sale del modo).

**Defecto encontrado y corregido de paso**: salir de presentación con un cuarto seleccionado abría su ficha de golpe al volver al panel (fuera de presentación, la selección *es* la ficha). `exitPresentation()` ahora suelta la selección.

**Pies de los modales exprés y de reserva apilados en móvil**: hasta hoy esos modales eran de escritorio en la práctica, porque en el teléfono no había plano. Al hacerlos alcanzables desde el celular, la leyenda del pie quedaba en una columna de tres palabras junto al botón; ahora se apila y el botón va a lo ancho.

**Minimapa** acotado a 180×120, con borde, y pintado con los colores del semáforo leídos de las variables CSS del theme (respeta el color que cada hotel se pone en /ajustes/general/apariencia).

**Hallazgo de datos, no de código (cierra el pendiente de E2)**: hoteltest tiene **dos** zonas ("Planta baja" con 101-106 y "Piso 1" con 201-204), pero el acomodo del plano pone 105 y 106 en el mismo renglón que 201 y 202. Como el marco de zona es la caja que envuelve a sus cuartos, los dos marcos se traslapan y a simple vista parecen tres zonas — la de abajo "sin etiqueta" es en realidad la parte baja del marco de Piso 1. Se arregla acomodando (mover 105-106 al primer renglón) o reasignando la zona; el render no tiene nada que corregir.

**Lo que quedó fuera a propósito**: la barra de presentación no lleva buscador ni filtros (era justo lo que pidieron no ver). Si lo piden después, es sumar un input a esa barra.

---

## Iteración 2 [2026-08-13] — legibilidad con muchas habitaciones

Feedback del usuario viendo el modo en vivo: *"imagínate que tuviera 80 habitaciones, el zoom se aleja y ya no se ve nada"*. Propuso icono, número más grande y más separación entre habitaciones. Lo que se hizo y por qué:

- **Más separación se descartó, y es contraintuitivo**: como el encuadre es automático (bounds ÷ pantalla), separar más las habitaciones agranda el plano, aleja el encuadre y deja las tarjetas **más chicas**; la separación que se ve en pantalla no cambia, porque escala con todo. Medido con 80 habitaciones en retícula de 10×8: con la separación actual el encuadre queda en 0.56; al duplicarla caería a ~0.4.
- **El número compensa el zoom** (lo que sí resuelve el "ya no se ve nada"). Se escribe `--room-number-size` en el contenedor del canvas (`17px / zoom`, acotado entre 18 y 52) y la tarjeta lo usa como `font-size`: el número conserva ~17px **en pantalla** sin importar qué tan lejos esté. Es una sola escritura de variable CSS por cuadro, no un re-render de las tarjetas.
- **Una marca de atención que sobrevive a cualquier zoom**: punto rojo en la esquina superior derecha cuando la habitación pide algo (estancia excedida, saldo pendiente, pago de reserva vencido, bloqueo), visible en cuanto el zoom deja de ser "cerca". También compensa el zoom (`--room-alert-size`). Se prefirió **una** marca a una familia de iconos por estado: el color ya dice el estado y los iconos competirían con el número justo cuando faltan pixeles.
- **Salto por zona en la barra de presentación**: chips "Todo el plano" + una por zona, que encuadran esa planta. En escritorio con 80 habitaciones sube de 0.56 a 0.89. **En móvil hay un piso de zoom de 0.55** para el salto: una planta de 10 cuartos es más ancha que un teléfono, así que encuadrarla tal cual daba el mismo zoom que el plano completo y el salto no servía de nada; con piso, se entra legible y se recorre con el dedo.
- **Tope de acercamiento 1.3** en el encuadre: Motel la Cúpula (9 cuartos) pasaba a 1.63 y las tarjetas se veían como espectacular; ahora queda en 1.3.
- **El tipo de habitación solo se ve de cerca** (nivel `full`): a media distancia no se lee y solo ensucia.
- **En móvil, con chips de zona, el nombre del hotel se oculta** en la barra: se cortaban los chips contra el botón "Salir", y quien mira el panel ya sabe en qué hotel está.

Medido con 80 habitaciones simuladas (inyectadas en el cliente, sin tocar datos): plano completo en escritorio 0.56 con números legibles y puntos rojos visibles; salto a planta 0.89; móvil 0.18 (solo color, número y punto) y salto a planta 0.55 con precios y horas legibles.

## Iteración 3 [2026-08-13] — editar el acomodo desde pantalla completa

Observación del usuario: en pantalla completa no estaba el botón "Editar plano". Tenía razón y además es donde mejor cabe: acomodar es justo cuando conviene tener toda la pantalla para el canvas y el menú fuera.

- La barra de presentación gana **"Editar plano"** y, con la edición encendida, **"Alinear"**, con el mismo permiso y módulo que en el panel (`canManage` + `tablero-avanzado`).
- **Sigue siendo de ratón**: `canEditLayout` exige además `matchMedia('(pointer: fine)')`. Con el dedo, el gesto de mover el plano empieza casi siempre encima de una tarjeta, así que en vez de desplazar la vista movería el cuarto — y esa posición se guarda sola. En táctil entrar a presentación sigue apagando la edición; con ratón ahora **respeta** lo que el usuario ya tenía puesto en lugar de apagárselo.
- El aviso "Modo edición…" se repite dentro del overlay: el del panel queda tapado y sin él nadie se entera de que el refresco automático está en pausa.
- Mientras se edita en pantalla completa, **el clic en un cuarto ya no abre la hoja de acciones** (se soltaba encima del plano en cada movimiento).
- Verificado en navegador: el arrastre en pantalla completa guarda (`PATCH /api/rooms/1 {"pos_x":120,"pos_y":-24}`, imantado a la cuadrícula), el clic no abre la hoja mientras se edita y vuelve a abrirla al terminar. La posición de prueba se restauró.

## Iteración 4 [2026-08-13] — la zona se pierde al acercarse, e icono por tipo

Dos observaciones del usuario sobre el plano en pantalla completa.

**1. "La agrupación se pierde entre más zoom".** El letrero de zona se dibuja dentro del canvas, anclado a la esquina de su marco: al acercarse, esa esquina se sale de la pantalla y el letrero desaparece justo cuando hay más cuartos a la vista y menos contexto; y al alejarse encogía (escalaba con el canvas) cuando más falta hacía. Dos arreglos:

- **La etiqueta persigue la parte visible de su zona.** En cada cuadro (con `requestAnimationFrame` y redondeo a 4px para no re-renderizar de más) se calcula la esquina visible en coordenadas del plano y se desplaza la etiqueta dentro de su marco, sin salirse nunca de él. Medido en hoteltest: encuadrado, las etiquetas están en su esquina (263,155) y (263,405); tras acercar 3 pasos sobre la zona quedan en (11,97) y (11,528), y tras desplazar el plano, en (16,217) y (16,648) — siempre a la vista.
- **Tamaño constante**: `transform: scale(var(--zone-label-scale))` con `transform-origin: top left`, donde la variable es `1/zoom` acotada. El chip mide ~24px en pantalla a cualquier zoom (antes se inflaba al acercarse y se perdía al alejarse).

**2. Icono por tipo de habitación, junto al número.** El nombre del tipo desaparece a media distancia (decisión de densidad); el icono no. Se agregó:

- Columna `room_types.icon` (nullable, migración tenant `2026_08_13_120000_add_icon_to_room_types`) y lista cerrada en `App\Support\RoomTypeIcons` — 14 opciones del set del theme (cama individual, matrimonial, king, suite, jacuzzi, alberca, master/VIP, preferente, familiar, con cuna, accesible, con cochera, con vista, remodelada). Se guarda el nombre del icono del theme, no una categoría intermedia.
- **Se elige, no se adivina**: cada hotel nombra sus tipos como quiere ("Master Junior VIP"), así que deducir el icono del nombre sería una lotería. El selector vive en el catálogo (`/catalogo`, sección Tipos), con "Sin icono" incluido, y la misma lista valida en el backend (`RoomTypeController::profileRules`) — un solo origen para picker y validación.
- El plano lo pinta a la izquierda del número, compensando el zoom igual que él (`calc(var(--room-number-size) * 0.78)`), así que sobrevive a todos los niveles de densidad.
- **Gotcha**: `FloorPlanController` carga `roomType` con lista de columnas explícita; sin agregar `icon` ahí el payload llegaba siempre en null aunque el dato estuviera guardado.

## Iteración 5 [2026-08-13] — el icono, arriba y centrado

Ajuste pedido: el icono del tipo va **encima del número**, centrado, en vez de a su izquierda. Apilarlo obliga a repartir los 80px de alto de la tarjeta, así que:

- El número baja su tope de 52 a 38px y la línea de abajo pierde su margen superior salvo de cerca.
- **Icono y número compensan el zoom por separado**, cada uno con su propio tamaño objetivo en pantalla (14px y 15px). Atar el icono a una fracción del número lo dejaba en ~9px justo al alejarse — que es cuando más se necesita para reconocer el tipo de un vistazo — y así lo reportó el usuario ("se pierden los iconos"). Medido después del ajuste: el icono se mantiene en 14px en pantalla de zoom 0.74 hasta 0.51, y 11px a 0.36.
- El número usa `leading-none`: no tiene descendentes y ese 25% de interlineado era justo lo que le faltaba a la tarjeta (la ocupación del alto bajó de 99% a 90% en el peor caso). El umbral de densidad mínima sube de 0.4 a 0.5, porque debajo de ahí la tarjeta ya solo aguanta icono y número.
- **El bloqueo gana el lugar**: el badge "Bloqueada" se dibuja arriba al centro, exactamente donde ahora va el icono, así que cuando aparece el icono se oculta (urge más saber que el cuarto está bloqueado que si es sencillo o suite).
- Efecto secundario que salió en la captura y se corrigió: al alejarse, el letrero de zona —que conserva su tamaño en pantalla— se montaba sobre la primera habitación. La franja de aire del marco de zona sube de 34 a 56px y la escala del letrero se topa en 2.5, que es justo lo que cabe en esa franja; abajo de ese zoom el letrero encoge en vez de invadir.

## Iteración 6 [2026-08-13] — las zonas se van a un modal

Con nombres de zona reales la fila de pastillas se rompía: en Motel la Cúpula ("Habitaciones Sencillas", "Habitación Remodelada", "Habitaciones Jacuzzi", "Habitaciones Jacuzzi VIP", "Habitación Master Junior"…) los chips se amontonaban contra el semáforo y el último quedaba cortado. La barra vuelve a tener **un solo botón**, "Buscar y filtrar", que abre `floorplan/PlanFiltersDialog.vue` (nuevo).

- El modal reúne lo que faltaba en pantalla completa: **ir a una zona** (encuadra, y cierra el modal al elegir), **buscar** por número, huésped, tipo o código, y filtrar por **estado** y **tipo**. Los tres últimos atenúan lo que no coincide, como en el panel; la zona en cambio mueve la cámara — van separados y rotulados porque son cosas distintas.
- El botón muestra la zona activa cuando hay una (recortada a 14rem para que un nombre largo no vuelva a estirar la barra) y un punto cuando hay filtros aplicados; en móvil dice solo "Filtros".
- El encabezado del modal lleva el contador "N de M habitaciones a la vista", que antes vivía debajo de los filtros del panel y en presentación no existía.
- Verificado en Motel la Cúpula (7 zonas con nombres largos): barra sin amontonarse, salto a "Habitaciones Jacuzzi VIP" encuadrando a 1.30 y cerrando el modal, filtro por texto aplicándose, y el mismo modal en iPhone a una columna con objetivos grandes.
