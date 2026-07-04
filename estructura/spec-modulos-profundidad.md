# Spec de profundización — Módulos a detalle

> Documento vivo. **v2.0** — complementa `spec-reservas-multitenant.md`.
> Objetivo: pasar de "esqueleto funcional" a producto completo. Cada sección
> define: campos, comportamiento de UI, permisos y prioridad.
>
> Prioridades: **P0** = siguiente iteración · **P1** = después de P0 · **P2** = más adelante.
>
> Changelog: v1.0 base · v2.0 profundiza Habitaciones, Plano, Inventario+POS y
> agrega la sección completa de **Agentes IA multicanal** (§10).

---

## 1. Huéspedes / Clientes (CRM) — **P0 · ✅ HECHO (Iteración A)**

Hoy `guests` solo tiene name/phone/email. Es el módulo que más valor agrega:
el hotel debe reconocer a quien regresa.

### Modelo `Guest` (ampliar)
| Campo | Tipo | Notas |
|---|---|---|
| `first_name` / `last_name` | string | Separados (hoy `name` va junto) |
| `email` | string nullable | único por tenant si no es null |
| `phone` | string nullable | formato E.164, es la llave de identidad para WhatsApp (fase 4) |
| `birth_date` | date nullable | |
| `nationality` | string nullable | |
| `address` / `city` / `state` / `zip` | nullable | |
| `id_document_type` | enum: ine, pasaporte, licencia, otro | |
| `id_document_number` | string nullable | **encriptado** (cast `encrypted`) |
| **Foto de INE/documento** | medialibrary collection `documents` | 1-2 imágenes (frente/reverso), disco privado, solo visible con permiso |
| `photo` | medialibrary collection `avatar` | opcional |
| `notes` | text | notas internas del staff ("prefiere piso alto") |
| `is_blacklisted` | bool + `blacklist_reason` | al reservar/check-in muestra alerta roja |
| `marketing_consent` | bool | para campañas futuras |
| `meta` | json | extensible |

### Historial y métricas (calculadas, no columnas)
- **Visitas**: lista de estancias y reservas pasadas (fecha, habitación, noches, monto).
- **Totales**: nº visitas, total gastado (hospedaje + consumos), promedio por visita, última visita.
- **Comportamiento**: nº cancelaciones, nº no-shows → score de confiabilidad simple.

### UI
- **Página "Huéspedes"** (menú HOTEL): tabla con búsqueda (nombre/tel/email), filtros (blacklist, frecuentes), paginación.
- **Perfil del huésped**: datos + documentos + timeline de visitas + consumos. Botón "Nueva reserva" pre-llenado.
- **En el flujo de reserva/walk-in**: buscador de huésped por teléfono/nombre con autocompletado; si existe, muestra "Cliente frecuente · 5 visitas" o alerta de blacklist; si no, alta rápida inline (solo nombre+tel) y el perfil se completa después.
- **Check-in**: momento natural para capturar INE (subir foto desde el panel) y completar datos.

### Permisos
- `guests.view` / `guests.manage` (front-desk, manager, owner).
- Ver documento de identidad: permiso dedicado `guests.view-documents` (incluye front-desk porque hace el check-in).

### Pendiente P1/P2 del CRM
- Etiquetas/tags libres ("VIP", "empresa X", "quincenal") con filtro.
- Fusionar duplicados (mismo humano con 2 teléfonos).
- Exportar CSV de huéspedes con consentimiento de marketing.
- Cumpleaños del mes (para atención/campaña).

---

## 2. Habitaciones — **P0/P1 (ampliado v2)**

### 2.1 Modelo `Room` (ampliar)
| Campo | Tipo | Notas |
|---|---|---|
| `name` | string nullable | Nombre además del número: "Suite Presidencial" |
| `description` | text nullable | comercial (widget web / bots la usan) |
| `beds` | json | `[{"type":"king","qty":1},{"type":"individual","qty":2}]` — catálogo de tipos de cama: king, queen, matrimonial, individual, litera, sofá-cama |
| `max_occupancy` | int nullable | override del capacity del tipo |
| `size_m2` | decimal nullable | |
| `view` | string nullable | vista: mar, jardín, ciudad, interior |
| `amenities` | json | extra/override sobre las del tipo (jacuzzi propio) — con **catálogo central de amenities** (icono + nombre) para consistencia |
| `smoking` / `accessible` | bool | fumador · accesibilidad/planta baja |
| **Fotos** | medialibrary `photos` | galería con orden manual y foto de portada |
| `price_modifier` | decimal nullable | ajuste sobre la tarifa del tipo (+$100 por vista al mar, −$50 interior) — el motor de precios lo suma |
| `maintenance_notes` | text | por qué está fuera de servicio |

