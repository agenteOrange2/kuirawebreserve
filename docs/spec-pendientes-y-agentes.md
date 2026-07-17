# Spec — Huecos pendientes y preparación para agentes IA

> Revisión general del sistema (jul 2026) previa a arrancar la fase de agentes.
> Qué ya está sólido, qué falta por módulo con prioridad, y qué es **prerequisito**
> para que los agentes funcionen bien. Complementa `estructura/spec-reservas-multitenant.md`
> (§9 capa de agentes) y `estructura/spec-modulos-profundidad.md`.

**Prioridades:** `P0` = bloquea o degrada la fase de agentes · `P1` = operación diaria, hacer pronto · `P2` = mejora, puede esperar.

---

## 1. Qué ya está sólido (base construida)

| Área | Estado |
|---|---|
| Multitenancy (stancl v3, DB por tenant, sesiones tenant-aware) | ✅ |
| Catálogo: zonas, tipos, tarifas (noche/periodo, antelación, anticipo) | ✅ |
| Habitaciones: CRUD + ficha (show) + historial de uso + plano con semáforo | ✅ |
| Motor de disponibilidad con locks `FOR UPDATE` (anti doble-venta) | ✅ |
| Reservas: ciclo completo, holds 30 min auto-expiración, walk-in, auto-checkout | ✅ |
| Salida y total auto-calculados por tarifa; disponibilidad auto en el modal | ✅ |
| Pagos de reservas (métodos, anticipo, vencidos) | ✅ |
| CRM huéspedes: perfil, INE + fotos, vehículo + fotos, lista negra, confiabilidad | ✅ |
| Inventario: productos/insumos, recetas (BOM), movimientos, categorías, valuación | ✅ |
| POS: venta con método de pago, cargo a habitación, stock transaccional | ✅ |
| Turnos: tipos dinámicos, rol semanal (grid), asistencia, fondo de caja | ✅ |
| Cortes de venta por encargado: manual con arqueo + automático al cerrar turno | ✅ |
| Usuarios: CRUD con roles, protecciones (último owner, auditoría, límite del plan) | ✅ |
| Reportes de reservas por periodo + PDF (dompdf) | ✅ |
| Roles/permisos por tenant (spatie), incluido rol `agent` reservado | ✅ |

---

## 2. Huecos en Reservas / operación de dinero

### 2.1 `P0` — Folio de estancia y check-out con cuenta final
**El hueco más importante del sistema hoy.** Los consumos POS cargados a habitación
(`orders.payment_method = 'room'`) **no se cobran en ningún lado**: el check-out
cierra la estancia sin liquidar hospedaje pendiente ni consumos.

- Al hacer check-out: mostrar **cuenta final** = saldo de hospedaje + consumos `room`
  de la estancia → registrar pago(s) (efectivo/tarjeta/transferencia) ahí mismo.
- Ese cobro debe entrar al **corte del encargado** (ya hay infraestructura: `Payment.received_by`).
- Bloquear (o pedir confirmación explícita) check-out con saldo > 0.
- Modelo sugerido: no hace falta tabla nueva; los `orders` con `stay_id` + `Reservation.pendingBalance()` ya lo permiten. Falta la action `SettleStay` y el modal de cuenta.

### 2.2 `P1` — Extender estancia y cambio de habitación (room move)
No existe forma de: alargar la salida de una estancia activa (validando disponibilidad)
ni mover al huésped de habitación a mitad de estancia (la original queda sucia, la
nueva ocupada, el folio se conserva). Ambos son operaciones diarias en hotel/motel.

### 2.3 `P1` — Calendario de ocupación (timeline)
Solo hay listas y plano (estado actual). Falta la vista **timeline por habitación**
(filas = habitaciones, columnas = días, barras = reservas/estancias) para ver huecos
de venta y arrastrar/ajustar. Es también la mejor UI para detectar sobre-ocupación.

