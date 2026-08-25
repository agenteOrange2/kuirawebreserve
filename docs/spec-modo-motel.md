# Modo de operación hotel | motel

## ALCANCE DE HOY (demo con moteleros mañana)

**Se implementa HOY únicamente E1 (recortado) + E2: switch de modo + registro exprés en el plano.** Todo aditivo y detrás del flag `isMotel` — si algo no convence al final del día, no se activa el modo y la demo corre idéntica a hoy (kill-switch natural). Se activa en **hoteltest** vía /ajustes/general para la demo (reversible con un click).

**Diferido a después de la reunión** (queda en este plan, etapas E3-E6 + pendientes de E1):
- Cambio de default global `walkin_charge` → `'checkin'` (hoy NO se toca ningún default: el modal exprés cobra porque él mismo manda `payment_method` explícito).
- ~~Selector de modo al crear tenant en /admin~~ → HECHO también el 2026-08-11 (ver E1).
- Fix de fianza en check-in desde plano, CheckOutModal/salida, endpoint settle, POS modo motel, aligerar modal de reservas.
- Copiar este plan a `docs/spec-modo-motel.md` para irlo tachando por etapas (primer paso al salir del modo plan).

**Definición de "hecho" hoy**: ExpressWalkInTest + PropertyModeTest verdes, suite completa sin regresiones, vue-tsc limpio en tocados, build OK, migración a tenants, smoke en hoteltest: modo hotel intacto → activar motel → tap en cuarto libre → exprés con placa → cobrado (Payment visible en corte) → a-pie con identificación → desactivar modo → todo como antes.

## Contexto

Un cliente motelero dio feedback duro: el sistema está pensado para hotel; en caseta necesitan registrar, cobrar y salir en segundos, sin pedir nombre/teléfono/correo — registran con la placa del carro o, si van a pie, con la identificación.

**Diagnóstico verificado en el código:** el backend YA es laxo (un walk-in válido solo exige habitación + tarifa, ambas preseleccionadas; nombre/teléfono/correo son nullable). La fricción real es la arquitectura del flujo:
- El plano no cierra ventas: "Llegó sin reserva" navega a la página completa de `/reservas` (210 KB) y abre un modal de ~1,100 líneas con ~19 controles pensado para reservas de hotel.
- Por default `walkin_charge = 'checkout'`: el walk-in no cobra nada, y cobrar la salida desde el plano es imposible (422 "saldo pendiente" → hay que irse a /reservas). De tap a dinero cobrado: ~7-8 clicks, 3 pantallas, 2 recargas.
- Placa/vehículo escondidos (colapsable + gate módulo crm-avanzado); identificación no existe en la llegada (solo en el CRM); el correo del walk-in se teclea y se descarta en silencio.

**Solución acordada:** selector `hotel | motel` (elegible al crear el tenant en /admin, editable por el tenant en ajustes, default hotel) + en modo motel un **flujo exprés dentro del plano**: tap → tarifa en botones grandes → placa o identificación → "Registrar y cobrar" → listo. Decisiones confirmadas: identificación tipo+número (cifrada, sin fotos); el wizard público /reservar no cambia.

**Modelo de cobro (ajuste del usuario, feedback del cliente): nadie cobra al salir — todo se cobra al llegar o al reservar.** El hospedaje se cobra en la llegada (walk-in y check-in de reserva) o al reservar (anticipos/pasarela, ya existen); los consumos del POS se cobran al momento de entregarse Y quedan cargados/registrados a la habitación (para historial y folio informativo). La salida deja de ser un evento de cobro: es cerrar la estancia en un click. El cobro-en-salida queda solo como respaldo para saldos residuales.

Precedentes en el repo que se imitan: `settings['menu_billing_mode']` `'hotel'|'motel'` (toggle 2 tarjetas en `menu/Admin.vue:1210-1245`), `guest_policy` "Solo adultos (caso motel)", patrón `Property.settings` + clase Policy memoizada (`HousekeepingPolicy` / `ReservationPolicy`).

## Decisiones de diseño

