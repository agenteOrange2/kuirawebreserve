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