### 2.2 Incidencias de mantenimiento (mini-módulo) — **P1**
Tabla `room_issues`: `room_id, title, description, photo?, priority(baja|media|alta), status(open|in_progress|resolved), reported_by, resolved_by, resolved_at, cost`.
- Cualquier rol puede **reportar** (housekeeping encuentra el foco fundido); manager resuelve/cierra.
- Una incidencia **alta** abierta sugiere poner la habitación en `maintenance`.
- En el plano: icono de llave inglesa sobre el nodo si tiene incidencias abiertas.
- Historial de incidencias por habitación con costos → costo de mantenimiento acumulado por habitación (reporte).

### 2.3 Bloqueos programados — **P1**
Tabla `room_blocks`: `room_id, starts_at, ends_at, reason`. Ej: "remodelación del 10 al 15".
- El **motor de disponibilidad** los trata como reservas bloqueantes (mismo query de solape).
- Al llegar la fecha, el scheduler pone la habitación en `maintenance`; al terminar, la libera.

### 2.3.1 Auto-checkout por vencimiento — **✅ HECHO (Iteración B/C)**
Scheduler `stays:auto-checkout` (cada minuto, por tenant): estancia activa con
`planned_end_at` + gracia vencidos → check-out automático y la habitación cae a
**sucia**; housekeeping la ve en el plano (Reverb) y sigue sucia → limpieza →
disponible. Config `reservations.auto_checkout` (enabled + `grace_minutes`,
default 15; env `RESERVATION_AUTO_CHECKOUT[_GRACE]`). El log del semáforo marca
`auto=true` y el plano lo muestra con chip "auto".

### 2.4 Housekeeping pro — **P1/P2**
- **Checklist de limpieza** por tipo de habitación (plantilla: baño, sábanas, minibar, amenities); al pasar a `cleaning`, la camarista ve/marca el checklist (desde móvil).
- **Tiempos**: `room_status_logs` ya registra todo → medir minutos promedio de limpieza por habitación/camarista; alertar limpieza que excede X min.
- Asignación de camarista del día (quién limpia qué zona) — P2.
- **QR por habitación** (P2): pegado en la puerta; housekeeping lo escanea para marcar "limpia" sin abrir el panel; a futuro el mismo QR sirve al huésped para menú de room service.

### 2.5 Estadísticas por habitación (en su ficha) — **P1**
- % ocupación del mes, ingresos generados (hospedaje + consumos), nº estancias.
- Ranking "habitaciones más vendidas" (en reportes fase 5).
- Últimos 10 huéspedes que la ocuparon (link al CRM).

### 2.6 Tarifas más ricas (extiende RatePlan)

#### 2.6.1 Duración con unidad — **P0 · pedida explícita**
Hay habitaciones que se rentan por **horas, días, semanas o meses** (rato de
motel, noche de hotel, renta semanal, residencia mensual). La tarifa define
su periodo con **valor + unidad**:

| Campo | Detalle |
|---|---|
| `type` | `night` (caso especial: cuenta noches calendario) o `block` (periodo genérico) |
| `duration_unit` | **minute · hour · day · week · month** |
| `duration_value` | entero ≥1 — "3 horas", "1 semana", "2 meses" |

- **Pricing**: unidades = techo del rango entre la duración (4.5 h en tarifa
  de 3 h → 2 bloques). Los **meses son calendario** (del 5 feb al 5 mar = 1
  mes, no 30 días fijos).
- `suggestedEnd`: inicio + duración (pre-llena la salida en panel y bots).
- La UI de tarifas vive en el **catálogo** (tipos de habitación), con selector
  de unidad; el POS/reservas muestran la duración legible ("$4,500 / semana").

#### 2.6.2 Ventana de reserva (antelación) — **P0 · pedida explícita**
Cuánta anticipación exige la tarifa para poder reservar, **personalizable**:

| Campo | Detalle |
|---|---|
| `min_advance_value` + `min_advance_unit` | hour · day · week — "reservar con mínimo 4 horas de antelación" / "1 semana antes" |
| `max_advance_value` + `max_advance_unit` | (P1) no aceptar reservas a más de X de distancia ("máximo 3 meses adelante") |