1. **Llave**: `Property.settings['property_mode']` (`'hotel'|'motel'`, default `'hotel'`). Servicio nuevo `app/Services/PropertyMode.php` calcado de `HousekeepingPolicy`: `mode()`, `isMotel()`, `expressCheckInEnabled()`.
2. **El modo lo administra SOLO la plataforma (/admin)** — decisión del usuario 2026-08-11: es un ajuste comercial, no del tenant. Se elige al CREAR el tenant y se puede cambiar en EDITAR (ambos en `admin/tenants/Index.vue`, dos tarjetas hotel/motel). El tenant NO lo ve en sus ajustes y su API lo descarta en silencio (`PropertyController` no valida `settings.property_mode` a propósito). `Admin\TenantController::update` escribe el setting vía `$tenant->run()` y solo toca `property_mode` (no re-siembra); el listado expone `mode` desde el rollup cacheado `admin:tenant-ops:v2:*` (se invalida al editar).
3. **Selector en admin al crear**: radio hotel/motel; si motel, la Property nace con `['property_mode'=>'motel','guest_policy'=>'adults_only','menu_billing_mode'=>'motel']` como **semillas editables** (no derivación en runtime — motellacupula productivo no debe cambiar de conducta solo).
4. **Cobro al llegar como default GLOBAL (ambos modos)**: el default de `ReservationPolicy::walkinChargeOnCheckIn()` cambia de `'checkout'` a `'checkin'` — "todos cobran al llegar". Un tenant que quiera cobro en salida lo guarda explícito (`walkin_charge=checkout`) y ese manda. `PaymentMethodsPageController` muestra el valor efectivo vía policy. En modo motel el modal exprés además siempre manda `payment_method` (cobro garantizado en la llegada).
   **POS**: en modo motel desaparece el crédito "cargo a habitación pagadero en salida": una orden ligada a estancia exige método de pago real (efectivo/tarjeta/transfer) al entregarse y nace liquidada (`settled`), pero conserva su `stay_id` — queda cargada/registrada a la habitación para historial y folio. En modo hotel el folio con `consumption_pending` sigue disponible (feature deliberada del P0), aunque con el hospedaje ya cobrado en llegada el `grand_pending` normal será solo consumos. Precedente exacto: `menu_billing_mode='motel'` ("el pedido se paga siempre al recibirlo").
5. **Identificación**: columnas nuevas en `stays` (`id_document_type` nullable + `id_document_number` text nullable con cast `'encrypted'`), NO en Guest (el cast encrypted no permite búsquedas y un Guest sin nombre/teléfono ensucia el CRM). Si el walk-in sí creó Guest (hubo teléfono), se le copia el documento. Nunca se expone el número en payloads de lectura.
6. **Compartir al frontend**: `panelTenant.property_mode` en `HandleInertiaRequests` (reusar el `$property` ya resuelto; NUNCA dentro de `tenant` — bug documentado en el middleware).
7. **Vehículo sin gate**: en el modal exprés nunca se gatea por crm-avanzado; en el modal de reservas se quita el gate solo en modo motel.

## Etapas

### E1 — Infraestructura del modo [HOY, recortado]
- `app/Services/PropertyMode.php` (nuevo).
- `app/Http/Controllers/Tenant/PropertyController.php`: regla `settings.property_mode` (el `array_merge` existente preserva el resto).
- `app/Http/Middleware/HandleInertiaRequests.php`: `property_mode` en `panelTenant`.
- Tenant: `GeneralSettingsPageController` + `resources/js/pages/tenant/settings/General.vue`: bloque "Modo de operación" (2 tarjetas, iconos `Building2`/`CarFront`) + texto que sugiere revisar guest_policy y menú digital al cambiar a motel.
- **[DIFERIDO]** `app/Services/ReservationPolicy.php` (~línea 156): default de `walkin_charge` pasa a `'checkin'` global; `PaymentMethodsPageController.php` (líneas 79/168) muestra el efectivo vía policy.
- **[HECHO 2026-08-11]** Admin: `TenantController::store` valida `mode` y siembra `Property.settings` (motel ⇒ property_mode + guest_policy adults_only + menu_billing_mode motel, editables); `admin/tenants/Index.vue` con las dos tarjetas hotel/motel en el modal de crear. El editar NO toca el modo (lo gobierna el tenant en /ajustes/general).

