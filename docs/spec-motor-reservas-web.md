# Spec — Motor de reservas web: un motor, tres entregas

> Cómo se conecta el sistema de reservas (la matriz) con la presencia web de
> cada hotel: sitios WordPress, desarrollos Laravel a medida, y hoteles SIN
> página (solo Facebook/WhatsApp). Responde la confusión de origen: ¿qué se
> sincroniza, quién manda en los precios, cron o manual?
> Complementa `spec-pagos.md` (el wizard cobra con esa infraestructura) y
> `spec-pendientes-y-agentes.md` §4.5 (webchat embebible).

**Prioridades:** `P0` = el corazón, sin esto no hay venta web · `P1` =
integraciones · `P2` = madurez.

---

## 1. El problema real (las tres situaciones de cliente)

| Situación | Ejemplo | Qué necesita |
|---|---|---|
| **A. Sitio a medida** | Laravel + Vue + Inertia que tú construyes | Que el sitio venda reservas del sistema sin duplicar lógica |
| **B. WordPress** | Ecommerce/brochure en WP que tú u otros mantienen | Un shortcode que ponga el wizard de reservas en cualquier página |
| **C. Sin página** | Solo fan page de Facebook con precios en posts | Un lugar a dónde mandar a la gente a reservar (URL para pegar) |

La tentación natural es "sincronizar el sistema con cada sitio". Esa es la
trampa que hay que evitar.

---

## 2. El principio rector (la decisión que ordena todo lo demás)

### 2.1 Las transacciones NUNCA se sincronizan; se consultan en vivo

Hay dos clases de datos y cada una tiene una regla distinta:

| Clase | Ejemplos | Regla |
|---|---|---|
| **Transaccional** | Disponibilidad, precios/tarifas, holds, reservas, pagos | **Vive SOLO en el sistema.** Los sitios la LEEN en vivo por API en el momento de mostrar/reservar. Jamás se copia a WordPress ni a otro lado. |
| **Contenido** | Fotos de habitaciones, descripciones largas, amenidades | Puede copiarse a WordPress para SEO/diseño (opcional, §8) — por cron o botón manual. Si se desactualiza, no pasa nada grave. |

¿Por qué jamás sincronizar lo transaccional?

- **Doble venta**: una copia de disponibilidad en WP está vieja desde el
  segundo uno. El motor con locks `FOR UPDATE` que ya construiste solo
  protege si TODAS las ventas pasan por él.
- **Precios fantasma**: dos lugares donde editar precio = el hotel cobra
  precios distintos según por dónde entró el huésped. La respuesta a "¿los
  precios los maneja el sistema?" es **sí, únicamente el sistema** —
  WordPress ni los guarda: los muestra leyéndolos en vivo.
- Con lectura en vivo, tu pregunta "¿cron o manual?" **desaparece** para lo
  que importa: no hay nada que sincronizar porque no hay copia.

Nadie "sincroniza" Stripe Checkout a su WordPress: lo embebe. Aquí igual.

### 2.2 Un solo motor, tres formas de entregarlo

El **motor de reservas público** (wizard de 3 pasos, §4) se construye UNA
vez, alojado en el subdominio del tenant, y se entrega de tres maneras:

```
                    ┌──────────────────────────────────────┐
                    │      KuiraWebReserve (la matriz)      │
                    │  tarifas · disponibilidad · holds ·   │
                    │  pagos (F0-F4) · bandeja · agentes    │
                    └───────────────┬──────────────────────┘
                                    │  motor público /reservar
                                    │  (wizard + Booking API)
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
   C. URL alojada          B. Widget embed        A. API headless
   hotel.kuira….com/reservar   <script> 1 línea       REST con site key
   (pegar en FB, QR,           shortcode WP           para wizards 100%
   WhatsApp, linktree)         [kuira_reservas]       a medida en Laravel
```

- **C (sin página)** usa la URL tal cual: botón "Reservar" de la fan page,
  link en posts, QR en la recepción. Cero integración.
- **B (WordPress)** instala un plugin cuyo shortcode incrusta el MISMO
  wizard (iframe/script). El plugin no guarda precios ni reservas.
- **A (Laravel a medida)** puede usar el embed (rápido) o consumir la
  Booking API directo para dibujar su propio wizard (control total).

El mismo motor es también lo que el bot "linkea" cuando alguien pide
reservar desde un canal donde el flujo conversacional no conviene. Todo
converge: **una sola caja registradora, muchas puertas**.

