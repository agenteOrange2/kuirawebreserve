# Spec — Módulos por plan y precio único

> Responde tres frentes detectados en julio 2026: (1) el precio se ve
> repetido en `/catalogo` y `/habitaciones` del tenant motellacupula,
> (2) hace falta un sistema de MÓDULOS activables por plan con su área en
> el admin, y (3) cómo debe conectarse WordPress (precios/nombres del
> sistema, imágenes en WP, wizard con pago). El punto 3 ya tiene spec
> propio — aquí solo se aterriza la respuesta y su relación con módulos.
> Complementa `spec-motor-reservas-web.md` y `spec-pagos.md`.

**Prioridades:** `P0` = corrige confusión actual · `P1` = producto · `P2` = madurez.

---

## Parte I — El precio repetido: diagnóstico y precio único

### 1.1 Qué se ve hoy (motellacupula, datos reales)

| Tipo de habitación | `room_types.base_price` | Tarifa capturada | Precio tarifa |
|---|---|---|---|
| Habitación Sencilla | $1,300 | "Precio base" (bloque 12 h) | $1,300 |
| Habitación Remodelada | $1,500 | "Precio base" (bloque 12 h) | $1,500 |
| Habitación Jacuzzi Sencillo | $1,700 | "Precio base" (bloque 12 h) | $1,700 |
| Habitaciones Jacuzzi VIP | $2,000 | "Precio base" (bloque 12 h) | $2,000 |
| Habitación Master Junior | $3,000 | "Precio base" (bloque 12 h) | $3,000 |
| Habitación Master Junior VIP | $3,500 | "Precio base" (bloque 12 h) | $3,500 |

En `/catalogo` la tabla "Tipos de habitación" muestra la columna **Precio
base** y, más abajo, la tabla "Tarifas" muestra **el mismo número otra
vez** por cada tipo. No hay filas duplicadas en la base: es **doble
captura del mismo dato** — el formulario del tipo pide "Precio base ($)"
y además hay que crear una tarifa a mano, y nadie conecta las dos cosas.

Agravante visual del caso motel: cada habitación es única (6 tipos ↔ 6
habitaciones, 101–601), así que `/habitaciones` repite uno a uno los
nombres de `/catalogo` y las dos páginas parecen "la misma lista dos
veces".

### 1.2 Qué cobra realmente el sistema (verificado en código)

- `CreateReservation` calcula SIEMPRE con la tarifa:
  `RatePlan::priceFor()` = precio de la tarifa + `rooms.price_modifier`
  (ajuste por habitación: +$100 vista, −$50 interior).
- `room_types.base_price` **no participa en ningún cobro**. Solo se
  muestra (tabla del catálogo, ficha de habitación en el plano).

Es decir: hay UNA fuente que cobra (la tarifa) y UNA copia decorativa
(`base_price`) que el usuario debe mantener a mano. Esa copia es la
"repetición" — y el día que difieran, el catálogo mostrará un precio y la
reserva cobrará otro. Es exactamente el "precio fantasma" que
`spec-motor-reservas-web.md` §2.1 prohíbe entre sistemas, pero dentro de
nuestra propia casa.

### 1.3 Decisión propuesta: la tarifa es la única fuente `P0`

1. **`base_price` deja de capturarse.** El "precio desde" de un tipo se
   DERIVA: `min(precio de sus tarifas activas)`. Sin tarifa activa → el
   tipo se marca "Sin tarifa — no reservable" (guarda visible, hoy falla
   silencioso).
2. **Alta en una sola captura.** El formulario de crear tipo pregunta
   "¿Precio y modalidad?" (por noche / por bloque de N horas) y crea
   automáticamente la tarifa "Tarifa base" con ese dato. El usuario captura
   el precio UNA vez; editarlo después es editar la tarifa.
3. **Migración**: para tipos existentes, si ya hay tarifa se descarta
   `base_price` (caso motellacupula: las 6 coinciden, cero pérdida); si NO
   hay tarifa, se genera "Tarifa base" por noche desde `base_price` antes
   de eliminar la columna.
4. **UI del catálogo**: una sola sección "Tipos y tarifas" — cada tipo con
   sus tarifas anidadas (expandible), en lugar de dos tablas paralelas que
   repiten nombres y montos. La tabla de tarifas suelta desaparece.
5. **`/habitaciones`**: la habitación no muestra precio propio (no lo
   tiene); muestra su tipo y, si existe, el ajuste (`price_modifier`) como
   badge — que es lo único que de verdad es suyo. El "desde" vive en el
   catálogo.

Alternativa considerada y descartada: conservar `base_price` como copia
sincronizada por observer. Menos disruptiva, pero mantiene dos estados
para un dato y el observer es exactamente la clase de acoplamiento que
luego se rompe en silencio.

`P2` (calidad de vida para moteles): alta rápida "habitación única" que
crea tipo + habitación + tarifa en un solo formulario, porque en moteles
la relación es casi siempre 1:1 y el flujo actual obliga a tres pantallas.

---

## Parte II — Módulos por plan