### 2.4 `P1` — Notificaciones al huésped
Hoy no se envía **nada** (no hay Mail configurado). Mínimo:
- Confirmación de reserva (email; WhatsApp cuando exista el canal).
- Recordatorio previo a la llegada y aviso de hold por vencer.
- Es prerequisito suave de agentes: el bot promete "te llega tu confirmación".

### 2.5 `P2` — Tarifas por temporada / día de semana
`RatePlan` es precio fijo. Falta: precio por rango de fechas (temporada alta) y/o
por día de semana (viernes/sábado más caro). Diseño sugerido: tabla `rate_plan_seasons`
con override de precio y prioridad; `priceFor()` ya centraliza el cálculo.

### 2.6 `P2` — Política de cancelación con dinero
Cancelar hoy no toca pagos. Falta: definición por tarifa (reembolsable hasta X,
penalidad %) y registro de reembolso/nota de crédito en el corte.

### 2.7 `P2` — No-show sugerido automáticamente
El scheduler podría marcar "sugerencia de no-show" pasadas N horas de la llegada
sin check-in (decisión final humana, alineado con la confirmación en modal).

---

## 3. Huecos generales del sistema

### 3.1 `P0` — Decisión de multipropiedad
**12 controladores** asumen `Property::firstOrFail()` (una propiedad por tenant).
El spec original contempla "owner con su(s) propiedad(es)". Antes de agentes hay
que **decidir y blindar**: o (a) se declara single-property por tenant (documentarlo
y quitar la ambigüedad), o (b) se agrega selector de propiedad + scoping. La opción
(a) es la recomendada para no frenar la fase de agentes; (b) puede venir después
porque el dato `property_id` ya existe en todas las tablas.

### 3.2 `P1` — Configuración del hotel (settings del tenant)
No existe página de ajustes: horario check-in/out por defecto, moneda, zona horaria,
datos de contacto/fiscales, políticas escritas. **Doble uso**: lo consume el panel
y es la fuente del `get_policies()` de los agentes (§4.3).

### 3.3 `P1` — Tiempo real (Reverb ya instalado, sin conectar)
`RoomStatusChanged` se dispara y Reverb/Echo están en las dependencias, pero el
plano/dashboard no se suscriben. Conectar: `property.{id}.rooms` (semáforo en vivo),
`property.{id}.reservations`, y el futuro `property.{id}.agent` (aprobaciones).

### 3.4 `P1` — Tablero de housekeeping
El rol limpieza solo tiene el plano genérico. Falta vista simple móvil: "mis
habitaciones sucias" → marcar en limpieza → disponible, con tiempos (el log de
estados ya lo registra; hay KPI de turnaround gratis).

### 3.5 `P2` — Bitácora visible (auditoría)
`activitylog` ya registra reservas/habitaciones/estancias, pero no hay UI. Una
página "Actividad" filtrable por usuario/modelo/fecha cierra el ciclo de auditoría
(importante con cortes y agentes operando).

### 3.6 `P2` — Exports CSV/Excel y reportes programados
Reportes solo en pantalla + PDF de reservas. Faltan: export CSV (reservas, cortes,
inventario), PDF del corte individual (para firma), y envío programado semanal.

### 3.7 `P2` — Onboarding del tenant
El alta de un hotel nuevo requiere tocar varias pantallas en orden. Un wizard
(propiedad → zonas/tipos → tarifas → habitaciones → usuarios) reduce fricción de venta.

---

## 4. Fase agentes IA — prerequisitos y arquitectura

> Objetivo (spec original §9): agentes que atienden WhatsApp/webchat, consultan
> disponibilidad, cotizan y **crean holds** (nunca cobran), con humano en el loop.

### 4.1 `P0` — Agent API autenticada por token
Hoy toda la API es **sesión web + CSRF**: un agente no puede consumirla.
- **Sanctum ya está instalado** → emitir tokens por tenant para el usuario `agent`
  (rol existente), con abilities limitadas.