---

## 3. Qué ya existe y se reutiliza (el motor está 80% construido)

| Pieza del wizard | Ya existe como | Cambio necesario |
|---|---|---|
| Consultar disponibilidad + total | `AvailabilityService` + `AgentToolsController::availability` (su docblock ya decía "la usará la API pública") | Exponerla en rutas públicas con throttle |
| Tipos de habitación con fotos/descripción | `RoomType` + `policies()` del agente | Endpoint público de catálogo |
| Crear el apartado (hold 30 min) | `CreateReservation` + idempotencia de la Agent API | Endpoint público con anti-abuso |
| Cobrar (las 4 vías) | **Todo F0-F4**: `IssuePaymentRequest`, checkout de pasarela, transferencia, webhooks, auto-confirmación | Ninguno: el wizard termina redirigiendo al checkout o mostrando cuentas |
| Página de resultado | `/pago/{uuid}` (pública, se auto-refresca) | Ninguno: es el final natural del wizard |
| Confirmación + recordatorios | Webhooks + `payments:collect-balance` + follow-ups | Ninguno |
| Página pública en el subdominio | Patrón del webchat `/chat` (standalone, sin login) | El wizard es la segunda página de este tipo |

Es decir: el "corazón" que te preocupa **ya late** — reservas, precios,
holds y cobros. Lo que falta es la fachada pública (wizard) y sus tres
entregas.

---

## 4. El wizard `/reservar` (P0 — la pieza única)

Página pública en el subdominio del tenant (como el webchat), branding del
hotel, móvil-primero. Tres pasos:

1. **Fechas y personas** → consulta disponibilidad EN VIVO. Muestra los
   tipos de habitación disponibles con foto, descripción, capacidad y el
   TOTAL calculado por el servidor (tarifa por noche o por periodo, igual
   que el panel y el bot). Sin disponibilidad → sugiere fechas cercanas y
   ofrece el webchat.
2. **Datos del huésped** → nombre, teléfono, email. Crea el hold (reserva
   Pendiente, 30 min) con clave de idempotencia — el mismo
   `CreateReservation` de siempre, `source_channel = 'web'` (la etiqueta ya
   existe en reportes).
3. **Pago** → el mismo `IssuePaymentRequest` del bot/panel decide según la
   tarifa: anticipo, pago total o "sin prepago" (solo confirmación del
   hotel). Con pasarela → redirige al checkout y aterriza en `/pago/{uuid}`;
   con transferencia → muestra cuentas + instrucciones y el hold se
   extiende 24 h; sin cobro → pantalla "el hotel te confirmará" (+ el
   follow-up del bot ya persigue estos holds).

Reglas heredadas automáticamente: antelación mínima por tarifa, extensión
de hold al emitir cobro, expiración de holds, revivir pago tardío,
verificación humana de transferencias, avisos por el canal. **Cero lógica
nueva de negocio.**

Conexión con conversaciones: si el huésped llegó del webchat/WhatsApp, la
idea es que el wizard acepte `?conversation={uuid}` para ligar la reserva
a su hilo (el bot y la bandeja la verían — misma memoria que
`crear_apartado`). **No entró en la implementación de E0** — no hay
columna de enlace reserva↔conversación en ningún lado del código hoy; se
deja pendiente para cuando el bot empiece a repartir el link del wizard
activamente (hoy solo lo hace el humano al pegarlo en bio/WhatsApp).

### 4.1 Nota de implementación (E0, 2026-07-10)

Construido tal cual el diseño de este documento, con dos decisiones que
se apartan del borrador original al toparse con datos reales (ver §13.2
y §13.3 arriba) y una lista corta de lo que quedó fuera a propósito:

