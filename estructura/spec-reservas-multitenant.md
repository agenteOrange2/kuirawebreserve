# Spec técnico — Sistema de reservas multitenant (hoteles / moteles)

> Documento vivo. v0.1 — punto de partida para iterar.
> Stack central: **Laravel 12 + PHP 8.3+ · Vue 3 + Inertia 2 · Tailwind · Reverb · Redis**

---

## 1. Resumen ejecutivo

Un **core SaaS multitenant en Laravel 12** es la única fuente de la verdad (habitaciones, inventario, reservas, agentes, reportes). Todo lo demás son clientes:

- **Panel interno de staff** → Vue 3 + Inertia 2 (acoplado al core).
- **Plugin de WordPress** → cliente de la API REST (widget de disponibilidad/reserva en el sitio público del hotel).
- **Otros proyectos Laravel tuyos** → clientes de la misma API.
- **Agentes IA (WhatsApp/IG/FB)** → clientes de la API vía tool-calling + webhooks.

Regla dura: **Inertia es solo para el panel interno. Todo lo externo va por API (Sanctum).** Esto evita dobles reservas porque todos leen la misma disponibilidad.

### Decisiones abiertas (confirmar antes de codear a fondo)
1. **Aislamiento de datos:** DB-por-tenant (recomendado, ver §4) vs single-DB con `tenant_id`.
2. **WhatsApp oficial vs no-oficial:** Meta Cloud API (formal, sin riesgo de ban) vs Evolution API (rápido, riesgo de ToS). Ver §9.
3. **Alcance del MVP:** ¿arrancamos con hotel/motel puro (habitaciones + estados + reservas) y dejamos inventario/agentes para fase 2? Recomendado sí.

---

## 2. Stack recomendado y justificación

| Capa | Elección | Por qué |
|---|---|---|
| Core | Laravel 12, PHP 8.3+ | Base del proyecto, tu terreno. |
| Multitenancy | `stancl/tenancy` (DB por tenant) + DB central | Aislamiento fuerte (moteles → datos sensibles). Ver §4. |
| Frontend interno | Vue 3 (`<script setup>`) + Inertia 2 | Encaje nativo con Laravel, tu velocidad, Inertia 2 trae deferred props / polling / prefetch. |
| Mapa visual | Vue Flow (`@vue-flow`) o `vue-konva` | Canvas de nodos arrastrables para el plano de habitaciones. |
| Tiempo real | Laravel Reverb + Echo | Semáforos que actualizan en vivo en todas las pantallas. WebSockets nativos. |
| Estados | `spatie/laravel-model-states` | Máquina de estados con guards + eventos (semáforo). |
| Permisos | `spatie/laravel-permission` | Roles: platform-admin, owner, manager, front-desk, housekeeping, kitchen, agent. |
| Auditoría | `spatie/laravel-activitylog` | El "historial" de cada habitación/reserva. |
| Media | `spatie/laravel-medialibrary` | Fotos de habitaciones. |
| Dinero | `cknow/laravel-money` (Brick\Money) | Precisión monetaria; te sirve para márgenes/COGS. |
| API externa | Laravel Sanctum + versionado | Tokens por cliente (WP, agentes, otros Laravel). |
| Colas/cache | Redis + Horizon | Ya lo tienes en tu VPS. Jobs de reportes, webhooks, mensajes de agentes. |
| Agentes LLM | `prism-php/prism` | Tool-calling nativo en Laravel, multi-proveedor por API: ChatGPT, Claude, DeepSeek, Kimi, MiniMax (seleccionable; ver spec-modulos-profundidad §10.2.1). |
| Canales | Evolution API (WA) / Meta Graph API (IG/FB) | Reutilizas tu infra actual. |
| Pagos (fase 2) | MercadoPago / Conekta / Openpay | Relevantes para México. |
| Deploy | Docker Compose (+ Octane/FrankenPHP opcional) | Ya lo dominas. Octane mejora rendimiento y convive con Reverb. |

**Vue vs React:** Vue+Inertia es lo más robusto *para este proyecto* por encaje con Laravel, tu experiencia previa y ecosistema suficiente (Vue Flow / Konva cubren el canvas). React solo valdría la pena con equipo React o app React Native compartida.

---

## 3. Arquitectura general

