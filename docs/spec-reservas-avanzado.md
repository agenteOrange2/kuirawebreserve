# Spec — Reservas avanzado: pago siempre disponible, capacidad real y módulo de Experiencias

> Nace de probar el wizard en vivo (2026-07-11) contra motellacupula y
> encontrar tres huecos: (1) sin anticipo configurado, el wizard no ofrece
> NINGÚN método de pago, y con varias pasarelas conectadas nunca dejaba
> elegir cuál — **ya corregido, §1.4**; (2) buscar con más personas hacía
> desaparecer opciones que en realidad sí podían admitir esa gente con un
> cargo extra, y el propio editor de habitación estaba dividido de forma
> que hacía casi imposible configurarlo bien — **ya corregido, §2**; (3)
> falta un módulo para reservar recorridos/experiencias (cuatrimotos,
> excursiones), que son reservables por sí solos, no "extras pegados a una
> reserva de cuarto" — diseño aterrizado en §3, pendiente de construir.
> Revisión completa del flujo de alta de habitación → tarifa → wizard,
> pedida explícitamente después de que el primer fix de capacidad (§2.3)
> resultara insuficiente en la práctica (ver §2.4 para el porqué exacto).
> Complementa `spec-wizard-precios-y-pasos.md` (pago y transparencia de
> precio) y `spec-motor-reservas-web.md` §12.2 (Experiencias, hasta ahora
> solo anticipada, aquí se aterriza el diseño). Mapa de qué controla qué:
> §5.

**Prioridades:** `P0` = ya corregido · `P1` = siguiente entrega · `P2` = madurez/módulo grande.

---

## 1. Pago: opción de pago siempre visible + transferencia conectada a WhatsApp

### 1.1 Lo que ya existe y resuelve la mitad del problema

`settings.payment_mode` en `/ajustes/wizard` (spec-wizard-precios-y-pasos
§5.2, ya implementado): Automático (default, lo decide `deposit_percent`
de la tarifa) / **Siempre pedir pago en línea** / Nunca. Si el hotel
activa "Siempre", el wizard YA pide método de pago en cada reserva —
incluso en tarifas sin anticipo configurado, cobrando el total completo
vía la misma lógica de `IssuePaymentRequest::resolveConcept` que ya
existe (`deposit_amount <= 0` → cobra `CONCEPT_FULL`). **Esto es lo que
motellacupula necesita activar para dejar de ver "el hotel confirma
directo"** — no hace falta código nuevo para esa mitad del problema, solo
prender el interruptor.

### 1.2 Lo que falta: transferencia conectada de verdad a WhatsApp

Hoy, cuando el método resuelto es transferencia, la pantalla de
confirmación del wizard solo muestra las cuentas bancarias y el texto
genérico "envía tu comprobante al hotel" — sin decir CÓMO. El huésped
tiene que adivinar el canal.

Investigado a fondo: **toda la infraestructura de mensajería ya existe**
— `Channel`/`Conversation`/`Message`, bandeja unificada en `/inbox`
(`InboxController`), `OutboundMessenger::pushToConversation()`, adapters
Evolution y Meta. El bot INCLUSO ya sabe pedir el comprobante "por este
chat" cuando la conversación ya existe (`AgentBrain.php`,
`AgentToolsController::requestPayment` línea 267). Lo único que falta es
el puente entre el wizard público (que arranca sin conversación previa) y
esa infraestructura.

Dos formas de tender ese puente, evaluadas:

| Opción | Cómo funciona | Riesgo |
|---|---|---|
| **A. Link `wa.me` (recomendada)** | Botón "Enviar comprobante por WhatsApp" con `https://wa.me/{numero}?text={mensaje precargado con el código de reserva}` — el HUÉSPED inicia la conversación desde su propio WhatsApp | Ninguno: al ser el huésped quien escribe primero, no aplica la ventana de 24h de Meta ni requiere plantilla HSM aprobada. Funciona igual con canal Evolution o Meta, o incluso sin ningún canal conectado (con el teléfono de contacto del hotel). El mensaje entrante cae solo en `/inbox` — los webhooks ya existen y ya crean/encuentran la `Conversation` por teléfono |
| B. Mensaje proactivo del sistema | El servidor crea la `Conversation` y le manda un mensaje AL huésped vía `OutboundMessenger::pushToConversation()` apenas confirma transferencia | Con Meta Cloud API, mandar el PRIMER mensaje a alguien que nunca escribió exige una plantilla HSM aprobada (si no, Meta lo rechaza). Con Evolution no hay esa restricción formal, pero sí el riesgo de baneo por escribir en frío (ya documentado en el playbook anti-ban de esta misma app). Innecesariamente riesgosa para lo que se necesita |