**Diferido, no bloqueante:**
- **Sugerir fechas cercanas sin disponibilidad** (§9 mencionaba "sin
  disponibilidad → sugiere fechas cercanas"): el wizard hoy solo dice
  "sin disponibilidad" + link al webchat; no re-consulta ±N días
  automáticamente.
- **Límite de holds simultáneos por huésped/IP** (§9.6): no hay tabla de
  IPs ni contador; la única defensa hoy es el throttle de la ruta
  (20/min en `holds`) + honeypot + tiempo mínimo de llenado. Si se detecta
  abuso real, aquí es donde se refuerza.
- **Fotos en la ficha mínima**: el wizard muestra nombre/descripción/
  capacidad/amenidades pero sin imágenes — `RoomType` no tiene campo de
  media todavía.
- **QR descargable** en la tarjeta "Tu página de reservas": por ahora
  solo URL + copiar.
- **`?conversation={uuid}`**: ver arriba.

---

## 5. Entrega C — hoteles sin página (P0, gratis con el §4)

- La URL `https://{hotel}.kuirawebreserve.com/reservar` se pega en:
  botón "Reservar" de la fan page, posts con precios, bio de Instagram,
  respuesta automática de Messenger, estado de WhatsApp, QR impreso.
- En `/ajustes` una tarjeta "Tu página de reservas" con la URL, botón
  copiar y el QR descargable.
- `P2`: dominio propio (`reservas.hotelx.com` → CNAME al tenant, ya tienes
  Cloudflare para wildcard).

Nota: NO se publica nada "en Facebook automáticamente". Facebook es solo
donde el hotel pega su link — el precio que el huésped ve al reservar
siempre es el vivo del sistema, aunque el post viejo diga otra cosa.

---

## 6. Entrega B — WordPress (P1): el caso real de tus plugins

> Auditado en el servidor (jul 2026): `realdelasierra` corre `hotel-rooms`
> v1.2 (plugin propio Kuiraweb) — CPT `hotel_room` + tablas SQL propias de
> precios/temporadas/galería/features, wizard modal de reserva con
> disponibilidad, cupones, depósitos, pagos Stripe/PayPal/MP/transferencia
> y portal de cliente. Es un **motor de reservas completo dentro de WP**.
> `motellacupula` en cambio es solo presentación: CPT `kuiraweb_room` del
> theme con tarifas como TEXTO libre y botones tel:/WhatsApp (sin reserva
> en línea) — y ese motel ya existe como tenant de Kuira.

### 6.1 El diagnóstico: hoy hay dos corazones (y esa es la confusión)

`hotel-rooms` duplica dentro de cada WordPress lo que Kuira hace
multitenant: disponibilidad, precios con temporadas, reservas, pagos.
Mantener ambos implica doble venta entre canales (el bot de Kuira no ve
las reservas del plugin y viceversa), doble catálogo de precios, llaves de
pago dentro de WP (riesgo: los WP de clientes se infectan) y doble
mantenimiento tuyo. **La decisión estructural: un solo corazón — Kuira.**
El plugin no se tira: se convierte en el mejor cliente del motor.

### 6.2 El reparto definitivo (qué se queda en WP, qué pasa a Kuira)

| Pieza de `hotel-rooms` hoy | Destino | Por qué |
|---|---|---|
| CPT `hotel_room`, layouts (classic/modern/slider/event), design system, galerías responsivas por dispositivo, features con iconos, tour 360, sliders | **SE QUEDA en WP** | Es presentación/SEO — tu plugin lo hace muy bien y es tu diferenciador al vender sitios |
| `wp_hotel_room_pricing` + `wp_hotel_room_seasons` (precios base/promo/temporadas) | **MIGRA a Kuira** (tarifas; temporadas = §2.5 de spec-pendientes, se adelanta) | Precio en dos lados = precios fantasma; el bot y el panel deben cotizar lo mismo que la web |
| Disponibilidad + `wp_hotel_room_bookings` | **MIGRA a Kuira** | Un solo motor con locks anti doble-venta para TODOS los canales (web, bot, mostrador) |
| Wizard modal de reserva (UI de 4 pasos) | **SE QUEDA como UI**, pero sus AJAX llaman la Booking API (modo headless §7) | Conservas tu UX y diseño; el negocio corre en Kuira |
| `class-payments.php` (Stripe/PayPal/MP en WP), cupones, depósitos | **SE ELIMINA de WP** — el paso de pago redirige al checkout de Kuira (F0-F4) | Llaves y webhooks fuera de WordPress; anticipos/saldos/verificación ya resueltos allá |
| Portal "Mis reservas" | `P2`: consulta la Booking API por código+email | Kuira es la fuente |
| BookingPress (tours CANAM) | No se toca | Otro negocio, otro flujo |

**El vínculo técnico**: cada `hotel_room` de WP guarda un meta
`_kuira_room_type_id` (select poblado desde la API). Con eso el plugin pide
en vivo "precio desde / disponibilidad / total del rango" y abre el wizard
o manda el hold. El precio que el plugin muestra puede cachearse minutos
(transient); el precio que COBRA siempre es el del servidor.

### 6.3 hotel-rooms v2: "modo conectado" (sin romper lo vendido)

El plugin gana un ajuste **Modo**: `standalone` (todo como hoy, para sitios
que no usen Kuira) y `conectado` (URL del tenant + site key; oculta sus
meta boxes de precios, su calendario y sus pagos; precios y wizard vienen
del motor). Así no fragmentas el producto ni fuerzas migraciones: cada
sitio decide cuándo cambiar de modo.

Para sitios nuevos o simples (sin hotel-rooms) existe además el **plugin
mínimo**: shortcode `[kuira_reservas]`/bloque que incrusta el wizard
alojado en iframe. Sin base de datos propia: nada que desincronizar ni
hackear.

### 6.4 Los dos pilotos, aterrizados

- **motellacupula (el fácil, empezar aquí)**: ya es tenant. Su WP no tiene
  motor que migrar: se agrega el botón "Reservar" apuntando al wizard
  alojado (o embed) y, si se quiere, las tarifas de texto `_room_tarifas`
  se reemplazan por el precio vivo del API. Todo lo demás (galerías, 360,
  WhatsApp) queda igual. Riesgo cero.
- **realdelasierra (el completo)**: (1) alta como tenant: tipos de
  habitación, tarifas y temporadas capturadas en Kuira (las temporadas de
  Kuira deben existir antes — ver roadmap); (2) mapear cada `hotel_room` a
  su room_type; (3) activar "modo conectado"; (4) congelar
  `wp_hotel_room_bookings` (histórico consultable, reservas nuevas solo en
  Kuira). Los pagos del sitio pasan a las pasarelas del tenant.

**Regla de oro comunicable al cliente**: "tu página es el aparador (fotos,
diseño, 360); la caja es el sistema. Los precios se cambian UNA vez, en el
sistema, y se actualizan solos en la página, el bot y el panel."