### E2 — Registro exprés en el plano (modo motel) [HOY, completo]
- Migración tenant `add_id_document_to_stays` + `Stay` fillable/cast encrypted.
- `StayController::store` (42-76): reglas `id_document_type` (in `Guest::DOCUMENT_TYPES`), `id_document_number`, `guest_email` (fix: hoy se descarta); capturar el `ModelNotFoundException` de mismatch tarifa/cuarto → 422 con mensaje claro (hoy 404 crudo).
- `CreateWalkInStay`: persistir documento en Stay, copiar a Guest si existe, aceptar email. El cobro no cambia: el modal exprés siempre manda `payment_method` y el action ya cobra cuando viene.
- `FloorPlanController`: props `walkinChargeOnCheckin`, `guaranteeAmount` (mismo cálculo que ReservationsPageController:186-192).
- **`resources/js/pages/tenant/floorplan/ExpressCheckInModal.vue`** (nuevo, componente extraído): tarifas del cuarto como botones grandes (ya vienen en `room.rate_plans` con precio ajustado; preseleccionar la más barata), placa con autofocus + descripción opcional, toggle "Va a pie" → tipo+número de documento, stepper de personas (estimado de extra visible; el servidor recalcula), chips de cargos opcionales, método de pago en 3 botones (efectivo preseleccionado), fianza si aplica, botón único **"Registrar y cobrar $X"** → `POST /api/stays`. Éxito: toast + refresh de rooms + cerrar.
- `FloorPlan.vue`: en modo motel, la ficha de cuarto libre muestra botón primario grande "Registrar llegada" (abre el exprés); "Crear una reserva" queda secundario. El tap sigue abriendo la ficha (cero cambio de gesto).
- **Resultado: 1 tap + placa + 1 click = registrado y cobrado, sin salir del plano.**

### E3 — Salida en 1 click y cobros desde el plano [DIFERIDO post-reunión]
- **Con el nuevo modelo, la salida normal ya no cobra**: hospedaje pagado en la llegada + consumos pagados al entregarse ⇒ `grand_pending = 0` y la salida desde el plano es 1 click (+ devolución de fianza si aplica).
- `StayController::settle` + ruta `POST stays/{stay}/settle {method, reference?}` reutilizando `SettleStay` — para cobrar en el momento un saldo que aparezca a media estancia (caso "extendió una hora, cóbrale ya"). Sin abonos parciales (fuera de alcance).
- **`floorplan/CheckOutModal.vue`** (nuevo, AMBOS modos, como RESPALDO — arregla el 422 ciego de hoy): solo aparece cuando quedó saldo residual (estancias viejas, override checkout, excepciones): GET folio → pendiente + fianza, método de pago, toggle devolver/retener fianza (retener exige motivo), `force` opcional → `PATCH check-out` con body.
- Botón "Cobrar saldo" (estancia con `balance_due > 0`, p.ej. tras extender) → mismo modal en modo settle.
- Check-in de reserva desde plano:
  - **Fix bug (ambos modos)**: hoy `checkInReservation` manda `{}` y la fianza se salta en silencio; con fianza activa se abre mini-modal pidiendo método (igual que en /reservas).
  - **Modo motel — `floorplan/ReservationCheckInModal.vue`**: total/pagado/pendiente + fianza; check-in y cobro del saldo en la misma pantalla (`PATCH check-in` → `POST reservations/{id}/payments`; fallo del 2º paso deja estado consistente con el badge "Debe $X"). Requiere `paid_total`/`balance_due` en `upcoming_reservation` de `Room::toFloorPlanPayload` + `withSum` en FloorPlanController.

### E4 — POS: consumos cobrados al momento y cargados a la habitación (modo motel) [DIFERIDO post-reunión]
- `app/Actions/Orders/CreateOrder` (o donde viva la lógica; verificar) + `OrderController::store`: en modo motel, una orden con `stay_id` exige `payment_method` real (cash/card/transfer) — se rechaza el crédito 'room' con 422 y mensaje claro; la orden nace con `settled_at` puesto pero conserva `stay_id` (registrada a la habitación: aparece en historial del cuarto y en el folio como pagada).
- POS UI (`resources/js/pages/tenant/pos/...`, verificar página): en modo motel la opción "Cargo a habitación" se convierte en "Cobrar y registrar a la habitación" — selección de cuarto/estancia igual, pero pide método de pago al momento.
- El menú digital ya tiene su propio modo (`menu_billing_mode='motel'`); al crear tenant motel se siembra (E1), no se toca su lógica.