**Decisión: opción A.** Diseño concreto:

- `GET /api/booking/payment-options` (ya existe) se extiende con
  `whatsapp: { available: bool, number: string|null }`, resolviendo el
  número desde el canal WhatsApp ACTIVO del hotel (Evolution o Meta,
  cualquiera que esté conectado) — con fallback a `settings.phone` si no
  hay canal conectado pero el hotel puso un teléfono de contacto.
- En la pantalla de transferencia del wizard (`Wizard.vue`, la que hoy
  solo muestra cuentas bancarias), agregar un botón prominente "Enviar
  comprobante por WhatsApp" con el link `wa.me` prellenado: *"Hola, tengo
  una reserva {código} en {hotel}, aquí está mi comprobante de
  transferencia."*
- Cero backend nuevo de mensajería — el mensaje entrante ya cae en
  `/inbox` por los webhooks existentes (`EvolutionWebhookController`,
  `MetaWebhookController`), el staff lo ve con el resto de la bandeja.

### 1.3 Mejora relacionada (P2, no bloquea 1.2): vincular la conversación a la reserva automáticamente

`Conversation` ya tiene la columna `reservation_id`, pero nada la llena
hoy. Cuando llega un mensaje nuevo por WhatsApp, buscar una reserva
reciente (`status=pending`, `guest_phone` coincide con el remitente) y
ligarla — así el staff ve "Reserva RES-2026-XXXX" directo en el inbox sin
tener que preguntar ni buscar. Vale la pena, pero es independiente del
botón de WhatsApp (que funciona bien sin esto, el staff solo verifica el
comprobante a mano contra el código que el huésped ya escribió en el
mensaje precargado).

### 1.4 ✅ YA CORREGIDO (2026-07-11): elegir pasarela específica cuando hay varias conectadas

**El bug real** (parte de "no está detectando los diferentes [métodos]"
reportado en vivo): un hotel Pro puede conectar y activar hasta 3
pasarelas simultáneamente (`config/plans.php` `max_gateways`), y la UI de
`/ajustes` (Hotel.vue) ya deja prender cada proveedor por separado. Pero
`BookingController::payment()` y `BookingExtrasController::paymentOptions()`
solo sabían responder "¿hay pasarela? sí/no" (un objeto `gateway` con un
solo `provider_label`) y, al cobrar, siempre tomaban la **primera pasarela
por `id`** (`->orderBy('id')->first()`) sin importar cuál. Si un hotel
conectaba Stripe Y PayPal, el huésped nunca podía elegir cuál usar — el
selector "¿Cómo prefieres pagar?" (construido en spec-wizard-precios-y-
pasos §5.2) solo distinguía "pasarela" (una, genérica) vs "transferencia",
nunca pasarela A vs pasarela B.

**El fix:**
- `paymentOptions()` ahora devuelve `gateways: [{provider, label}, …]` —
  TODAS las pasarelas realmente activas, no un booleano.
- `payment()` acepta `provider` (`stripe`/`mercadopago`/`paypal`) además
  del `method` (`gateway`/`transfer`) que ya existía. Si se pide una
  pasarela puntual, se usa ESA o se rechaza con 422 — nunca se sustituye
  en silencio por otra que el huésped no eligió (mismo principio que ya
  regía `room_id` en `holds()`).
- El selector del wizard ahora renderiza un botón por cada pasarela
  conectada ("Pagar con Stripe", "Pagar con PayPal"…) en vez de un botón
  genérico "Pagar en línea".