- Endpoints-herramienta (JSON estable, pensado para tool-calling):
  - `GET /agent/availability` → ya existe la lógica (`AvailabilityController` la comparte); exponer con token.
  - `GET /agent/rate-plans` → tarifas activas con duración/anticipo/antelación.
  - `GET /agent/policies` → settings + políticas del hotel (§3.2).
  - `POST /agent/holds` → crea reserva `pending` con hold (usa `CreateReservation` con `confirmed=false`); **nunca** confirma ni cobra.
  - `GET /agent/reservations/{code}` → estado de una reserva (para "¿cómo va mi reserva?").
- **Guardrails**: rate-limit por token, montos/fechas máximos, y todo `created_by` = usuario agent (auditoría ya funciona).

### 4.2 `P0` — Idempotencia en escrituras del agente
Los LLM reintentan. `POST /agent/holds` debe aceptar `Idempotency-Key` (tabla
`idempotency_keys` o cache con TTL ≥ hold_minutes) para no crear holds duplicados
del mismo intento. Sin esto, un agente en producción genera basura de holds.

### 4.3 `P0` — Políticas del hotel como fuente del agente
El "system prompt" del hotel (spec §9: `AgentInstruction`): texto por propiedad con
tono, políticas (mascotas, horarios, estacionamiento), FAQs. Editable en settings.
Sin esto el agente inventa políticas — es el riesgo #1 de reputación.

### 4.4 `P1` — Bandeja unificada (inbox omnicanal)
Un solo lugar donde el staff ve **todas** las conversaciones de todos los canales
(WhatsApp, Messenger, Instagram DM, comentarios, webchat), con:

- **Identidad unificada**: si el teléfono/perfil coincide con un `Guest` del CRM,
  la conversación muestra su expediente (visitas, lista negra, vehículo, reserva activa).
- **Asignación y estados**: conversación abierta / pendiente / resuelta; asignar a
  un miembro del staff; etiquetas (cotización, queja, proveedor…); notas internas.
- **Handoff bot ↔ humano**: el agente escala con contexto completo (transcript +
  resumen); el humano puede devolver el control al bot.
- **Detección de colisión**: aviso "X está respondiendo esta conversación".
- **Respuestas rápidas** (canned responses) con variables ({{nombre}}, {{checkin}}).
- **Tiempo real**: Reverb `property.{id}.inbox` — mensajes nuevos sin recargar,
  contador en el menú del panel.

Modelos: `Channel` (tipo, credenciales cifradas, modo de operación, estado de
salud), `Conversation` (canal, guest_id?, reservation_id?, assigned_to, status,
last_message_at), `Message` (dirección in/out, tipo texto/imagen/audio/plantilla,
`channel_message_id` para dedupe, status sent/delivered/read/failed, `sent_by`
bot|humano|sistema), `CannedResponse`.

### 4.4.1 `✅ HECHO` — WhatsApp vía Evolution API (alternativa self-hosted)

Canal WhatsApp **sin depender de la aprobación de Meta**: el hotel (o la
plataforma) conecta instancias de su propio servidor Evolution API desde el
panel `/asistente` (URL + api key + nombre de instancia). Implementación:

- **Adapter independiente de Meta** (no toca `MetaApi` ni su webhook):
  `EvolutionApi` (envío, estado de conexión, autoconfiguración del webhook),
  `EvolutionWebhookController` (inbound con dedupe por `external_id`, ignora
  ecos/grupos) y `OutboundMessenger` — despachador por `channel->type` que
  usan bandeja y follow-ups (punto único para futuros canales).
- **Enrutamiento**: tabla central `evolution_channel_links` (credenciales
  cifradas); webhook por instancia `/webhooks/evolution/{token}`.
- **Multi-instancia**: cada número es un `Channel` propio (se quitó el unique
  property+type) con su modo auto/copilot/off individual en la bandeja.
- **Límite por plan** `max_channels` (Meta + Evolution; webchat no cuenta):
  Básico 1, Pro 3.
- **Instrucciones del bot editables por el hotel**: `settings.agent_instructions`
  en `/ajustes` se inyecta al system prompt (subordinado a las reglas duras:
  nunca confirma ni cobra, no inventa precios).