### E5 — Aligerar el modal de reservas del panel (modo motel) [DIFERIDO post-reunión]
- `ReservationsPageController`: `room_type_id` en el payload de ratePlans y en prefill.room; prop `adultsOnly`.
- `reservations/Index.vue` con condicionales `isMotel`: ocultar correo en walk-in (en hotel se conserva y ahora sí se persiste), ocultar niños si adults_only, placa/vehículo al nivel principal sin colapsable ni gate, label "Nombre (opcional)".
- **Fix 404 (ambos modos)**: el select de tarifas se filtra al `room_type_id` del cuarto preseleccionado.
- NO tocar el wizard público (`reservar/`) ni BookingController.

### E6 — Tests y verificación
**HOY:**
- `PropertyModeTest` (default hotel, validación, guardado, panelTenant; sin ajuste explícito el walkin_charge NO cambia hoy).
- `ExpressWalkInTest` (documento cifrado persiste, copia a Guest, email ya no se descarta, cobro en llegada crea Payment lodging+guarantee, mismatch tarifa → 422, vehículo sin módulo).
- `WalkInChargeTest` debe seguir verde SIN cambios (hoy no se toca el default).
- kuira-ui: `vue-tsc --noEmit` limpio en tocados, `npm run build`, grep de emojis = 0.
- Smoke manual en **hoteltest**: modo hotel intacto → activar motel en /ajustes/general → exprés con placa y con identificación a-pie, cobro visible en el corte del turno → desactivar modo → todo como antes.

**DIFERIDO (post-reunión):**
- `StaySettleTest` (settle liquida pendiente; salida en 1 click con folio en cero; check-out de respaldo con pago y fianza).
- `PosMotelOrderTest` (orden con stay_id en modo motel exige método real y nace liquidada con stay_id; modo hotel conserva cargo a habitación).
- Actualizar `WalkInChargeTest` cuando cambie el default global a `'checkin'`; `FloorPlanRoomPayloadTest` (campos nuevos de upcoming_reservation); test admin de siembra de settings.
- Smoke en **motellacupula** al activarle el modo (consumo POS cobrado al entregar, extender + cobrar saldo, salida 1 click con fianza).

## Orden de implementación

**HOY**: 1. E1 backend recortado (PropertyMode + PropertyController + middleware) + PropertyModeTest → 2. Selector en /ajustes/general → 3. E2 backend (migración stays, StayController, CreateWalkInStay) + ExpressWalkInTest → 4. ExpressCheckInModal + integración FloorPlan → 5. Suite completa + vue-tsc + build → 6. `tenants:migrate` + smoke en hoteltest (activar/desactivar modo).

**Post-reunión** (con lo aprendido de la demo): default global de cobro → admin create → E3 → E4 POS → E5 modal reservas → E6 cierre.

## Riesgos

- **Cambio de default global de cobro** (`walkin_charge` → `'checkin'`): afecta a tenants existentes sin ajuste explícito — sus walk-ins empezarán a cobrar en la llegada. Es deliberado ("nadie cobra al salir"), pero hay que comunicarlo; quien quiera el viejo comportamiento guarda `checkout` explícito y ese manda.
- **Hotel casi intacto**: fuera del default de cobro, todo lo demás condicionado a `isMotel`; cambios en hotel son fixes deliberados: CheckOutModal en vez del 422 ciego, fianza pedida en check-in desde plano, guest_email persistido, 422 claro en mismatch de tarifa.
- **motellacupula productivo**: activar el modo cambia POS (cobro al entregar) y walk-in (cobro al llegar) — comunicarlo antes. Migración de stays nullable, corre con `tenants:migrate` sin downtime.
- **FloorPlan.vue (3,000 líneas)**: los 3 modales nuevos viven en `resources/js/pages/tenant/floorplan/` como componentes; FloorPlan solo suma imports y ~30 líneas.
- **Cifrado**: `id_document_number` como `text` (el ciphertext crece); jamás en payloads de lectura sin gate `can:guests.view-documents`.