4 tests nuevos, verificado en vivo (motellacupula, solo lectura — su
`payment-options` ya devuelve `{"gateways":[{"provider":"paypal","label":"PayPal"}]}`
con la forma nueva).

---

## 2. Capacidad real de cada habitación (✅ YA CORREGIDO, 2026-07-11)

### 2.1 El bug

Buscar con 2 adultos mostraba el catálogo completo; buscar con 3 hacía
**desaparecer** todo tipo cuya `capacity` de catálogo fuera 2, aunque una
habitación específica de ese tipo tuviera configurado un recargo por
persona extra (`Room.included_occupancy` + `Room.extra_guest_fee`) que la
habilitaba para admitir esa gente. Causa: `BookingController::availability()`
filtraba los TIPOS con un `WHERE capacity >= guests` antes de siquiera
mirar las habitaciones individuales — el recargo nunca tenía oportunidad
de aplicarse porque el tipo entero ya estaba descartado del resultado.

### 2.2 Lo que ya existía y no se usaba

`Room.max_occupancy` (override propio de capacidad sobre la del tipo) y
`Room::effectiveMaxOccupancy()` **ya existían** en el modelo de datos —
se construyeron en una iteración anterior (spec-modulos-profundidad §2.1,
Iteración B) pero nunca se conectaron a la búsqueda del wizard público.
El campo ya está expuesto en el editor de habitación ("Ocupación
máxima"), solo en una sección distinta a "Personas incluidas"/"Cobro por
persona extra" (ver §2.4).

### 2.3 El fix

- La búsqueda ahora filtra por HABITACIÓN, no por tipo: cada tipo se
  ofrece si al menos un cuarto (disponible o no, para poder mostrar "sin
  disponibilidad" en vez de ocultar el tipo del catálogo entero) tiene
  `max_occupancy` (o la capacidad del tipo si no hay override) suficiente
  para el grupo buscado.
- `CreateReservation::handle()` ahora valida la capacidad real del cuarto
  asignado como único punto de verdad — protege los 3 canales que crean
  reservas (wizard, panel interno, Agent API) con una sola pieza de
  código en vez de una validación distinta (y potencialmente
  inconsistente) en cada uno.
- El payload de `availability()` ahora incluye `effective_capacity` (el
  techo real del cuarto ofrecido) además de `capacity` (la capacidad "de
  catálogo" del tipo, sin cambios) — el wizard muestra "Hasta 2 (hasta 3
  con cargo extra)" cuando aplica.
- El desglose de precio (`price_breakdown`, de la iteración anterior) ya
  mostraba la línea "Personas extra" automáticamente en cuanto un cuarto
  con recargo llegaba a cotizarse — no hizo falta tocar esa parte, solo
  dejar de excluirlo antes de tiempo.

7 tests nuevos (`BookingWizardTest.php`, `ReservationEngineTest.php`), 227
en total. Verificado en vivo (tenant `demo`, con limpieza después): con
el override activo en una habitación, "Sencilla" (capacidad de catálogo
2) apareció para 3 personas con el cargo de $400 desglosado
("Tarifa (12 horas): $1,300" + "Personas extra (1): $400"); sin el
override, sigue excluida correctamente — no es que ahora todo admita
cualquier cantidad de gente, solo lo que el hotel configuró
explícitamente.

### 2.4 ✅ YA CORREGIDO (2026-07-11): consolidar los campos de ocupación en el editor de habitación

**Por qué el fix de §2.3 no bastó por sí solo — caso real.** El mismo día
del fix, el usuario probó de nuevo con la habitación 101 de motellacupula
(la que "sí permite 3 con costo extra" en sus palabras) y seguía sin
aparecer. Auditoría de los datos reales: `included_occupancy=2`,
`extra_guest_fee=650`, pero **`max_occupancy=NULL`** — nunca se capturó.
Causa raíz: "Ocupación máxima" vivía en la sección "Ocupación / superficie
/ vista" del editor, separada de "Personas incluidas"/"Cobro por persona
extra" (que sí se habían juntado en la iteración anterior con una vista
previa en vivo, spec-wizard-precios-y-pasos §4) — un hotelero que
configura el recargo por persona extra no tiene ninguna señal de que
además necesita ir a OTRA sección a subir un número distinto. El fix de
§2.3 era correcto, pero el editor nunca guio al hotelero a completarlo.

**Auditoría completa del flujo de alta** (los 3 campos —
`max_occupancy`, `included_occupancy`, `extra_guest_fee` — son
`nullable`, sin validación cruzada en el backend):

| Flujo de alta | ¿Expone los 3 campos de ocupación? |
|---|---|
| "Nueva habitación" (formulario completo) | Sí, siempre lo hizo — pero repartidos en 2 secciones distintas |
| "Alta masiva" (rango de números) | No — crea cuartos con ocupación 100% heredada del tipo |
| "Habitación única" (alta rápida motel) | No — mismo caso |
| "Duplicar habitación" | Sí, copia los 3 campos del cuarto origen (`Room::duplicateAsNew`) |

**El fix aplicado:**
- Los 3 campos ahora viven en UNA sola sección ("Ocupación de esta
  habitación"), en orden lógico: máxima → incluidas → cobro extra.
- `occupancyPreview` (la vista previa en vivo) tenía su propio bug
  relacionado: comparaba `included_occupancy` contra la capacidad del
  TIPO, no contra `max_occupancy` — exactamente el campo que §2.3 dejó
  como techo real. Con eso, la advertencia "el recargo nunca se va a
  cobrar" podía quedar callada (o equivocada) en el caso exacto de la
  habitación 101. Ya corregido: compara contra `max_occupancy` (con la
  capacidad del tipo como respaldo si está vacío) y explica cuál de los
  dos está usando.

**Deliberadamente NO corregido (alcance, no bug):** "Alta masiva" y
"Habitación única" siguen sin exponer estos 3 campos — crean varios
cuartos idénticos compartiendo tipo, así que personalizar la ocupación de
UNO en particular no encaja ahí de forma natural; el flujo esperado
sigue siendo crear en masa con la ocupación base y después editar
individualmente los cuartos que sean distintos (ej. la única suite con
sofá-cama). Si esto se vuelve un problema real, la solución sería un
recordatorio/banner post-creación ("N habitaciones creadas — ¿alguna
admite más gente con cargo extra? Personalízala aquí"), no exponer los 3
campos en un formulario que ya es de "muchos cuartos a la vez".

**Importante para motellacupula:** el sistema ya no le puede ADIVINAR el
número a la habitación 101 — alguien del hotel tiene que entrar a
Habitaciones → 101 → Editar → "Ocupación de esta habitación" y poner
"Ocupación máxima" en 3 (o el número real). Es una decisión de negocio
(cuántas personas caben físicamente), no algo que el código deba inferir
solo porque configuraron un cobro extra.

### 2.5 ✅ YA CORREGIDO (2026-07-11): wizard restructurado — "cuántos son" se pregunta DESPUÉS de elegir cuarto

**Por qué ni el fix de §2.3 alcanzaba de raíz.** Pedirle al huésped
"¿cuántos son?" en el PRIMER paso (antes de saber qué habitación va a
elegir) nunca podía calzar bien: cada cuarto tiene su propio tope real
(§2.3/§2.4), así que un solo número preguntado al inicio no tiene forma
correcta de decidir "qué mostrar u ocultar" — de ahí toda la confusión de
esta sesión (ocultar vs. mostrar con recargo, el badge "hasta N" que no
se entendía, etc.). Propuesta del usuario, confirmada como mejor diseño:
mover esa pregunta a un paso propio, DESPUÉS de elegir el tipo, cuando el
sistema ya sabe exactamente cuál cuarto es y puede topar el selector a su
máximo real de verdad.

**Wizard restructurado — pasos nuevos:**

```
Fechas → Confirmar habitación → Extras → Tus datos → Confirmación
```

(antes: `Fechas (con adultos/niños) → Tus datos → Extras → Confirmación`)

- **"Fechas"** ya NO pide personas — solo fecha/hora (y modalidad
  noche/bloque si el catálogo vende ambas). La lista de tipos que
  devuelve `availability()` ahora cotiza con **1 adulto de anclaje**
  (nunca dispara `extraChargeLines`), mostrando un precio "desde" limpio;
  `adults`/`children` en la query string son ahora **opcionales**.
- **"Confirmar habitación"** (paso nuevo): detalle del tipo elegido +
  selector de adultos/niños con **tope duro en la UI** = el
  `effective_capacity` real de ese cuarto (§2.3). Cada cambio
  re-consulta `availability()` (debounced 300ms), ahora acotado por el
  nuevo parámetro `room_type_id`, para refrescar el total/desglose en
  vivo — y para recalcular qué cuarto específico conviene ofrecer (el de
  modificador más barato QUE ADEMÁS admite a esa gente). Si nadie del
  tipo alcanza, se avisa ahí mismo (`available:false`) en vez de que la
  tarjeta desaparezca de la lista original.
- **"Extras" → "Tus datos"** se intercambiaron de orden: primero qué se
  va a llevar (extras del POS), luego quién es (nombre/teléfono) — no
  hace falta la identidad del huésped para decidir si quiere un
  refresco.
- El límite del stepper en pantalla es ayuda de UX; **`CreateReservation`
  en el servidor (§2.3) sigue siendo quien de verdad blinda esto**, por
  si alguien manda la petición directa sin pasar por la pantalla — ambas
  capas conviven, ninguna reemplaza a la otra.

3 tests nuevos de backend (`availability()` sin `adults` cotiza con
anclaje 1; `room_type_id` acota la respuesta a un tipo; el tipo ya NO
desaparece cuando nadie alcanza, se marca `available:false`), 231 en
total. Verificado en vivo contra motellacupula: `availability()` sin
`adults` devuelve los 6 tipos activos con precio "desde"; re-consultado
con `room_type_id=1&adults=3` (Sencilla, sin `max_occupancy` propio
todavía) devuelve `available:false`; con `room_type_id=5&adults=3`
(Master Junior, capacidad 3 de catálogo) devuelve `available:true` y el
total correcto; hold de extremo a extremo creado y limpiado.

---

## 3. Módulo nuevo: Experiencias y recorridos

### 3.1 El problema

Algunos hoteles/moteles venden recorridos o actividades aparte de la
habitación — recorridos en cuatrimoto, excursiones, tours — cada uno con
su propio nombre, cupo de personas, duración, qué incluye, descripción,
precio. Esto **no es lo mismo** que "Extras de reserva" (spec-motor-
reservas-web §12.1: decoración, desayuno, cama extra — cosas que se
PEGAN a una reserva de cuarto existente, sin calendario ni cupo propio,
ya construido vía el paso de Extras del wizard sobre el módulo POS). Una
experiencia se reserva POR SÍ SOLA: puede o no ir de la mano de una
estancia de habitación.

Esto ya estaba anticipado en `spec-motor-reservas-web.md §12.2` (E5, P2,
deliberadamente diferido — "BookingPress se queda para los tours
mientras tanto, sin conflicto") pero nunca se aterrizó el diseño a
detalle ni se construyó. Este documento lo aterriza.

### 3.2 Modelo de datos propuesto

**`experiences`** (catálogo, por propiedad):

| Campo | Tipo | Notas |
|---|---|---|
| `name` | string | "Recorrido en cuatrimoto" |
| `description` | text | qué es, en lenguaje de venta |
| `includes` | json | qué incluye (equipo, guía, refrigerio…) — lista de strings |
| `duration_minutes` | int | duración del recorrido/actividad |
| `pricing_mode` | enum | `per_person` \| `flat` (ver pregunta §3.5.1) |
| `price` | decimal | por persona o por grupo, según `pricing_mode` |
| `min_people` / `max_people` | int | rango de personas por RESERVA (no confundir con el cupo de la sesión) |
| `active` | bool | |
| **Fotos** | medialibrary | igual que `RoomType` |

**`experience_sessions`** (una fecha/hora concreta, con cupo):

| Campo | Tipo | Notas |
|---|---|---|
| `experience_id` | FK | |
| `starts_at` | datetime | |
| `capacity` | int | cupo TOTAL de la sesión (suma de personas de todas las reservas de esa sesión) |
| `status` | enum | `scheduled` \| `cancelled` \| `completed` |

**`experience_bookings`**:

| Campo | Tipo | Notas |
|---|---|---|
| `experience_session_id` | FK | |
| `guest_id` | FK nullable | liga al CRM igual que `Reservation` |
| `reservation_id` | FK nullable | si el huésped YA tiene una estancia, se puede ligar (opcional — alguien puede reservar solo el tour, ver §3.5.2) |
| `people` | int | cuántas personas |
| `total` | decimal | congelado al reservar |
| `status` | enum | `pending` \| `confirmed` \| `cancelled` \| `completed` |
| `code` | string | folio propio, ej. `EXP-2026-0001` (mismo patrón que `Reservation::formatCode`) |

### 3.3 Cómo se reserva

- **Admin**: nueva sección en el panel ("Experiencias") — CRUD de
  `experiences` + calendario de sesiones (crear sesiones sueltas o
  recurrentes, ver §3.5.5).
- **Wizard público**: página propia `/reservar/experiencias` (mismo
  patrón standalone que `/reservar`, sin `RazeLayout`) — lista de
  experiencias activas → elige sesión con cupo disponible → datos del
  huésped → pago. Reutiliza el motor de pagos: `payment_requests` se
  generaliza para apuntar a `experience_booking_id` en vez de
  `reservation_id` (cambio acotado, ya lo anticipaba spec-motor-
  reservas-web §12.2).
- **Opcional, P2**: ofrecer la experiencia como paso adicional DENTRO del
  wizard de habitaciones (para quien ya está reservando cuarto) —
  reutilizaría el mismo patrón que el paso de Extras (POS) ya construido,
  pero apuntando a `experience_sessions` con cupo en vez de productos con
  stock.
- **Bot** (P2): tools `get_experiences()` / `create_experience_booking()`,
  mismo patrón que las tools de habitaciones (spec-pendientes §4.3).

### 3.4 Relación con "Extras de reserva" (§12.1) — no se mezclan

Aclaración explícita para no repetir la confusión de nomenclatura que ya
se resolvió una vez en este proyecto (el módulo `pos`/wizard-extras vs el
módulo `extras` reservado para add-ons). "Extras" (§12.1, decoración/
desayuno) y "Experiencias" (este documento) son DOS módulos con gates
independientes — `extras` y `experiencias`, ya reservados en
`config/modules.php` (ambos `available: false` hoy, listos para
activarse cuando se construyan). Un hotel puede tener uno, otro, ambos o
ninguno.

### 3.5 Preguntas abiertas (decidir antes de construir — este es el módulo grande, vale la pena aterrizarlas primero)

1. **Precio**: ¿siempre por persona, o también precio fijo por grupo/
   vehículo (ej. "cuatrimoto para 2, $800 el vehículo, no por cabeza")?
   Propuesto: campo `pricing_mode` (`per_person` / `flat`) desde el
   inicio, para no tener que migrar después.
2. **¿Requiere reserva de habitación?** ¿Se puede comprar SOLO el tour
   (turista de paso, sin hospedarse), o siempre debe ir ligado a una
   `Reservation`? Recomendado: independiente — más flexible, más
   mercado (`reservation_id` nullable, como ya está arriba).
3. **Cupos y overbooking**: ¿el cupo de la sesión es duro (no se puede
   exceder, como las habitaciones) o el staff puede forzarlo (ej. cliente
   frecuente, cortesía)? Recomendado: duro con lock igual que
   `AvailabilityService`, para no reintroducir el riesgo de doble-venta
   que ya se resolvió ahí — con override manual de staff como excepción
   explícita, no default.
4. **Cancelación por el hotel**: si llueve o no se junta el mínimo,
   ¿cómo se avisa/reembolsa a los ya inscritos? Reutilizar `refunds` de
   spec-pagos §6.6, mismo patrón de sugerencia-no-automática (la decisión
   final siempre humana).
5. **Recurrencia de sesiones**: ¿el admin crea cada sesión a mano, o hace
   falta un patrón "cada martes y jueves 10am, hasta fin de temporada"
   que genere sesiones automáticamente? Para v1, sesiones manuales
   alcanzan; recurrencia es candidato a P2 dentro del propio módulo.

---

## 4. Plan de implementación

| Paso | Qué | Tamaño |
|---|---|---|
| P0 | Capacidad real por habitación en la búsqueda + `CreateReservation` como único punto de verdad | ✅ Hecho |
| P0.1 | Elegir pasarela específica cuando hay varias activas (§1.4) | ✅ Hecho |
| P0.2 | Consolidar campos de ocupación en el editor + corregir `occupancyPreview` (§2.4) | ✅ Hecho |
| P1.a | `payment-options` con WhatsApp + botón en la confirmación de transferencia (§1.2) | S |
| P1.c | Vincular `Conversation`↔`Reservation` automáticamente por teléfono (§1.3) | S |
| P2 | Módulo Experiencias completo (§3) — el más grande; resolver §3.5 primero | L |

Siguiente paso sugerido: P1.a (WhatsApp) es lo único que queda corto y
rápido; P1.c cuando haya tiempo de sobra; el módulo de Experiencias (P2)
arranca cuando estén resueltas las preguntas de §3.5 — es una pieza
grande y nueva, vale la pena tenerlas claras antes de empezar a construir
modelos y migraciones.

---

## 5. Mapa modular: qué activa qué

Petición explícita del usuario: que quede claro, de un vistazo, qué
controla cada capacidad — para saber qué prender/revisar sin tener que
leer código. "Módulo" = gate de `config/modules.php` (apaga la feature
entera); "Setting" = interruptor por hotel en `/ajustes` o
`/ajustes/wizard`; "Dato" = pura configuración de catálogo, sin
interruptor — si está bien capturado, funciona; si no, no aparece (no es
un "bug", es que falta capturarlo).

| Capacidad | Se controla por | Dónde |
|---|---|---|
| El wizard `/reservar` existe | Módulo `motor-web` | Plan/`/admin/plans` |
| Modalidad noche vs. bloque | Dato (catálogo de tarifas) | Se infiere solo — si el hotel solo tiene tarifas `block`, el selector nunca aparece |
| Niños permitidos o no | Setting `guest_policy` | `/ajustes/wizard` |
| Paso "Extras" (POS) | Módulo `pos` **+** Setting `wizard_extras_enabled` **+** Dato (productos marcados `available_in_wizard` con stock) | `/ajustes/wizard` |
| Pedir pago en línea siempre / nunca / según tarifa | Setting `payment_mode` | `/ajustes/wizard` |
| Elegir entre pasarelas específicas | Dato (cuántas pasarelas activas haya) — 2+ activas = aparece el selector, sin interruptor propio | `/ajustes` → Pasarelas de pago |
| Transferencia como método | Dato (¿hay al menos 1 cuenta bancaria activa?) | `/ajustes` → Cuentas bancarias |
| Comprobante por WhatsApp (§1.2, P1) | Dato (¿hay canal WhatsApp conectado o teléfono de contacto?) — sin interruptor propio, aparece solo si hay a quién mandarlo | `/ajustes` → Canales, o `/ajustes` → teléfono |
| Habitación admite más gente con recargo | Dato puro, 3 campos por HABITACIÓN: `max_occupancy` (techo), `included_occupancy` (gratis), `extra_guest_fee` (precio del resto) — sin capturar los 3 coherentemente, no tiene efecto | Habitaciones → editar cuarto → "Ocupación de esta habitación" |
| Temporadas y promos por tarifa | Dato (¿la tarifa tiene `rate_plan_seasons` activas para esas fechas?) | Catálogo → tarifa → "Temporadas y promos" |
| Módulo Experiencias (§3, P2) | Módulo `experiencias` (ya reservado, `available:false` hasta construirse) | Plan/`/admin/plans`, cuando exista |
| Módulo Extras de reserva (§3.4, distinto de POS) | Módulo `extras` (ya reservado, `available:false`, aún sin construir) | Plan/`/admin/plans`, cuando exista |