```
┌───────────────────────┐        ┌───────────────────────────────┐
│  Panel interno         │        │  Consumidores externos          │
│  Vue 3 + Inertia 2     │        │  · WordPress plugin             │
│  (staff / operación)   │        │  · Otros proyectos Laravel      │
└───────────┬────────────┘        │  · Agentes IA (WA/IG/FB)        │
            │ Inertia (SSR)        └───────────────┬────────────────┘
            │                                      │ API REST + Webhooks (Sanctum)
            ▼                                      ▼
┌────────────────────────────────────────────────────────────────┐
│  CORE SaaS · Laravel 12 (multitenant)                            │
│  ┌────────────┬────────────┬────────────┬────────────┐          │
│  │Habitaciones│ Inventario │  Reservas  │  Reportes  │          │
│  │Mapa+estados│ BOM+combos │ Calendario │ KPIs·export│          │
│  └────────────┴────────────┴────────────┴────────────┘          │
│  DB por tenant + DB central · Redis/Horizon · Reverb (realtime)  │
└────────────────────────────────────────────────────────────────┘
```

---

## 4. Multitenancy

**Recomendación: DB-por-tenant con `stancl/tenancy` + una DB central.**

- **DB central:** registro de tenants (hoteles), billing/planes, super-admin, catálogo global.
- **DB por tenant:** habitaciones, reservas, inventario, huéspedes, conversaciones. Aislamiento total → argumento de venta fuerte para moteles (privacidad) y cero riesgo de fuga cruzada.
- **Identificación de tenant:** por dominio/subdominio (`hotelx.tudominio.com`) para el panel, y por token de API (que ya trae el `tenant_id`) para clientes externos.

**Trade-off:** reporting global (tuyo como dueño de plataforma) requiere agregar entre DBs; las migraciones corren sobre N bases (automatizable con `tenants:migrate`). Si prefieres simplicidad operativa, la alternativa es single-DB con global scopes por `tenant_id` — más fácil de operar y reportar, menor aislamiento. **Para moteles me inclino por DB-por-tenant.**

---

## 5. Dominio — modelos principales

> Campos ilustrativos, no exhaustivos. Todo dentro del contexto de un tenant.

### Property / Propiedad
Un tenant puede tener 1+ propiedades.
`id, name, timezone, address, settings(json)`

### Zone / Floor (piso o zona)
Para agrupar el plano. `id, property_id, name, order`

### RoomType (tipo de habitación)
`id, property_id, name, capacity, base_price, amenities(json)`
Ej: sencilla, doble, suite, jacuzzi.

### Room (habitación)
`id, property_id, zone_id, room_type_id, number, status, pos_x, pos_y, width, height, notes`
- `pos_x/pos_y/width/height` → posición en el canvas del plano (drag-and-drop).
- `status` → ver §6 (máquina de estados).

### RatePlan (tarifa) — clave para moteles
`id, property_id, room_type_id, type(hourly|block|night|day), duration_minutes, price, name`
Moteles cobran por **bloques/ratos**; hoteles por noche. El motor de disponibilidad (§7) debe manejar ambos.

### Stay (estancia / ocupación activa)
`id, room_id, rate_plan_id, guest_name?(opcional), num_people, check_in_at, check_out_at?, planned_end_at, amount, channel(walk_in|web|whatsapp|...), created_by, status, notes`
- Nombre opcional (moteles suelen ser anónimos).
- `created_by` puede ser usuario o agente IA.

### Reservation (reserva futura)
`id, room_type_id, room_id?, rate_plan_id, guest, num_people, starts_at, ends_at, status(pending|confirmed|checked_in|cancelled|no_show), source_channel, hold_expires_at, deposit_amount`
- `hold_expires_at` → reserva temporal que expira si no se confirma.

### Guest / Contact (huésped / contacto)
`id, name?, phone?, email?, meta(json)` — vinculable a conversaciones de agentes.

### Payment
`id, stay_id?, order_id?, amount, method, status, paid_at`

**Historial:** cada cambio relevante (estado de habitación, check-in/out, edición de reserva) se registra vía `activitylog` + un `room_status_log` dedicado.

---

## 6. Máquina de estados de habitaciones (semáforo)

Estados finitos con transiciones controladas (`spatie/laravel-model-states`):

| Estado | Color sugerido | Significado |
|---|---|---|
| `available` | verde | Libre y limpia |
| `reserved` | morado | Reservada, sin check-in aún |
| `occupied` | rojo | Ocupada |
| `dirty` / `checkout_pending` | naranja | Se fueron, falta limpieza |
| `cleaning` | azul | En limpieza |
| `maintenance` | gris | Fuera de servicio |

**Transiciones válidas (ejemplos):**
```
available → reserved → occupied → dirty → cleaning → available
available → occupied  (walk-in)
cualquiera → maintenance → available
```