## Archivos críticos

- `app/Services/ReservationPolicy.php` (patrón + default suave, línea 156)
- `app/Actions/Reservations/CreateWalkInStay.php` (corazón del exprés)
- `app/Http/Controllers/Tenant/StayController.php` (store/settle/check-out)
- `resources/js/pages/tenant/FloorPlan.vue` (integración; hoy check-out sin body :1029, check-in sin fianza :1009)
- `resources/js/pages/tenant/reservations/Index.vue` (condicionales + fix select tarifas :2989)
- `app/Http/Controllers/Admin/TenantController.php` + `resources/js/pages/admin/tenants/Index.vue` (alta con modo)

---

## Iteración 2 [HECHA 2026-08-11 tarde] — feedback tras verla en vivo

- **F1 Modal exprés arreglado**: snapshot del cuarto al abrir (inmune a cerrar el slideover por debajo — causa del modal vacío), botón "Sin tarifa activa" deshabilitado cuando el tipo no tiene tarifas, stepper de personas topado a la capacidad real con leyenda "Hasta N · incluye M · extra $X c/u", alturas parejas y espaciado uniforme. Backend: `CreateWalkInStay` ahora valida capacidad (mismo `exceedsCapacity` de reservas).
- **F2 A pie = nombre + foto de INE**: nombre completo obligatorio (viaja como guest_name), foto del documento con cámara (`capture="environment"`, hasta 2: frente/reverso), staged y subidas tras crear la estancia a `POST api/stays/{stay}/id-document`. `Stay` ahora es HasMedia (colección `id_document`, disco privado `local`); se sirven por `/estancias/{stay}/documento/{media}` con permiso `guests.view-documents` (sin gate de módulo). La ficha de estancia activa del plano muestra "Identificación: INE" + links a fotos (prop `canViewDocuments`).
- **F3 Reserva-modal motel en el plano** (`floorplan/ReserveModal.vue`): "Crear una reserva" en modo motel ya NO navega a /reservas — abre modal con tarifa en botones, fechas con salida auto (payload rate_plans ahora trae `duration_unit/value`), disponibilidad en vivo (`GET /api/availability`, debounce 400ms, aviso si el cuarto del tap está ocupado), personas con capacidad, nombre+teléfono obligatorios (correo y placas opcionales), y toggle "Cobrar ahora" (efectivo/tarjeta, monto editable precargado al total) → `POST /api/reservations {confirmed:true}` + `POST payments` encadenado. Modo hotel intacto (sigue navegando).
- Tests: ExpressWalkInTest (7) + StayDocumentTest (3) + suite completa verde.

---

## Iteración 3 [HECHA 2026-08-13] — tercer modo "Ambos"

Primer cambio tras la reunión: hay propiedades que operan **hotel y motel a la vez**, así que el selector del admin deja de ser binario.

- **`PropertyMode::BOTH = 'both'`** (tercer valor de `Property.settings['property_mode']`, con `PropertyMode::MODES` como única lista válida — la usan `store` y `update` del admin).
- **Semántica del gate**: `isMotel()` pasa a significar *motel puro* y nace `isHotel()`/`isBoth()`; para **encender funcionalidad** se usan `hasMotel()` / `hasHotel()`, que en `both` son ambas true. `expressCheckInEnabled()` ahora cuelga de `hasMotel()`, así que "Ambos" tiene el registro exprés del plano sin perder nada de hotel (el modo nunca apaga funciones de hotel: solo suma atajos de caseta).
- **Semillas del alta** centralizadas en `PropertyMode::seedSettings()`: motel puro sigue naciendo como caseta (`adults_only` + `menu_billing_mode=motel`); **`both` solo fija el modo** — también vende noches a familias, no puede nacer solo-adultos. Editar el modo sigue sin re-sembrar.
- **Admin** (`admin/tenants/Index.vue`): las tres tarjetas salen de un `modeOptions` (valor/label/icono/descripción) reusado en crear y editar — el modal de editar sube a `size="lg"` y ahora muestra descripciones; el listado suma un chip con el modo en la columna Operación.
- Tests: PropertyModeTest cubre `both` (gates cruzados) y las semillas por modo.
- Pendiente de las etapas E3-E6: sin cambios, siguen diferidas.