- Mismo cerebro, bandeja, historial (conversación ligada a huésped y reserva),
  cuotas y aprobaciones que el resto de canales.
- **Humanización anti-ban** (respuestas del bot y follow-ups; staff no):
  `delay` nativo de Evolution — el server muestra "escribiendo..." y entrega
  después de un retraso proporcional al largo del texto con jitter (2–7 s,
  `EvolutionApi::humanDelay`). Responder en 0 s es firma de bot para Meta.
- **Playbook anti-ban del número** (aprendido con ban real en el primer
  mensaje): SIM física del país, jamás número virtual/VoIP; calentar 1-2
  semanas de uso normal en teléfono real antes de vincular; proxy
  residencial/móvil configurado en Evolution (la sesión no debe salir con IP
  de datacenter); solo tráfico entrante al inicio y sin links en los primeros
  mensajes. Evolution = vía rápida/plan B; producción seria = Cloud API.
- Pendiente P2: medios entrantes (imagen/audio → transcripción), alerta de
  salud del canal (webhook sin eventos / instancia caída → campana), y mover
  el procesamiento inbound a colas si el volumen crece.

### 4.5 `P1` — Canales Meta: WhatsApp, Messenger e Instagram DM
Integración **oficial vía Meta Graph API** (una sola app de Meta, N hoteles
conectan su número/página con OAuth de incrustación — *Embedded Signup*):

**WhatsApp Business (Cloud API):**
- Recepción/envío: texto, imágenes (fotos de habitaciones), ubicación del hotel,
  documentos (confirmación PDF), audio.
- **Mensajes interactivos**: botones de respuesta rápida ("Ver disponibilidad",
  "Hablar con recepción") y listas (tipos de habitación con precio).
- **Plantillas HSM aprobadas** para iniciar conversación fuera de la ventana de
  24 h: confirmación de reserva, recordatorio de llegada, hold por vencer,
  agradecimiento post-estancia. Editor de plantillas en el panel + estado de
  aprobación de Meta visible.
- **Ventana de 24 h** gestionada por el sistema: dentro → conversación libre;
  fuera → solo plantillas (el panel lo indica y bloquea el envío libre).
- Opt-in/opt-out automático (STOP/BAJA) con registro de consentimiento.

**Facebook Messenger (Messenger Platform):**
- Webhooks de mensajes, postbacks y referencias (`m.me/hotel?ref=promo`).
- **Persistent menu** (Reservar · Precios · Ubicación · Hablar con humano) e
  **ice breakers** (preguntas sugeridas al abrir el chat).
- Ventana de 24 h + message tags permitidos (ej. `CONFIRMED_EVENT_UPDATE` para
  avisos de reserva).

**Instagram DM (Instagram Messaging API):**
- DMs, **respuestas a stories** que mencionan al hotel y *story mentions* —
  entran a la misma bandeja.
- Ice breakers y respuestas con imagen.

**Webchat propio** (primer canal, sin dependencia de Meta):
- Widget embebible en el sitio del hotel (script 1 línea), con branding del hotel,
  persistencia de sesión y traspaso a WhatsApp ("sigue la conversación en tu cel").

### 4.6 `P1` — Comentarios y publicaciones de Facebook/Instagram (community management)
El bot no solo contesta DMs: **atiende la fan page**. Vía webhooks `feed` (FB) y
`comments` (IG) sobre las publicaciones de la página:

- **Clasificación automática de cada comentario** (LLM): intención de compra
  (precio/disponibilidad/ubicación) · pregunta general · queja · elogio · spam.
- **Respuesta pública** configurable por tipo: a intención de compra responde
  público breve ("¡Te mandamos precios por privado! 💬") **+ private reply**.
- **Private replies (API oficial de Meta)**: responder un comentario **por
  mensaje privado** — abre la conversación en la bandeja, ligada al comentario
  de origen. (Regla Meta: 1 private reply por comentario, dentro de los 7 días.)