---

## 7. Entrega A — Laravel/desarrollos a medida (P1)

Dos niveles, según cuánto control quiera el proyecto:

1. **Embed** (default): mismo script/iframe que WordPress. Un componente
   Blade/Vue de una línea. Para el 80% de los sitios basta.
2. **Headless — Booking API** (`/api/booking/*`, pública con site key):
   - `GET catalog` → tipos con contenido y tarifas (precios en crudo + label).
   - `GET availability?arrive=&depart=&guests=` → opciones con TOTAL.
   - `POST holds` (Idempotency-Key) → reserva Pendiente.
   - `POST holds/{code}/payment` → checkout URL o datos de transferencia.
   - `GET reservations/{code}` → estado (para páginas "mi reserva").
   El sitio dibuja SU wizard con SU diseño; el negocio sigue en la matriz.

**Site key por dominio** (nueva tabla central `booking_site_keys`, patrón
`*_links`): identifica al tenant, restringe CORS a los dominios dados de
alta, throttle propio y revocable desde `/ajustes`. Es llave PÚBLICA (como
la publishable de Stripe): no autoriza nada sensible — leer catálogo y
crear holds que expiran solos; el dinero solo se confirma por webhook/staff.

---

## 8. El contenido: quién es dueño de las fotos y textos

Aclarado con los casos reales: el contenido tiene DOS modos según el tipo
de cliente, y ninguno necesita sincronización delicada.

**Modo WP-dueño (sitios con página: realdelasierra, motellacupula).**
El folleto rico — galerías por dispositivo, layouts, 360, amenidades con
iconos — vive y se edita en WordPress (tu plugin/theme ya lo hace muy
bien). Kuira solo guarda la **ficha mínima** por tipo de habitación
(nombre, descripción corta, capacidad, 1-3 fotos): es lo que necesitan el
wizard alojado, el bot y la bandeja para verse decentes. Esa ficha mínima
se captura una vez en el panel; no se sincroniza desde WP (es poca cosa y
cambia rara vez). Si algún día estorba la doble captura, `P2+`: el modo
conectado del plugin puede EMPUJAR la ficha mínima a Kuira con un botón
"Publicar al motor" (manual, no cron — el contenido no es urgente).

**Modo Kuira-dueño (hoteles sin página).** No hay WP: la ficha completa
vive en el panel y el wizard alojado es su única vitrina. Aquí sí puede
crecer a galería completa por tipo.