---

## Iteración 4 [HECHA 2026-08-13] — el modo separa de verdad, y nace el registro de vehículos

Feedback operando con "Ambos" encendido: *"no diferencia aquí el motel/hotel; si es motel es discreto, cuando es hotel sí pide datos"*. Tenía razón — `expressCheckin` colapsaba `motel` y `both` en un booleano, y en "ambos" el flujo completo de hotel quedaba inalcanzable desde el plano.

### Principio que resuelve todas las bifurcaciones pendientes

**En modo puro decide la configuración; en "Ambos" decide quien atiende, venta por venta.** El sistema no puede adivinar si el que llegó es un cliente de paso o un huésped que se queda: en una propiedad que opera las dos cosas, ambas son válidas en el mismo cuarto el mismo día, y el único que lo sabe está en el mostrador. Corolario para las etapas E3-E6 que siguen diferidas: **"Ambos" es la unión de funcionalidades, nunca la intersección** — donde el modo motel *quita* algo (crédito a habitación en POS, correo y niños en el modal de reservas), en "ambos" se conserva y se ofrece.

### Infraestructura del modo (ya no es un booleano)

- `PropertyMode` gana `label()` / `labelFor()` y la lista `LABELS`.
- **Middleware `mode:`** (`app/Http/Middleware/EnsurePropertyMode.php`, alias en `bootstrap/app.php`), hermano de `EnsureModuleEnabled`: `mode:motel` o `mode:motel,both`. Ojo con la **firma variádica** — Laravel parte los parámetros por coma, así que un `string $modes` se quedaría solo con el primero (hay test que lo fija). Bloquea con `tenant/FeatureNotForMode.vue`, que a diferencia de `ModuleDisabled` **no ofrece "Ver mi plan"**: el modo no se compra, lo administra la plataforma.
- **`usePropertyMode()`** (`resources/js/composables/usePropertyMode.ts`), calcado de `useModules`: lee `panelTenant.property_mode`, que ya se compartía pero que nadie leía. El gating de UI deja de viajar como prop página por página, y `FloorPlanController` **dejó de mandar `expressCheckin`**.
- `useMenu.ts`: `MenuItem` gana `mode?: PropertyModeValue[]` y el filtro suma ese AND, igual que `module` y `permission`.
- El plano tenía la decisión escrita **dos veces** (ternarios en la ficha + `sheetWalkIn`/`sheetReserve`); ahora es un solo computed `arrivalActions` que consumen la ficha y la hoja de acciones. `RoomActionSheet` recibe la lista y emite `arrival`, sin decidir nada.

Verificado en navegador: en `hotel` dos caminos que navegan a `/reservas`; en `motel` "Registrar llegada" (exprés) y reserva rápida; en `both` **tres** caminos rotulados por lo que sirven.

### Registro de vehículos (solo motel puro)

En caseta no se registra al huésped, se registra el carro. Decisión del usuario: la sección existe **solo en motel puro** (en "ambos" el exprés sigue capturando el vehículo, pero la sección no aparece; cambiarlo es una línea en el middleware y otra en el menú).