Cada transición:
1. Valida con guards (ej. no pasar a `occupied` si ya está ocupada).
2. Dispara un evento (`RoomStatusChanged`).
3. Registra en `room_status_log` (quién, cuándo, de → a).
4. Broadcast por Reverb → el plano se actualiza en vivo en todas las pantallas.

```php
// Sketch
class RoomState extends State {
    public static function config(): StateConfig {
        return parent::config()
            ->default(Available::class)
            ->allowTransition(Available::class, Occupied::class)
            ->allowTransition(Occupied::class, Dirty::class)
            ->allowTransition(Dirty::class, Cleaning::class)
            ->allowTransition(Cleaning::class, Available::class)
            ->allowTransition([Available::class, Occupied::class], Maintenance::class);
    }
}
```

---

## 7. Motor de disponibilidad y concurrencia

El punto más delicado: **evitar doble reserva** cuando entran peticiones simultáneas (web + WhatsApp + walk-in).

**Consulta de disponibilidad:** dado `room_type + rango(datetime)`, devolver habitaciones sin solape en `stays` ni en `reservations` activas.
```sql
-- Solape: (start_a < end_b) AND (end_a > start_b)
WHERE room_id = ?
  AND status IN ('confirmed','checked_in')
  AND starts_at < :requested_end
  AND ends_at   > :requested_start
```

**Anti-doble-reserva:**
- Transacción + **lock pesimista** (`lockForUpdate`) sobre las filas candidatas al confirmar.
- O sistema de "hold" (reserva temporal con `hold_expires_at`) para el flujo del agente/web: se aparta la habitación, se confirma, y si no, expira (job en cola la libera).
- Manejar tanto tarifas por **noche** como por **bloque/hora** (moteles).

---

## 8. Inventario y BOM (combos)

Mini-ERP de alimentos/bebidas. Descuenta stock al vender.

### Product
`id, sku, name, type(simple|composite), unit, price, track_stock(bool), cost`
- **simple** (coca): al vender, descuenta su propio stock.
- **composite** (hamburguesa): descuenta sus ingredientes vía receta.

### Ingredient / RawItem
`id, name, unit, stock_qty, reorder_point, cost` — pan, carne, mayonesa...

### Recipe / BOM (Bill of Materials)
`id, product_id, ingredient_id, quantity` — la hamburguesa = N ingredientes en cantidades. Al venderla, se "explota" la receta y se descuenta cada ingrediente.

### StockMovement
`id, ingredient_id|product_id, type(purchase|sale|waste|adjustment), qty, unit_cost, ref_type, ref_id, created_at`
- Todo movimiento queda auditado → habilita valuación (FIFO / costo promedio), COGS y márgenes por venta. (Te conecta con tu curso de análisis financiero.)

### Order / venta (POS)
`id, stay_id?(cargo a habitación), lines[], total, status`
Al confirmar → genera `StockMovement`s (descuenta, explota BOM), alimenta reportes de ingresos.

### Features de inventario
- Ubicaciones/almacenes por propiedad (bar, cocina, minibar por habitación).
- Alertas de stock bajo / punto de reorden.
- Merma (waste) como tipo de movimiento.
- (Fase 2) Proveedores + órdenes de compra.

---

## 9. Capa de agentes IA / canales

**Arquitectura:** el core expone una "Agent API" (herramientas) que el LLM invoca vía **tool-calling** (`prism-php`):
- `check_availability(room_type, dates)`
- `get_room_details(id)` / `get_pricing(...)`
- `create_reservation_hold(...)` → crea hold, **no** cobra.
- `get_policies()` (RAG sobre datos del tenant: políticas, horarios, precios).

**Flujo con humano en el loop (recomendado al inicio):**
1. Llega mensaje por WhatsApp/IG/FB → webhook → cola.
2. Agente arma respuesta / propone reserva (usando tools).
3. Info simple → responde solo. Reserva/cobro → **requiere aprobación** en el panel antes de enviarse.
4. Todo queda en `conversations` / `messages`, vinculado a `Contact` y a la `Reservation`.

**Canales:**
- **WhatsApp:** Evolution API (rápido, reutilizas tu infra; riesgo de ToS) **o** Meta WhatsApp Cloud API (oficial, requiere verificación de negocio, sin riesgo de ban). *Decisión pendiente §1.*
- **IG/FB DM:** Meta Graph API (requiere app verificada + permisos).

**LLM:** `prism-php` conectado por API al proveedor que se habilite (OpenAI/ChatGPT, Anthropic/Claude, DeepSeek, Kimi, MiniMax) con cadena de fallback — sin modelos locales. Detalle en spec-modulos-profundidad §10.2.1.