En ambos modos la regla transaccional del §2 no cambia: fechas, precios y
reservas jamás se copian — se consultan en vivo. El "cron o manual" queda
reducido a contenido opcional y de baja frecuencia.

---

## 9. Seguridad y anti-abuso del motor público

1. **Montos server-side siempre** (herencia de spec-pagos §3.6): el wizard
   jamás manda precios, solo fechas/tarifa/datos del huésped.
2. **Throttle** por IP + por site key; holds con **Idempotency-Key** (ya
   existe el mecanismo de la Agent API).
3. **Anti-bots en el paso 2**: honeypot + tiempo mínimo de llenado en v1;
   Turnstile/reCAPTCHA como opción por hotel si hay abuso. Los holds basura
   expiran solos en 30 min (el sistema ya se limpia).
4. **CORS estricto** por dominios registrados del site key; la página
   alojada y el iframe no necesitan CORS (mismo origen del tenant).
5. Nada de datos de tarjeta en el wizard: el pago siempre es el checkout
   del proveedor o transferencia (PCI igual que el resto).
6. Límite de holds simultáneos por huésped/IP (config) para que nadie
   "congele" el hotel apartando sin pagar.

---

## 10. Quién manda en cada dato (resumen ejecutivo)

| Dato | Dueño único | Los sitios web… |
|---|---|---|
| Precios y tarifas | Sistema (catálogo) | Los muestran en vivo; jamás los editan |
| Disponibilidad | Sistema (motor con locks) | La consultan en vivo |
| Reservas y holds | Sistema | Las crean VÍA el sistema (wizard/API) |
| Pagos | Sistema (F0-F4) | Redirigen al checkout del sistema |
| Fotos y textos | Sistema como fuente; copia opcional en WP | Pueden copiarlos para SEO (cron/manual) |
| Diseño de la página | El sitio del cliente | Libre: embed o headless |

---

## 11. Roadmap propuesto

| Orden | Entregable | Contenido | Para quién abre venta |
|---|---|---|---|
| E0 `P0` ✅ | **Wizard alojado `/reservar`** — IMPLEMENTADO 2026-07-10 | Página pública standalone (patrón webchat) con los 3 pasos; doble modalidad noche/bloque (§13.2); montos siempre recalculados en servidor; anti-abuso (honeypot + tiempo mínimo + throttle + idempotencia); tarjeta "Tu página de reservas" en **Integración** (URL + copiar; sin QR aún) | Los tres casos desde el día uno; **piloto: motellacupula**, ya operable sin plugin (solo falta pegar el botón en su WP) |
| E0.5 `P0` ✅ | **Temporadas y promos en Kuira** — IMPLEMENTADO 2026-07-10 | `rate_plan_seasons` (precio por rango de fechas con prioridad) + precio promocional; editable en el catálogo | Prerrequisito de paridad: `hotel-rooms` ya maneja temporadas — sin esto, realdelasierra no puede ceder los precios |
| E1 `P1` | **Embed universal** | Script 1 línea + iframe auto-altura + parámetros de color; snippet copiable en /ajustes | Laravel y cualquier CMS, sin plugin |
| E2 `P1` | **Booking API headless + site keys** | Tabla central `booking_site_keys` (CORS por dominio) + endpoints §7.2 + docs | El "modo conectado" de hotel-rooms la necesita; también tus Laravel a medida |
| E3 `P1` | **hotel-rooms v2 "modo conectado"** | Ajuste standalone/conectado; meta `_kuira_room_type_id`; precios/disponibilidad vivos; wizard propio contra la API; pagos redirigen a Kuira; **piloto: realdelasierra** (migrar tarifas, congelar `wp_hotel_room_bookings`) | Tus clientes WP con el plugin completo |
| E3.5 `P1` | **Extras de reserva** (§12.1, opt-in por tenant) | Catálogo `extras` + `reservation_extras` que suman al total; paso Extras en wizard y panel; interruptor por tenant (§12.3) | Paridad con el paso Extras de hotel-rooms y las decoraciones de motellacupula |
| E4 `P2` | **Madurez** | Plugin mínimo `[kuira_reservas]` para WP simples, portal "mis reservas" vía API, "Publicar al motor" (ficha mínima WP→Kuira), dominio propio de reservas, atribución por canal, códigos promo (paridad con cupones del plugin) | Optimización |
| E5 `P2` | **Experiencias** (§12.2, opt-in por tenant) | Sesiones con cupo + reservas propias + cobro vía `payment_requests` generalizado + página propia del wizard + tools del bot | Reemplaza BookingPress en realdelasierra (los tours siguen ahí mientras tanto, sin conflicto) |