### 2.1 El principio: módulo ≠ límite

Hoy `config('plans')` (hidratado desde la tabla central `plans`) solo
tiene **límites numéricos** (`max_rooms`, `max_channels`, …) y un booleano
suelto (`ai_enabled`). No existe el concepto "este plan incluye el módulo
X". La regla que ordena el diseño:

- **Límite** = cosa contable (habitaciones, canales, usuarios, pasarelas).
  Sigue siendo columna numérica; no cambia nada.
- **Módulo** = capacidad encendida o apagada (POS, motor web, agente IA,
  extras…). Es lo nuevo: lista de switches por plan, con override por
  hotel.

### 2.2 Catálogo inicial de módulos

Definido en código (`config/modules.php`: key, label, descripción) porque
un módulo implica rutas, menú y tools — no se inventa desde el admin, el
admin solo lo enciende. Mapa contra lo que ya existe:

| Módulo | key | Qué enciende | Gate actual |
|---|---|---|---|
| Núcleo hotelero | — (siempre activo) | Dashboard, plano, reservas, habitaciones, zonas/tipos, huéspedes, bandeja, usuarios, ajustes | ninguno |
| Punto de venta | `pos` | POS, inventario, turnos, cortes de venta | ninguno (hoy todos lo ven) |
| Cobros en línea | `cobros` | Pasarelas, links de pago, checkout | indirecto: `max_gateways > 0` |
| Asistente IA | `agente-ia` | Bot, tools, respuestas automáticas | `ai_enabled` (migra aquí; la cuota `ai_monthly_replies` sigue siendo límite) |
| Motor de reservas web | `motor-web` | Wizard `/reservar`, embed, Booking API, site keys | no existe aún (spec motor E0–E2) |
| Extras de reserva | `extras` | Catálogo de add-ons + paso en wizard/panel/bot | no existe (spec motor §12.1) |
| Experiencias | `experiencias` | Tours/sesiones con cupo | no existe (spec motor §12.2) |

Notas:
- Multipropiedad NO es módulo: ya está resuelto como límite
  (`max_properties`). No duplicar gates.
- `spec-motor-reservas-web.md` §12.3 ya pedía "tabla central de módulos
  por tenant" para extras/experiencias — esta parte ES esa tabla,
  generalizada.

### 2.3 Modelo de datos `P0`

- **`plans.modules`** (JSON, lista de keys): qué incluye cada plan. Se
  expone en `Plan::toConfigArray()` como `'modules' => [...]` para que
  `config('plans.{key}.modules')` funcione igual que todo lo demás.
  `ai_enabled` migra: la columna se conserva un ciclo por compatibilidad,
  pero la verdad pasa a `modules` (contiene o no `agente-ia`).
- **`tenant_modules`** (tabla central, patrón `payment_method_settings`):
  `tenant_id`, `module`, `enabled`, timestamps. Sin fila = hereda del
  plan. Con fila = override del admin (encender un módulo a un hotel
  Básico como cortesía/prueba, o apagárselo a uno Pro).

Resolución en un solo lugar:

```php
// App\Models\Tenant
public function hasModule(string $key): bool
// override en tenant_modules ?? in_array($key, plan.modules) ?? false
```

### 2.4 Enforcement (dónde se hace cumplir) `P0`–`P1`

| Capa | Mecanismo |
|---|---|
| Rutas tenant | Middleware `module:{key}` en los grupos de rutas (POS, cortes, cobros…) → 403 con mensaje "Este módulo no está en tu plan" |
| Menú | `HandleInertiaRequests` comparte `panel.modules` (keys activas); cada item de `useMenu.ts` declara su `module` y se filtra — apagado = no aparece |
| Bot | `AgentBrain` no registra tools de módulos apagados (sin `extras` el bot no ofrece agregar extras) |
| API pública | Booking API responde 404/403 si `motor-web` está apagado |
| Panel admin | El área de planes y la ficha del hotel (2.5) |

Regla de datos: apagar un módulo OCULTA su área pero **no borra ni
bloquea sus datos** (los cortes históricos siguen en la base; si se
reactiva, todo está donde estaba).

### 2.5 El área en el admin `P0`

1. **`/admin/plans` (crear/editar plan)**: el formulario gana la sección
   "Módulos incluidos" — un switch por módulo del catálogo, con su
   descripción. Guardar actualiza `plans.modules`; aplica de inmediato a
   todos los hoteles del plan (mismo comportamiento que los límites hoy).
   La tarjeta de cada plan en el índice lista sus módulos como badges.
2. **`/admin/tenants/{id}` (ficha del hotel)**: tarjeta "Módulos" con el
   estado EFECTIVO de cada uno y su origen — "Incluido en plan Pro",
   "Forzado: activado", "Forzado: desactivado" — y el control para forzar
   u heredar (escribe/borra la fila de `tenant_modules`).

### 2.6 Backfill propuesto

| Plan | modules |
|---|---|
| Básico | `pos` |
| Pro | `pos`, `cobros`, `agente-ia`, `motor-web` |