- **Comment-to-conversation**: el hilo del comentario queda vinculado a la
  `Conversation`; si termina en reserva, la atribución queda registrada
  (post X → comentario → DM → reserva).
- **Moderación automática**: ocultar (nunca borrar) spam y ofensas según lista
  de palabras del hotel + detección del LLM; todo reversible y auditado.
- **Alertas**: comentario clasificado como queja → notificación al manager y
  cola de aprobación (el bot no responde quejas solo).
- **Publicaciones**: registro de posts de la página (`SocialPost`) para agrupar
  sus comentarios y medir cuáles generan demanda.

Modelos: `SocialPost` (page_post_id, mensaje, permalink, stats), `SocialComment`
(comment_id, autor, texto, clasificación, estado público/oculto, private_reply_sent,
conversation_id?).

### 4.7 `P1` — Modos de operación y humano en el loop
Por **canal** y por **tipo de acción**, tres modos:

| Modo | Comportamiento |
|---|---|
| **Automático** | El bot responde/actúa solo (FAQ, disponibilidad, crear holds). |
| **Copiloto** | El bot redacta, un humano aprueba antes de enviar (quejas, confirmaciones, cortesías). |
| **Apagado** | El canal solo alimenta la bandeja; responde el staff. |

- Cola de aprobaciones en el panel con vista previa del mensaje propuesto +
  contexto; aprobar/editar/rechazar. Canal Reverb `property.{id}.agent`.
- **Horario del bot** configurable (fuera de horario: mensaje de espera + prioridad
  en bandeja).
- **Escalamiento automático**: sentimiento negativo, palabras clave (reembolso,
  demanda, accidente), o petición explícita de humano → handoff inmediato.
- Límite duro: el bot **nunca cobra ni confirma pagos**; solo crea holds y agenda.

### 4.8 `P0` (de la fase canales) — Cumplimiento y operación Meta
Lo que separa una integración de juguete de una profesional:

- **Verificación**: Business Verification del cliente + App Review de la app con
  permisos `whatsapp_business_messaging`, `pages_messaging`,
  `pages_manage_engagement`, `pages_read_engagement`,
  `instagram_manage_messages`, `instagram_manage_comments`.
- **Webhooks firmados**: validación `X-Hub-Signature-256` en cada evento; endpoint
  central `/webhooks/meta` que enruta al tenant por page_id/número (tabla de
  mapeo en la DB central).
- **Colas y resiliencia**: todo envío/recepción pasa por jobs con reintentos
  exponenciales e **idempotencia por `channel_message_id`** (Meta reintenta
  webhooks; sin dedupe se duplican mensajes).
- **Rate limits** de Graph API respetados por cola con throttling por página/número.
- **Salud del canal**: monitor de token expirado / permiso revocado / calidad del
  número WhatsApp (quality rating) con aviso en el panel.
  - ✅ Primera pieza hecha: `last_event_at` por canal (Meta y Evolution — latido
    del webhook visible en admin), botón **Diagnosticar** (token, número,
    calidad, callback registrado vs el nuestro, apps suscritas a la WABA) y
    botón **Reparar suscripción** (ciclo unsubscribe/subscribe de la app a la
    WABA — la causa #1 de "el Test llega pero los mensajes reales no": la app
    del token sin suscribir a la cuenta). Requiere capturar `waba_id` en el
    canal. Pendiente: alerta proactiva (campana) cuando un canal deja de
    recibir eventos o el token caduca.
- **Privacidad**: retención configurable de conversaciones, borrado a solicitud
  del usuario (requisito Meta), y las credenciales de página **cifradas** por tenant.

### 4.9 `P2` — Métricas de canales y agentes
- Embudo por canal: conversaciones → cotizaciones → holds → reservas confirmadas.
- Tiempo de primera respuesta (bot vs humano), tasa de resolución sin humano.
- Comentarios: volumen por post, ratio comentario→DM→reserva, quejas atendidas y SLA.
- Costo LLM por conversación (tokens) para cuidar el margen por tenant.