E0 reutiliza tanto que es una fase corta: el trabajo real es la UI del
wizard; el negocio ya existe. Nota del orden: la Booking API (E2) sube de
prioridad respecto al borrador anterior porque el modo conectado de
hotel-rooms — tu caso más valioso — es un cliente headless, no un iframe.

**E0.5 IMPLEMENTADO (2026-07-10):** tabla `rate_plan_seasons` (rate_plan_id, name, kind season|promo, starts_on, ends_on, price, priority, active) — un rango de fechas con un precio que SUSTITUYE al de la tarifa mientras esté vigente; `kind` es solo etiqueta, el mecanismo es el mismo para temporada y promo. `RatePlan::activeSeasonFor(fecha)` resuelve solapes por `priority` (empate → id mayor). `priceFor()` para tarifas `night` ahora calcula **noche por noche** (cada una puede caer en una temporada distinta si la estancia cruza el límite); para `block` resuelve la temporada del día de inicio. `priceBreakdown()` agrupa noches consecutivas de la misma temporada en una sola línea ("Fin de semana largo (2 noches)"), cero cambio de forma cuando no hay temporadas. CRUD REST anidado `rate-plans/{id}/seasons` (`RatePlanSeasonController`), UI en `/catalogo` (modal aparte "Temporadas y promos", abierto desde un badge en la fila de cada tarifa). 12 tests nuevos (`RatePlanSeasonsTest.php`, `RatePlanSeasonsControllerTest.php`), 223 tests en total. Verificado en vivo contra motellacupula: temporada de prueba con precio $5000 reflejada correctamente en `/api/booking/availability` (`price_breakdown` incluido), limpiada después.

**Bug real encontrado y corregido durante la implementación**: `now()` en esta app resuelve a `Carbon\CarbonImmutable`, no al `Carbon` mutable de costumbre — un `$cursor->addDay();` suelto (sin reasignar el resultado) es un no-op silencioso. El primer intento de `priceFor()`/`priceBreakdown()` noche-por-noche se quedaba con el cursor pegado en la primera noche, aplicando la temporada a TODAS las noches o a NINGUNA según si esa primera noche caía dentro del rango de la temporada — solo lo atrapó un test que mezclaba noches base y de temporada en la misma estancia (los casos con la temporada cubriendo el 100% o el 0% del rango pasaban igual, por eso no fue obvio de inmediato). Fix: `$cursor = $cursor->addDay();` en los dos lugares que lo necesitaban.

---

## 12. Extras y experiencias (módulos opcionales por tenant)

En realdelasierra también se reservan RECORRIDOS (tours CANAM, hoy vía
BookingPress) y el wizard del plugin tiene paso de "extras"; motellacupula
vende decoraciones/servicios sobre la habitación. Bajo la palabra "extras"
viven DOS conceptos distintos — separarlos es lo que ordena el diseño:

### 12.1 Extras de reserva (add-ons) — módulo chico, entra pronto

Cosas que se AGREGAN a una reserva de habitación: decoración romántica,
desayuno, cama extra, late checkout. No tienen calendario propio ni
capacidad: viven pegadas a la reserva.

- Modelo: catálogo `extras` del tenant (nombre, precio, descripción,
  activo) + `reservation_extras` (qué extras lleva cada reserva). El extra
  **suma al total de la reserva** ANTES de emitir cobros: el anticipo % y
  el saldo lo incluyen solos (cero cambios en spec-pagos — el total ya es
  la fuente de los montos).
- Dónde se ofrecen: paso "Extras" del wizard (paridad con tu plugin), el
  panel al crear/editar reserva, y el bot (`P2`, tool `agregar_extra` con
  confirmación del huésped).
- No tocan el plano ni la disponibilidad: son dinero, no ocupación.

### 12.2 Experiencias (recorridos/tours) — módulo aparte, más grande

Reservables POR SÍ SOLOS, con horario y cupo: recorridos, tours, spa. Es
otro motor (sesiones + capacidad por sesión), NO el de habitaciones:

- Modelo (borrador): `experiences` (nombre, precio por persona, duración,
  contenido) + `experience_sessions` (fecha/hora, cupo) +
  `experience_bookings` (huésped, personas, total, estado).