(`cobros` en Pro es coherente con `max_gateways`: Básico=0, Pro=3. El
módulo controla si el ÁREA existe; el límite, cuántas pasarelas caben.)
`extras` y `experiencias` nacen apagados en ambos hasta que existan.

---

## Parte III — WordPress: respuesta a la duda

La duda planteada: "que el plugin jale precios, nombre y características;
que en WordPress vivan las imágenes; y un wizard shortcode que reserve
contra nuestro sistema y cobre con la pasarela".

**La dirección es correcta y ya está especificada** en
`spec-motor-reservas-web.md`. Los matices que importan:

1. **"Jalar" precios = leerlos en vivo, jamás guardarlos.** El plugin
   consulta la Booking API (precio desde, nombre, capacidad,
   características) al renderizar, con caché de minutos (transient) solo
   para no pegarle al API en cada visita. WordPress **nunca** tiene una
   tabla de precios: el precio que se muestra es el vivo y el que se cobra
   lo calcula siempre nuestro servidor (§2.1 y §6.2 del spec motor). Si se
   copiaran, tendríamos entre WP y Kuira la misma enfermedad de la Parte I
   dentro del catálogo.
2. **Imágenes, galerías, layouts, 360 viven en WP.** Es el "modo WP-dueño"
   (§8): el folleto rico es de WordPress; Kuira solo guarda la ficha
   mínima (nombre, descripción corta, capacidad, 1–3 fotos) para que el
   wizard alojado, el bot y la bandeja se vean bien.
3. **El wizard del shortcode no es un formulario de WP**: `[kuira_reservas]`
   incrusta (iframe/script) el wizard alojado en el subdominio del tenant.
   Fechas → disponibilidad en vivo → hold de 30 min (`CreateReservation`,
   `source_channel='web'`) → pago con la infraestructura de spec-pagos
   (checkout de pasarela o transferencia). **Ninguna llave de pago ni
   webhook toca WordPress.**
4. **El vínculo técnico** es un meta `_kuira_room_type_id` por habitación
   de WP (select poblado desde la API) — con eso el plugin pide precio y
   disponibilidad del tipo correcto.
5. **Orden de construcción** (roadmap del spec motor, sin cambios): E0
   wizard alojado `/reservar` → E1 embed → E2 Booking API + site keys →
   E3 plugin "modo conectado". Para motellacupula basta E0: su WP solo
   agrega el botón "Reservar" a la URL alojada.

**Conexión con la Parte II**: `motor-web` es un módulo — el shortcode, el
embed y la Booking API solo responden si el plan del hotel lo incluye (o
el admin lo forzó). Esto contesta la pregunta 4 del spec motor §13: el
gating por plan se hace con este sistema de módulos, no con un límite ad
hoc.

**Conexión con la Parte I**: el precio único es PRERREQUISITO del motor
web. La Booking API publica "precio desde" y totales; si internamente hay
dos precios por tipo (base vs tarifa), la web puede anunciar uno y cobrar
otro. Primero se unifica adentro, luego se expone afuera.

---

## Roadmap

| Orden | Entregable | Contenido |
|---|---|---|
| M0 `P0` | **Precio único** | Migración `base_price` → tarifa (1.3), alta de tipo crea tarifa, catálogo agrupado, guarda "sin tarifa" |
| M1 `P0` | **Base de módulos** | `config/modules.php`, columna `plans.modules`, `tenant_modules`, `hasModule()`, share Inertia + filtro de menú, backfill 2.6 |
| M2 `P0` | **Área admin** | Sección "Módulos incluidos" en `/admin/plans` + tarjeta "Módulos" con override en la ficha del hotel |
| M3 `P1` | **Enforcement duro** | Middleware `module:` en rutas, tools del bot filtradas, 403 API |
| M4 `P1` | **Motor web gateado** | E0 del spec motor nace ya detrás del módulo `motor-web` |
| M5 `P2` | **Alta rápida motel** | Tipo + habitación + tarifa en un formulario |

M0 y M1 son independientes (pueden ir en paralelo); M2 depende de M1; M4
depende de M1 y de E0.

---

## Preguntas abiertas

1. **¿Básico incluye `pos`?** El backfill propuesto dice sí (hoy todos lo
   ven y quitárselo a hoteles activos se sentiría como downgrade). ¿O POS
   es palanca de upsell y solo Pro?
2. **¿El tenant ve sus módulos?** Una tarjeta "Tu plan incluye" en
   `/ajustes` con los apagados en gris y un "Solicitar activación" es
   upsell barato. ¿Entra en M2 o después?
3. **Módulo apagado con datos históricos**: la regla propuesta es ocultar
   sin borrar. ¿Alguien necesita modo "solo lectura" (ver cortes viejos
   sin poder crear)?
4. **¿`base_price` se elimina físicamente o se deja NULL un ciclo?**
   Propuesto: deprecar en M0 (deja de escribirse/mostrarse) y tirar la
   columna una vez verificado en producción.
5. **Nombre visible**: ¿"Módulos" o "Funciones del plan" en la UI del
   admin? (afecta textos, no diseño).