### Modelos
- `Channel` → `id, property_id, type(whatsapp|instagram|facebook|webchat), credentials(encrypted), active`
- `Conversation` / `Message` → hilos, vinculados a `Contact` y opcionalmente a `Reservation`.
- `AgentInstruction` → prompt/políticas por propiedad (el "system prompt" del hotel).

---

## 10. Tiempo real

**Laravel Reverb + Echo.** Canales privados por propiedad:
- `property.{id}.rooms` → cambios de estado del plano (semáforo en vivo).
- `property.{id}.reservations` → nuevas reservas / cambios.
- `property.{id}.agent` → mensajes entrantes pendientes de aprobación.

El componente Vue del plano se suscribe y refleja cambios sin recargar. Esta es la "reactividad" que buscas.

---

## 11. Reportes

**KPIs hoteleros:**
- Occupancy rate (diario/semanal/mensual).
- ADR (Average Daily Rate), RevPAR (Revenue per Available Room).
- Duración promedio de estancia, rotación de habitaciones.

**Ingresos:**
- Habitaciones vs A&B (inventario), por canal, por tipo de habitación.

**Inventario:**
- COGS, márgenes, merma, valuación de stock, top productos, stock bajo.

**Agentes/canales:**
- Conversaciones, tasa de conversión (chats → reservas), tiempo de respuesta.

**Housekeeping:**
- Tiempos de limpieza, turnaround de habitaciones.

**Entrega:**
- Export CSV / Excel / PDF.
- Reportes programados semanales/mensuales por email (Laravel Scheduler + jobs en cola).

---

## 12. Roles y permisos

`spatie/laravel-permission`:
- **platform-admin** (tú): gestión de tenants, billing, global.
- **owner** (dueño hotel): todo dentro de su(s) propiedad(es).
- **manager**: operación + reportes.
- **front-desk**: check-in/out, reservas, cobros.
- **housekeeping**: cambia estados de limpieza.
- **kitchen/bar**: órdenes e inventario.
- **agent** (sistema): identidad de los bots para auditoría.

---

## 13. API + plugin de WordPress

**API (Sanctum):** versionada (`/api/v1/...`), token por cliente. Endpoints núcleo:
- `GET /availability`
- `POST /reservations` (crea hold/confirma)
- `GET /room-types`, `GET /pricing`
- Webhooks salientes (reserva creada/cancelada) para sincronizar clientes.

**Plugin WordPress:** cliente delgado en PHP que:
- Guarda un API token del hotel (una config).
- Renderiza un **widget de reserva** (bloque Gutenberg + shortcode) que consulta disponibilidad y crea reservas contra la API.
- No tiene lógica de negocio propia: todo vive en el core.

---

## 14. Roadmap por fases

| Fase | Entregable |
|---|---|
| **0 — Fundación** | Tenancy, auth/roles, modelado property/zone/room, CRUD habitaciones. |
| **1 — Plano visual** | Canvas drag-and-drop + máquina de estados + tiempo real (Reverb). *El "wow".* |
| **2 — Reservas** | Check-in/out, rate plans (noche + bloque), motor de disponibilidad, calendario. |
| **3 — Inventario** | Productos, BOM/combos, POS, cargo a habitación, movimientos de stock. |
| **4 — Agentes IA** | Canales (arrancar WhatsApp), tool-calling, aprobación humana. |
| **5 — Reportes** | KPIs, dashboards, export, envíos programados. |
| **6 — WordPress** | Plugin + widget público de reserva. |
| **7 — Pagos / avanzado** | Pasarela (MercadoPago/Conekta), analítica avanzada, órdenes de compra. |

**Sugerencia:** no hiervas el océano. Fases 0-2 ya son un producto vendible (gestión de habitaciones + reservas). Inventario y agentes suman valor pero son módulos independientes.

---

## 15. Riesgos y notas

1. **Doble reserva** — el riesgo #1. Locks/holds bien hechos desde fase 2.
2. **WhatsApp no-oficial (Evolution)** — riesgo de baneo por ToS de Meta. Para producción serio, evaluar Cloud API oficial.
3. **IA creando reservas/cobros solo** — arranca siempre con humano en el loop; automatiza cuando confíes en el flujo.
4. **Migraciones multi-DB** — automatizar `tenants:migrate` en el pipeline de deploy.
5. **Tarifas por bloque (moteles)** — no asumas modelo hotelero por noche; el motor debe soportar ambos desde el diseño.
6. **Precios/comisiones de Meta** — cambian seguido; verifica el modelo vigente al integrar canales.

---

*Siguiente paso sugerido: cerrar las 3 decisiones abiertas (§1) y detallar el schema de fase 0-1 a nivel migración.*