- El **motor la hace cumplir** al crear la reserva (panel, API pública y bots
  por igual): si la llegada está más cerca que la antelación mínima → rechazo
  con mensaje claro ("Esta tarifa requiere reservar con al menos 4 horas de
  antelación").
- El **walk-in NO aplica antelación** (es ocupación inmediata en mostrador).
- El endpoint de disponibilidad avisa la violación **antes** de intentar crear
  (el panel/bot muestran el error temprano).

#### 2.6.3 Cobro anticipado — **P0 · pedida explícita**
Habilitable **por tarifa** (si la tarifa no lo configura, la reserva no exige
pago anticipado):

| Campo | Detalle |
|---|---|
| `deposit_percent` | % del total que se cobra como **anticipo** al reservar (ej. 20%) |
| `payment_due_value` + `payment_due_unit` | hour · day · week — cuándo debe estar **liquidado el total** antes de la llegada (ej. "1 semana antes"), personalizable |

Comportamiento:
- Al crear la reserva se calculan y congelan `deposit_amount` (% del total) y
  `payment_due_at` (llegada − ventana). Editar fechas/tarifa recalcula ambos.
- **Estado de pago** de la reserva: `unpaid → deposit_paid → paid`, derivado
  de la suma de pagos registrados (no se marca a mano).
- **Registro de pagos** (§7.5): abonos parciales con método y referencia; el
  panel muestra total / pagado / pendiente / fecha límite, y marca **"pago
  vencido"** en rojo si pasó `payment_due_at` sin liquidar.
- Acción automática al vencer (cancelar / liberar habitación): **P2,
  configurable** — por ahora solo se señaliza y la IA recordará (§10.6).

#### 2.6.4 Resto (P1)
| Qué | Detalle |
|---|---|
| Precio por día de semana | json `{vie: 800, sab: 900}` — común en moteles/hoteles de plaza |
| Temporadas | tabla `rate_seasons`: rango de fechas + multiplicador o precio fijo (Semana Santa, diciembre) |
| Persona extra | `extra_person_price` a partir de N personas |
| Hora extra (motel) | `extra_hour_price` cuando el rato se extiende |
| `min_stay_hours` / `max_stay_hours` | **límites del bloque** ("máximo 12 h por rato") — el motor los valida al reservar |

---

## 3. Tipos de habitación y Zonas — **P0 (tipos), P1 (zonas)**

### `RoomType` (ampliar)
| Campo | Tipo | Notas |
|---|---|---|
| `description` | text | comercial, la usará el widget web (fase 6) y los bots (§10) |
| `max_occupancy_adults` / `max_occupancy_children` | int | en vez de solo `capacity` |
| `check_in_time` / `check_out_time` | time nullable | hoteles: 15:00 / 12:00 |
| **Fotos** | medialibrary `photos` | |
| `sort_order` / `active` | int / bool | orden de venta/presentación |

- Las **tarifas se gestionan DESDE el tipo**: en su detalle, tabla de rate plans con CRUD (hoy no hay UI de tarifas; aquí es donde va).
- Validación motor: reserva por bloque no puede exceder `max_stay_hours`.

### `Zone` (ampliar) — P1
- `kind` enum: piso | edificio | área · `color` para el plano.
- En el plano se dibujan como rectángulos de fondo etiquetados.

---

## 4. Reservas — **P0**

### Modelo `Reservation` (ampliar)
| Campo | Tipo | Notas |
|---|---|---|
| `code` | string único | **folio**: `RES-2026-0001` — lo que se le da al huésped |
| `vehicle_plate` / `vehicle_desc` | string nullable | **clave en moteles** |
| `eta` | time nullable | hora estimada de llegada |
| `adults` / `children` | int | en vez de `num_people` plano |
| `internal_notes` vs `guest_notes` | text | staff vs peticiones del huésped |
| `cancellation_reason` | string nullable | |
| `payment_status` | enum: unpaid, deposit_paid, paid | registro manual (cobro real = fase 7) |
| `deposit_method` | enum: cash, card, transfer | |

### Comportamiento
- **Timeline** (activitylog ya lo registra; falta mostrarlo): creada → confirmada → check-in → check-out, con quién y cuándo.
- **Detalle de reserva** (modal lateral o página): datos completos, huésped con link al CRM, timeline, consumos si está en casa, acciones.
- **Editar** fechas/habitación re-validando disponibilidad (`ignoreReservationId` ya existe).
- **No-show** con botón propio (backend listo, falta UI).
- **Calendario mensual** (habitación × día, estilo channel manager) — P1.
- Tabla rediseñada: folio visible, chips de canal (web/whatsapp/mostrador), columnas consistentes.

---

## 5. Plano — **P0/P1 (ampliado v2)**

### 5.1 Slideover al click (P0)
Al hacer click en una habitación se abre un **Slideover** (componente del theme) cuyo contenido depende del estado:

- **Siempre**: número+nombre, tipo, zona, mini-galería, estado con color, amenities, incidencias abiertas.
- **Ocupada**: huésped (link al CRM), check-in, **salida prevista con cuenta regresiva** ("quedan 1 h 20 min"), monto de hospedaje, **consumos cargados** (órdenes POS + total acumulado), botones: *Cargar consumo* (POS preseleccionado), *Extender estancia* (cobra hora extra según tarifa), *Check-out*.
- **Reservada**: folio, huésped, ETA, botones *Check-in* y *Ver reserva*.
- **Disponible**: tarifas del tipo con precio, botones *Walk-in* y *Reservar* (pre-llenan el modal).
- **Sucia/Limpieza**: hace cuánto está así, quién limpia (P2), checklist (P2), botón de transición.
- **Mantenimiento**: incidencias abiertas con prioridad, notas.
- **Historial del día**: mini-timeline de estados (room_status_logs).

### 5.2 Indicadores sobre el nodo (P0/P1)
- **Countdown del rato** en habitaciones ocupadas por bloque (mm restantes); al vencer: borde parpadeante + badge "excedida" (los datos ya existen).
- Badge de **consumos** ($ acumulado sin liquidar).
- Icono de **llegada hoy** (reserva confirmada con ETA).
- Icono de **incidencia** de mantenimiento abierta.
- Tooltip hover con resumen (huésped, salida, total).

### 5.3 Editor del plano (P1)
- **Dos modos**: *Operación* (default, nodos NO arrastrables, solo click/acciones) y *Edición* (candado arriba; arrastrar, redimensionar con handles, alinear a grid).
- **Elementos decorativos** (nodos no-habitación): recepción, alberca, pasillo, escaleras, estacionamiento, jardín — solo visuales, dan contexto espacial.
- **Zonas como fondos**: rectángulo con color/etiqueta por zona; selector o tabs de zona/piso (multi-piso).
- Snap a grid + deshacer (P2).

### 5.4 Vista monitor / recepción (P1)
- Ruta `/plano/monitor`: pantalla completa, solo lectura, sin menú — para una TV en recepción; realtime ya funciona.
- **Barra de estadísticas vivas**: ocupación actual %, disponibles ahora, llegadas y salidas de hoy, ratos por vencer.
- **Alerta sonora opcional** cuando un rato vence o llega un mensaje del bot pendiente de aprobación (§10).
- Modo oscuro automático para el monitor.

### 5.5 Acciones rápidas (P1)
- Menú contextual (click derecho o long-press) en el nodo: Walk-in / Reservar / Cambiar estado / Reportar incidencia / Ver ficha.
- Filtros en la barra: por estado, tipo, zona; búsqueda por número; "solo disponibles".

---

## 6. Inventario — **P1 (ampliado v2)**

### 6.1 Catálogo
| Qué | Detalle |
|---|---|
| **Categorías** | tabla `categories` (nombre, tipo: producto/insumo, color): bebidas, cocina, amenities, limpieza — filtros en UI, agrupación en POS y reportes por categoría |
| `barcode` | escaneo en POS y recepción de compras (P2, lector USB = teclado) |
| Fotos de producto | medialibrary (P2, para el POS visual) |
| Unidad compra vs venta | `purchase_unit` + `units_per_purchase`: "caja de 24" → la compra de 2 cajas suma 48 piezas y prorratea el costo unitario |

### 6.2 Kardex y valuación — **P1, prioridad alta**
- **Kardex por producto/insumo**: tabla de movimientos (fecha, tipo, cantidad ±, costo unitario, **saldo corrido**, referencia, usuario) — `stock_movements` ya lo tiene todo; es solo UI.
- **Método de valuación configurable por tenant**: último costo (actual) o **costo promedio ponderado** (cada compra recalcula: `(stock×costo + qty×costo_compra) / (stock+qty)`).
- **Reporte de valor de inventario**: existencias × costo, agrupado por categoría; corte a una fecha (P2 con snapshot).

### 6.3 Compras y proveedores — **P1/P2**
- `suppliers`: nombre, contacto, teléfono, días de entrega, notas; productos que surte con **precio por proveedor** (comparador).
- **Orden de compra**: draft → enviada → recibida (parcial/total). La recepción genera los movimientos `purchase` y actualiza costos. Historial de compras por proveedor.
- **Sugerencia de compra**: lo que está bajo reorden, agrupado por proveedor, con cantidad sugerida para llegar al stock objetivo (`max_stock`).
- Cuentas por pagar básicas (P2): saldo por proveedor, fecha de pago.

### 6.4 Almacenes / ubicaciones — **P2 (pero diseñar desde ya)**
- `locations`: bodega, bar, cocina, **minibar por habitación** (una location ligada a `room_id`).
- Stock por ubicación (`location_id` en el acumulado); **transferencias** entre ubicaciones como movimiento auditado tipo `transfer`.
- El POS descuenta de la ubicación del punto de venta.

### 6.5 Minibar por habitación — **P2, diferenciador**
- **Plantilla de carga** por tipo de habitación (2 cocas, 2 aguas, 1 papas).
- Al **check-out**, recepción/housekeeping marca lo consumido → genera orden POS cargada a la estancia automáticamente.
- **Lista de reposición**: qué surtir a cada habitación tras la salida.

### 6.6 Mermas y conteo — **P1**
- Merma con **motivo obligatorio**: caducidad, rotura, robo, cortesía — reporte de mermas por periodo/motivo/costo.
- **Conteo físico**: sesión de conteo (congelar → capturar conteos → diferencias → manager aprueba → ajustes en lote con ref al conteo).

### 6.7 Recetas avanzadas — **P2**
- **Sub-recetas**: la salsa lleva ingredientes y se usa en varios platillos.
- **Rendimiento/batch**: 1 preparación de salsa (1 kg) = 20 porciones; el consumo descuenta porciones.

### 6.8 Alertas — **P1**
- Notificación en el panel (campana) + email diario opcional con lo que cruzó el punto de reorden.

---

## 7. POS (nueva sección) — **P1**

El POS actual vende y carga a habitación. Para operar en serio le falta:

| Qué | Detalle |
|---|---|
| **Turnos de caja** | abrir turno (fondo inicial) → ventas del turno → **corte de caja** (arqueo: efectivo esperado vs contado, diferencia) → cerrar. Todo reporte de ventas se puede filtrar por turno |
| **Métodos de pago** | efectivo (con cambio calculado), tarjeta, transferencia, **cargo a habitación** (ya existe); pagos mixtos (P2) |
| **Descuentos** | % o monto por línea/total, con permiso (`orders.discount`, manager) y motivo |
| **Cancelar venta (void)** | revierte movimientos de stock (movimiento espejo), requiere manager + motivo; queda auditada |
| **Propinas** | registro para corte (no es ingreso del hotel) |
| **Ticket** | imprimible (80mm) y por WhatsApp al huésped (se conecta con §10) |
| Cobro de estancia | al check-out: pantalla de cobro unificada — hospedaje + consumos + hora extra, métodos de pago, ticket (esto une reservas + POS y prepara fase 7) |
| Comanda a cocina (P2) | orden con destino "cocina" imprime/notifica a la pantalla de cocina |

### 7.5 Área de pagos (reservas) — **P0 registro · P1 configuración**

- **Registro de pagos por reserva** (P0): abonos con `amount`, `method`
  (efectivo · tarjeta · transferencia), `reference` (folio bancario/voucher),
  notas, quién lo recibió y cuándo. La suma mueve el estado de pago
  (`unpaid → deposit_paid → paid`). No se puede abonar más que el pendiente.
- **Cuentas bancarias del hotel** (P1): sección "Pagos" en configuración del
  tenant para registrar **N referencias bancarias** — banco, titular,
  CLABE/número de cuenta/tarjeta, tipo (transferencia/depósito), activa —
  para dárselas al huésped que paga por transferencia. La IA (§10) las envía
  al huésped cuando pregunta cómo pagar; el widget web (fase 6) las muestra
  al confirmar.
- Métodos habilitados por propiedad (efectivo/tarjeta/transferencia) — P1.
- Pasarela de cobro real (MercadoPago/Conekta) sigue siendo **fase 7**; este
  módulo registra pagos hechos por fuera y deja el modelo listo para eso.

---

## 8. Admin de plataforma (tenants) — **P1**

- Columna de **uso vs límites** ("12/30 hab · 3/5 usuarios") — consulta lazy/cacheada a cada DB tenant.
- Detalle del tenant: dominios, owner, actividad, botón **impersonate** (stancl lo trae).
- Página "Planes": editar límites desde UI (P2; config/plans.php es suficiente hoy).

---

## 9. Transversal — **P0 parcial**

- **Tablas**: búsqueda, filtros, paginación y orden en todas las listas (huéspedes ya lo tiene; replicar).
- **Toasts** de éxito/error consistentes (Notification del theme).
- **Formato de dinero** centralizado ($1,400.00 MXN) con cknow/laravel-money.
- **Zona horaria de la propiedad** en todas las fechas mostradas.
- **Notificaciones internas** (campana en el topbar): stock bajo, rato vencido, mensaje del bot esperando aprobación, incidencia alta — via Reverb (infra ya lista).
- Validación en vivo en formularios; estados vacíos con CTA.

---

## 10. Agentes IA multicanal — **la fase 4, a detalle (v2)**

> Lo que debe poder hacer el bot, en palabras del negocio: responder precio de
> habitaciones, duración permitida, cuántas personas, qué incluye, consultar
> disponibilidad por fechas, **reservar**, dar indicaciones de cómo llegar,
> horarios, políticas… y pasar con un humano cuando haga falta.

### 10.1 Canales soportados

| Canal | Vía | Notas |
|---|---|---|
| **WhatsApp** | Meta **Cloud API** (recomendado) o Evolution API | Cloud API: oficial, sin riesgo de ban, webhooks, mensajes interactivos (botones, listas), **ventana de 24 h** (fuera de ella solo plantillas aprobadas/HSM). Evolution: rápido para arrancar, riesgo ToS — útil solo para demo/dev |
| **Facebook Messenger** | Meta Graph API (Messenger Platform) | misma app de Meta, webhooks unificados |
| **Instagram DM** | Instagram Messaging API | requiere cuenta business + app review de Meta |
| **TikTok** | ⚠️ realidad: TikTok **no tiene API pública de DM** general (Business Messaging es limitado/por región/partners). Estrategia honesta: perfil TikTok con **link a WhatsApp (wa.me)** para captar; si su API de mensajes se abre en la región, se agrega como un adapter más | el diseño multicanal lo permite sin retrabajo |
| **Webchat propio** | widget embebible (sitio WP del hotel, fase 6) | mismo motor, sin dependencias de Meta |

**Arquitectura de canales**: cada canal es un **ChannelAdapter** (recibe webhook → normaliza a `IncomingMessage`; envía `OutgoingMessage` → formato del canal). El agente y el inbox trabajan solo con mensajes normalizados: agregar un canal nuevo = escribir un adapter.

### 10.2 Modelos
| Tabla | Campos clave |
|---|---|
| `channels` | property_id, type (whatsapp/messenger/instagram/webchat), **credentials encriptadas** (tokens, phone_number_id, page_id…), status (connected/error/disabled), config json (horario del bot, idioma default) |
| `conversations` | channel_id, **guest_id** (¡CRM! identificado por teléfono/PSID), external_id, status (**bot / human / closed**), assigned_to (user), last_message_at, unread_count |
| `messages` | conversation_id, direction (in/out), sender (guest/bot/user), type (text, image, audio, location, buttons, template), body/payload json, delivery_status (sent/delivered/read/failed), external_id |
| `agent_settings` | por propiedad: system prompt/personalidad, tono, idiomas, **nivel de autonomía**, horario del bot, mensaje fuera de horario, **proveedor LLM activo + modelo** (ver 10.2.1) |
| `agent_faqs` | pregunta, respuesta, embedding/keywords (RAG ligero) |
| `agent_actions` | acciones propuestas pendientes de aprobación: tipo (create_hold, cancel…), payload, status (pending/approved/rejected/executed), approved_by |
| `llm_providers` | catálogo de proveedores habilitados: provider, model, api_key **encriptada**, base_url (para APIs compatibles-OpenAI), enabled, priority (orden de fallback), límites |

### 10.2.1 Proveedor LLM configurable (sin modelos locales)

**Decisión: todo por API de terceros — NO se usa Ollama ni modelos locales.**
El sistema se conecta al proveedor que se seleccione y habilite:

| Proveedor | Vía | Notas |
|---|---|---|
| **OpenAI (ChatGPT)** | API oficial | soporte nativo en prism-php |
| **Anthropic (Claude)** | API oficial | soporte nativo en prism-php |
| **DeepSeek** | API oficial | soporte nativo en prism-php |
| **Kimi (Moonshot)** | API **compatible-OpenAI** | se conecta con el driver OpenAI de prism apuntando `base_url` a Moonshot |
| **MiniMax** | API **compatible-OpenAI** | ídem, `base_url` de MiniMax |

Comportamiento:
- **Selector de proveedor + modelo**: se configura a nivel **plataforma** (default del SaaS, con las API keys del dueño) y opcionalmente **override por tenant** (el hotel trae su propia key). Keys siempre encriptadas.
- **Cadena de fallback por prioridad**: si el proveedor activo falla (timeout, rate limit, error 5xx), se reintenta con el siguiente habilitado; el incidente se registra.
- Requisito duro para entrar a la lista: el modelo debe soportar **tool-calling confiable** (todas las tools de 10.3 dependen de eso).
- **Botón "probar conexión"** al configurar (mini prompt de prueba + verificación de tool-calling).
- **Tracking de consumo**: tokens y costo estimado por conversación/tenant/proveedor → habilita límites por plan (ej. plan básico: N conversaciones IA/mes) y decidir con datos qué proveedor conviene por costo/calidad.
- Cambiar de proveedor **no toca nada más**: prompt, tools, inbox y aprobaciones son agnósticos al proveedor (prism-php abstrae la llamada).

### 10.3 Herramientas del agente (tool-calling con prism-php)

Todas reutilizan lo YA construido (AvailabilityService, CreateReservation, CRM):

| Tool | Qué responde |
|---|---|
| `get_room_types()` | tipos con descripción, capacidad adultos/niños, camas, amenities, fotos (URLs) — "¿qué incluye la suite?" |
| `get_pricing(room_type, fechas/horas)` | tarifas noche y bloque con duración permitida (min/max horas) y **precio total calculado** — "¿cuánto por 2 noches?" / "¿cuánto el rato de 3 horas?" |
| `check_availability(room_type, rango)` | disponibilidad real (mismo motor anti-doble-reserva) — "¿tienen libre el sábado?" |
| `create_reservation_hold(...)` | crea **hold de 30 min sin cobrar**, devuelve folio; el teléfono ya lo da el canal → liga/crea el huésped en el CRM |
| `get_reservation(folio | teléfono)` | estado de su reserva — "¿a qué hora es mi check-in?" |
| `cancel_reservation(folio)` | con confirmación explícita del huésped |
| `get_property_info()` | **dirección + indicaciones de llegada** (texto + pin GPS + link Google Maps — WhatsApp permite mandar ubicación nativa), horarios, estacionamiento, políticas (mascotas, niños, visitas), métodos de pago aceptados |
| `get_faq(pregunta)` | RAG sobre `agent_faqs` del tenant — todo lo demás |
| `handoff_to_human(motivo)` | pasa la conversación a la bandeja humana y notifica al panel |

### 10.4 Comportamiento del agente
- **Identificación CRM automática**: el teléfono de WhatsApp busca en `guests` → saluda por nombre a recurrentes ("¡Hola de nuevo, María!"), y si está en **lista negra** no ofrece reservar y notifica al staff.
- **Niveles de autonomía** (configurable por tenant):
  1. *Supervisado*: TODA respuesta requiere aprobación humana (arranque).
  2. *Semi*: información libre; **reservas/cancelaciones requieren aprobación** en el panel (default recomendado).
  3. *Autónomo*: hace holds solo; el staff recibe resumen y el hold expira si nadie confirma.
- **Horario del bot**: fuera de horario responde el mensaje configurado y deja la conversación pendiente.
- **Idiomas**: detecta es/en y responde en el idioma del huésped.
- **Ventana de 24 h de Meta**: dentro, respuestas libres; fuera, solo plantillas aprobadas — el sistema lo controla y avisa cuando una conversación va a "cerrarse".
- **Seguridad**: jamás revela datos de otros huéspedes; no inventa precios (solo tools); límite de mensajes/tokens por contacto (anti-abuso); **log completo de tool-calls** para auditoría; **minimización de PII** hacia las APIs de terceros (al LLM solo va lo necesario para conversar — nombre de pila; nunca INE, documentos ni datos completos del CRM).

### 10.5 Bandeja de conversaciones (panel) — el inbox
- Página "Conversaciones": lista estilo WhatsApp Web (canal, huésped con link al CRM, último mensaje, estado bot/humano, no leídos), filtros por canal/estado.
- Vista de conversación: historial completo (con lo que respondió el bot marcado), **takeover** ("intervenir": el bot se calla y escribe el humano; "devolver al bot"), plantillas de respuesta rápida.
- **Cola de aprobaciones**: tarjetas "el bot propone: hold hab 104, 2 noches, $1,300 para +52614…" → Aprobar / Rechazar / Editar. Notificación en campana + monitor del plano.
- Realtime por Reverb (infra lista): mensajes nuevos aparecen sin recargar.

### 10.6 Mensajes salientes proactivos — **P2 (requiere plantillas WA aprobadas)**
- Confirmación de reserva con folio al crearla (cualquier canal de origen).
- **Recordatorio de pago** (pedido explícito): si la tarifa tiene cobro
  anticipado (§2.6.3) y la reserva **no está liquidada**, recordatorio
  configurable antes de `payment_due_at` (default: **1 semana antes**) con el
  monto pendiente y las referencias bancarias del hotel (§7.5); segundo aviso
  al vencer. Solo si sigue `unpaid`/`deposit_paid` — nunca molesta a quien ya
  pagó.
- **Recordatorio 24 h antes** con indicaciones de llegada y pin GPS.
- Aviso de rato por vencer (motel): "tu tiempo termina en 30 min, ¿extendemos?" → upsell de hora extra.
- Post-checkout: agradecimiento + pedir reseña de Google.
- Ticket de consumo por WhatsApp (conecta con POS §7).

### 10.7 Métricas del módulo (fase 5)
- Conversaciones por canal/día, tiempo de primera respuesta.
- **Tasa de conversión chat → hold → reserva confirmada → estancia**.
- Temas frecuentes (clasificación simple de intents), % resuelto por bot vs humano, CSAT (pregunta al cerrar).

### 10.8 Configuración en el panel (por tenant)
Sección "Canales y Agente":
- **Conectar canal** con wizard (tokens de Meta, verificación del webhook, número).
- **Proveedor de IA**: selector de proveedor/modelo habilitado (10.2.1) — el platform-admin define cuáles están disponibles y sus keys; el tenant elige entre los habilitados (u override con su propia key si su plan lo permite).
- Personalidad/prompt del bot, FAQs editables, indicaciones de llegada (texto + pin en mapa), horario, nivel de autonomía, idiomas, activar/desactivar por canal.
- Probar el bot en un **sandbox de chat** dentro del panel antes de conectarlo.

### 10.9 Plan de implementación del módulo
| Sub-fase | Entregable |
|---|---|
| **4a** | Webhooks + adapters (WA Cloud API primero) + modelos + **inbox humano SIN IA** (mensajería unificada ya es vendible) |
| **4b** | Agente con tools de **solo información** (tipos, precios, disponibilidad, indicaciones, FAQs) + sandbox |
| **4c** | `create_reservation_hold` + cola de aprobaciones (autonomía 1-2) |
| **4d** | Autonomía 3, Messenger + Instagram, métricas |
| **4e** | Proactivos con plantillas (P2) + webchat propio |

---

## 11. Orden de ejecución propuesto (actualizado v2)

| Iteración | Contenido | Estado |
|---|---|---|
| **A (P0)** | CRM Huéspedes completo + integración en reserva/walk-in/check-in | ✅ HECHA |
| **B (P0)** | Habitaciones y Tipos profundos (campos, fotos, camas, tarifas desde el tipo, min/max horas, price_modifier) | ✅ HECHA salvo: fotos (medialibrary), min/max horas por bloque |
| **C (P0)** | Reservas (folio, vehículo, ETA, timeline, editar, no-show UI) + **Plano: slideover completo** (countdown, consumos, acciones) | ✅ HECHA + extra: auto-checkout (§2.3.1), pestaña Historial, confirmación no-show/cancelar con motivo, toasts globales, zonas con tipo/color |
| **D (P0)** | Transversal: toasts, búsqueda/paginación en todas las tablas, formato dinero, notificaciones campana | pendiente |
| **E (P1)** | Incidencias + bloqueos programados · Kardex + categorías + mermas con motivo · POS turnos de caja + métodos de pago + void · calendario de ocupación · editor del plano + vista monitor | pendiente |
| **F (P1)** | **IA 4a+4b**: webhooks WhatsApp Cloud API + inbox + agente informativo | pendiente |
| **G (P1/P2)** | **IA 4c+4d**: holds por bot + aprobaciones + Messenger/Instagram | pendiente |
| **H (P2)** | Minibar por habitación · compras/proveedores · sub-recetas · QR housekeeping · proactivos WA · webchat · almacenes | pendiente |

---

*Reglas transversales: datos sensibles encriptados y en disco privado; todo
campo nuevo entra a los serializers de la API para que bots (§10) y widget
web (fase 6) lo aprovechen; toda acción de negocio pasa por las Actions
existentes (una sola fuente de verdad para panel, API y bots).*