---

## 5. Roadmap propuesto

| Orden | Entregable | Por qué primero |
|---|---|---|
| 1 | ✅ **Folio/check-out con cuenta final** (2.1) — *hecho* | Era dinero mal cerrado; el agente venderá estancias que deben poder liquidarse bien. |
| 2 | ✅ **Multipropiedad blindada** (3.1) + **Settings del hotel + políticas** (3.2/4.3) — *hecho* | Desbloquea `get_policies()` y evita re-trabajo en la Agent API. |
| 3 | **Agent API** (4.1) + **idempotencia** (4.2) | El corazón de la fase agentes; la lógica de negocio ya existe, es exponerla con token y contratos estables. |
| 4 | **Bandeja unificada + webchat + modos de operación** (4.4, webchat de 4.5, 4.7) | Primer canal end-to-end sin depender de Meta; deja lista la infraestructura de conversaciones que reutilizan todos los canales. |
| 5 | **Notificaciones al huésped** (2.4) | Cierra la promesa del agente ("te llega confirmación"); base para plantillas WhatsApp. |
| 6 | **WhatsApp Cloud API + cumplimiento Meta** (4.5/4.8) | Canal de mayor volumen en MX; requiere Business Verification y App Review (empezar trámites desde el paso 4, tardan semanas). Mientras corren los trámites, WhatsApp ya opera vía Evolution API (4.4.1 ✅). |
| 7 | **Messenger + Instagram DM + comentarios FB/IG** (4.5/4.6) | Misma app Meta y misma bandeja: el costo marginal es bajo una vez hecho WhatsApp; los comentarios convierten la fan page en canal de venta. |
| 8 | Timeline de ocupación (2.3), extender/mover estancia (2.2), housekeeping (3.4), realtime (3.3) | Operación diaria; en paralelo según capacidad. |
| 9 | Métricas de canales (4.9), temporadas (2.5), cancelación con dinero (2.6), exports (3.6), bitácora (3.5), onboarding (3.7) | Madurez. |

> **Nota de trámites Meta:** la Business Verification y el App Review (4.8) son el
> camino crítico de los pasos 6-7 — son procesos de Meta que toman semanas y no
> dependen de nosotros. Iniciarlos en cuanto arranque el paso 4.

---

## 6. Notas técnicas rápidas

- **Reutilizar actions, no duplicar**: la Agent API debe llamar `CreateReservation`,
  `AvailabilityService`, `RatePlan::priceFor()` — las mismas piezas del panel. Un solo
  camino de negocio = un solo lugar que probar.
- El rol `agent` ya existe en el seeder con permisos mínimos (rooms.view,
  reservations.view/manage); revisar que **no** tenga `orders.manage` ni pagos.
- `AvailabilityController` ya anticipa esto en su docblock: *"Es la misma consulta que
  usará la API pública (fase 6) y los agentes IA (fase 4)"*.
- Todos los montos/fechas que consuma el LLM deben ir **formateados y en crudo**
  (ej. `{"total": 1300, "total_label": "$1,300.00"}`) para minimizar alucinación de cifras.
- Los holds creados por agentes ya expiran solos (scheduler `reservations:expire-holds`) —
  la limpieza automática de la fase agentes ya está resuelta de gratis.
- **Arquitectura Meta multitenant**: UNA sola app de Meta (nuestra, de plataforma) y
  cada hotel conecta su página/número vía **Embedded Signup / OAuth** — no crear una
  app por hotel (inviable de revisar/verificar N veces). Los webhooks llegan a la app
  central (dominio central, no al subdominio del tenant) → tabla de mapeo
  `page_id/phone_number_id → tenant` en la DB central → se inicializa el tenant y se
  despacha el job. Es el único punto del sistema donde el flujo entra por el dominio
  central hacia datos de tenant.
- **Los comentarios de FB usan permisos distintos a los DMs** (`pages_manage_engagement`
  vs `pages_messaging`): pedir todos en el mismo App Review para no pasar dos veces
  por el proceso.