- **Ajenas a las habitaciones a propósito**: no aparecen en el plano ni
  bloquean habitaciones; tienen su propio calendario de sesiones y su
  propia página en el wizard alojado (`/reservar/experiencias`).
- **Reutilizan la caja completa**: cobros via `payment_requests` (requiere
  generalizar la solicitud de cobro para que apunte a reserva O a
  experiencia — cambio acotado), mismas pasarelas, misma verificación de
  transferencias, misma bandeja/bot para venderlas por chat.
- Mientras no exista: **BookingPress se queda para los tours** sin
  conflicto — los tours no compiten con el motor de habitaciones (no hay
  riesgo de doble venta entre sistemas distintos que venden cosas
  distintas). Se migra cuando el módulo esté listo, no antes.

### 12.3 Habilitación por tenant (tu requisito)

Ambos módulos son **opcionales por hotel**: tabla central de módulos por
tenant (patrón `payment_method_settings`: interruptor global de plataforma
+ override por tenant, sin fila = apagado para módulos opt-in). Apagado =
no aparece en el menú, ni en el wizard, ni en las tools del bot. El plan
puede acotarlo además (patrón `max_gateways`): p. ej. experiencias solo en
Pro. El motel que solo renta habitaciones jamás ve nada de esto.

## 13. Preguntas para aterrizar antes de E0

1. **¿El wizard muestra precios "desde" en el paso 1 o solo tras elegir
   fechas?** Recomendado: solo con fechas (el precio real depende del rango
   y evita malentendidos con temporadas futuras). **Decidido e implementado
   así**: el paso 1 pide fechas/hora antes de mostrar ninguna tarjeta.
2. **¿Reservas de tarifas por bloque (ratos/horas) también en el wizard o
   solo por noche?** Recomendado originalmente: solo noche/estancia v1.
   **Revisado con datos reales al implementar E0**: el catálogo completo de
   motellacupula (el piloto) es 100% por bloque de 12 h — cero tarifas
   `night`. Restringir a "solo noche" habría dejado al piloto sin ninguna
   opción reservable. **Decisión final: wizard de doble modalidad desde
   v1** — "Por rato/bloque" (una fecha+hora de llegada; la salida la deriva
   el servidor con `suggestedEnd()`) y "Por noche(s)" (llegada+salida,
   ancladas a 15:00/12:00). Un tipo de habitación solo aparece en la
   modalidad para la que tiene tarifa activa; si el hotel es 100% motel,
   la pestaña "Por noche" simplemente sale vacía con su propio mensaje.
3. **¿El wizard pide pago siempre que la tarifa tenga anticipo, o el hotel
   puede permitir "reservar sin pagar" en web?** Recomendado: respetar la
   tarifa (misma regla que el bot) — coherencia total. **Implementado**:
   `requiresPrepayment()` de la tarifa decide; si es `false` el wizard
   NUNCA llama a pedir cobro (llamar a `IssuePaymentRequest` sin anticipo
   configurado pediría el TOTAL por error de interpretación — el
   controlador lo rechaza con 422 explícito como salvaguarda).
4. **Gating por plan**: ¿página alojada en todos los planes y
   embed/plugin/API solo en Pro? (patrón `max_gateways`). **Resuelto por
   el sistema de módulos** (spec-plan-maestro E1): todo el wizard vive
   detrás del módulo `motor-web`, que el admin habilita por plan o fuerza
   por hotel — no es un `if` de plan ad hoc.
5. **¿Multi-idioma del wizard (ES/EN) desde v1?** Los extranjeros con
   PayPal lo agradecerían; costo moderado. **v1 quedó solo en español**;
   sigue como mejora futura, no bloqueó el lanzamiento.
6. **Granularidad del mapeo WP↔Kuira**: en realdelasierra cada cabaña es
   reservable individualmente. En Kuira, ¿cada cabaña = un `room_type` con
   1 habitación (mapeo directo, recomendado para cabañas), o cabañas
   agrupadas por tipo con N habitaciones? Decidirlo antes de migrar tarifas.
7. **Cupones**: `hotel-rooms` los tiene; Kuira no. ¿Se sacrifican en el
   modo conectado v1 y entran como "códigos promo" en E4, o son
   imprescindibles para realdelasierra desde el día uno?
8. **Reservas grupales y tours CANAM del plugin**: quedan FUERA de este
   spec (siguen standalone en WP). ¿De acuerdo, o los tours también
   deberían vivir en Kuira algún día?