- Tabla `vehicles`: `plate` (como se tecleó, en mayúsculas) + **`plate_normalized` único** (A-Z0-9), marca, modelo, color, año, notas, `guest_id` y veto. `stays.vehicle_id` liga la visita.
- **`Vehicle::normalizePlate()` es la única definición de "la misma placa"** y descarta lo que tiene menos de 4 caracteres útiles: en los datos reales había una placa "ABC" y descripciones tipo "N/A" que habrían creado fichas basura.
- **`App\Services\VehicleRegistry` es el único escritor** (lo llaman `CreateWalkInStay` y `TransitionReservation`), para que no nazca una tercera representación junto a `stays.vehicle_plate` y `guests.meta['vehicle']`. Enriquece sin pisar, igual que la copia de identificación al Guest.
- Convivencia declarada: `stays.vehicle_plate` es el **sello histórico** de esa noche (como `guest_name` junto a `guest_id`), `vehicles` es la ficha editable, y `vehicle_desc` se conserva pero deja de pedirse en el exprés.
- Backfill en la migración (lógica en el servicio, para poder probarla sin migrar): en producción levantó 3 fichas en motellacupula y 3 en hoteltest, y descartó la placa inválida.
- Sección `/vehiculos` con dos pestañas: **Vehículos** (buscador por placa/marca/modelo, visitas, último ingreso, adentro ahora, vetadas) y **Llegadas a pie** (las estancias con identificación, que ya existían). El número del documento va cifrado y **no se puede buscar en SQL**: la pestaña busca por nombre.
- Permisos: **sin permiso nuevo**, se reusan `guests.view` y `guests.manage` — un permiso nuevo obligaría a re-sembrar roles en cada DB de tenant.
- El exprés captura marca, modelo y color, y al teclear la placa consulta `/api/vehicles/lookup`: la segunda visita autocompleta y una placa vetada avisa **antes** de cobrar.

### Iteración 4b [2026-08-13] — la sección también en "ambos", y CRUD completo

- **Vehículos ya existe en `motel` y en `both`** (el usuario revisó su decisión al verlo en vivo): `mode:motel,both` en las rutas y `mode: ['motel','both']` en el menú. Coherente con la regla de unión — una propiedad mixta también recibe carros.
- **Mismo lenguaje visual que Huéspedes**: encabezado con icono, tarjeta de búsqueda con su explicación, y filas como tarjetas con avatar, badges (Vetada / Archivada / Adentro), entradas con la última visita, botón "Ver ficha" y menú de acciones.
- **Acciones completas**: ver ficha, editar (modal desde la lista y desde la ficha), y **archivar o eliminar** con la misma política del CRM — con historial se archiva (soft delete, migración `add_soft_deletes_to_vehicles`) y se puede restaurar; sin historial se borra de verdad. `Stay::vehicle()` usa `withTrashed()` para que el historial no pierda la ficha archivada.
- **Las llegadas a pie ganaron detalle**: no tienen ficha propia porque el dato vive en la estancia, así que el detalle abre en un diálogo con identificación (tipo, nunca el número, que va cifrado), habitación, tarifa, personas, fechas, importe, fotos y notas.
- **Gotcha que costó 24 tests**: el backfill vivía dentro de la migración y llamaba al modelo; al agregarle soft deletes en la migración siguiente, el modelo empezó a consultar `deleted_at` en un punto del historial donde la columna aún no existía. **Una migración no debe llamar modelos**: el llenado se movió al comando `vehicles:backfill` (`php artisan tenants:run vehicles:backfill`), que además sirve para cualquier tenant que se sume después.
- Datos de demostración sembrados en **motellacupula** a petición del usuario: 5 vehículos con marca/modelo/color (uno vetado), sus entradas de los últimos días y 3 llegadas a pie. Todo lleva `notes = 'Registro de prueba (demo)'` para poder borrarlo: `Stay::where('notes', 'Registro de prueba (demo)')->forceDelete()` y `Vehicle::where('notes', 'Registro de prueba (demo)')->forceDelete()`.

### Corrección [2026-08-13] — el registro exprés en carro pedía datos de a pie

Reporte del usuario: en "En carro" aparecían nombre completo, identificación, número y foto, que son de la llegada a pie. **Causa exacta**: el aviso de "placa conocida" se insertó ENTRE las dos ramas del modal, y el `v-else` de la rama a pie se emparejó con ese aviso en vez de con la rama del vehículo; así, con `arrival === 'vehicle'` y sin placa conocida, el bloque de a pie se pintaba.

Arreglo: la rama a pie usa condición **explícita** (`v-if="arrival === 'foot'"`) en vez de `v-else`, para que no dependa nunca de qué elemento tenga al lado. Regla para el futuro: en bloques que alguien va a seguir editando, `v-else` es frágil — condición explícita.

Queda como el negocio lo pide: **en carro solo placa, marca, modelo y color**; **a pie, nombre completo, tipo de identificación, número opcional y foto** (hasta dos, frente y reverso).

### Ajuste visual [2026-08-13] — el bloque del vehículo en el exprés

La placa y los datos opcionales compartían retícula de dos columnas, así que la placa —lo único que de verdad se pide en caseta— quedaba del mismo tamaño que un campo opcional, con su texto de ayuda envolviéndose y el color flotando en la columna derecha.

Ahora hay jerarquía: **la placa ocupa el renglón completo**, en caja alta, grande y con espaciado entre letras (se lee de reojo y se teclea igual venga en minúsculas), con su ayuda debajo; y **marca, modelo y color van juntos abajo** bajo el rótulo "Vehículo (opcional)", con el mismo lenguaje de secciones del modal (Tarifa, Llegada, Personas, Cobro). En móvil los tres se apilan solos.

### Ficha del vehículo [2026-08-13] — encabezado, datos y consumos

Reporte del usuario sobre `/vehiculos/{id}`: se veía amontonada, el encabezado no seguía el diseño de la ficha de huésped, no aparecían los datos del vehículo (solo las notas) y faltaba el consumo.

- **Encabezado calcado del de huésped**: retícula `grid-cols-12`, tarjeta a lo ancho con avatar en degradado del theme, la placa como título, insignias de Adentro ahora / Vetada / Archivada, subtítulo con el vehículo y desde cuándo está registrada, y a la derecha "Vehículos" (volver) y "Editar datos". Las cuatro métricas usan las mismas tarjetas con círculo de icono.
- **"Datos del vehículo"** como sección propia: placa, marca, modelo, color, año y notas — antes solo se veían las notas.
- **"Consumos", solo con el módulo `pos`** (`tenant()->hasModule('pos')` viaja como `hasPos`): tabla con venta y hora, habitación, productos, estado de cobro e importe, más los totales "Total" y "Por cobrar". El estado distingue **Cobrado** (con su método) de **Cargado a la habitación**, que es lo que decide cómo entra al corte.
- **Relación con el corte de caja**: no hubo que inventar nada — `CashCutService` ya suma las ventas del POS del turno y lista aparte las cargadas a habitación como pendientes de cobro. La ficha lo dice explícitamente para que quien revisa después sepa si ese dinero ya entró.
- **Tiempo real**: la ficha se refresca sola cada 45 s y al volver a la pestaña, con recarga parcial de Inertia (`metrics, stays, orders, ordersTotal, ordersPending`) — verificado: al recuperar el foco dispara exactamente esa recarga. Así, si la habitación está en uso, el consumo que se cargue aparece sin recargar a mano.
- Datos de demostración añadidos en motellacupula para ver la tabla: dos ventas del POS sobre las visitas del Nissan Versa (una cobrada en efectivo y otra cargada a la habitación), marcadas con `notes = 'Registro de prueba (demo)'` y sin `created_by`, así que no entran al corte de nadie.

### Ficha de la llegada a pie [2026-08-13]

A petición del usuario, quien llega a pie tiene ahora **su propia ficha**, no un diálogo: `/vehiculos/a-pie/{stay}`, hermana de la del vehículo y con la misma estructura, para que el mostrador no aprenda dos lenguajes.

- Quien llega a pie **no tiene entidad propia**: la visita ES la estancia, así que la ficha es la de esa estancia. La ruta va antes que `/vehiculos/{vehiculo}` y esa exige número, así que no se pisan; el controlador **aborta 404 si la estancia trae vehículo** (ahí la ficha correcta es la del carro) o si no dejó identificación.
- Encabezado con avatar, nombre, insignia de Adentro ahora / Estancia cerrada, y botones para volver a la pestaña y —si esa visita sí quedó ligada a un huésped— ver su ficha del CRM.
- Cuatro métricas (habitación, hospedaje, personas, consumos), "Quién llegó" (nombre, tipo de identificación, el número siempre cifrado y sin mostrar, fotos con permiso, notas), "La estancia" y **"Consumos" con el mismo gate del módulo `pos`** y la misma explicación del corte de caja.
- Mismo refresco en vivo: cada 45 s y al volver a la pestaña, con recarga parcial.
- `VehiclesPageController::ordersFor()` quedó como helper único para los consumos, usado por las dos fichas.
