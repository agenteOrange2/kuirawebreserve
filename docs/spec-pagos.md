# Spec — Cobros de reservas y pasarelas de pago por tenant

> Análisis y diseño del módulo de pagos en línea (jul 2026). Responde a la pregunta:
> **¿cómo cobramos las reservas que vende el bot (o el staff) de forma automática,
> segura y por hotel?** — pago completo, anticipo + saldo antes de llegar, saldo a la
> llegada, y transferencias verificadas por humano.
> Extiende `estructura/spec-reservas-multitenant.md` §14 (fase 7 — pagos),
> `estructura/spec-modulos-profundidad.md` §7.5 (área de pagos) y
> `docs/spec-pendientes-y-agentes.md` (§2.6 cancelación con dinero, §4 agentes).

**Prioridades:** `P0` = núcleo del módulo, sin esto no hay cobro en línea ·
`P1` = necesario para operar bien en producción · `P2` = madurez.

---

## 1. El problema en una frase

Hoy el sistema **registra** pagos que ocurrieron por fuera (efectivo, tarjeta física,
transferencia), pero **no cobra**: el bot crea apartados que expiran en 30 minutos y
la confirmación depende de que un humano verifique dinero a mano. Falta el puente
entre "apartado creado" y "dinero confirmado" — y ese puente debe funcionar solo
(pasarela + webhook) o con verificación humana (transferencia + comprobante), según
lo que cada hotel tenga configurado.

---

## 2. Qué ya existe (y se reutiliza, no se duplica)

| Pieza | Dónde | Qué aporta al módulo de pagos |
|---|---|---|
| `Payment` (append-only, sin `updated_at`) | `app/Models/Payment.php` | Libro contable de dinero **confirmado**. Métodos `cash/card/transfer`, `kind lodging/consumption`, `received_by`, `paid_at`. |
| `RegisterReservationPayment` | `app/Actions/Reservations/` | Action canónica: lock pesimista, valida monto > 0 y <= pendiente, rechaza canceladas, sincroniza estado. |
| `Reservation.payment_status` | enum `Unpaid / DepositPaid / Paid` | Estado derivado por `syncPaymentStatus()` (nunca a mano). `pendingBalance()`, `isPaymentOverdue()`. |
| Política de anticipo por tarifa | `RatePlan.deposit_percent`, `payment_due_unit/value` | `requiresPrepayment()`, `depositAmountFor($total)`, `paymentDueAt($start)` — **el vocabulario de políticas ya existe**. |
| Holds con expiración | `Reservation Pending + hold_expires_at` (30 min, `config/reservations.php`) + `reservations:expire-holds` | El apartado del bot ya bloquea disponibilidad y se limpia solo. |
| Confirmación segura | `TransitionReservation::confirm()` | Re-checa disponibilidad antes de confirmar — el punto exacto donde encaja "confirmar al recibir pago". |
| Saldo a la llegada | `SettleStay` (folio de check-out) | El escenario "paga al llegar" **ya está resuelto**: hospedaje + consumos se liquidan en el folio. |
| Webhook central → tenant | `EvolutionWebhookController` (token en URL) y `MetaWebhookController` (firma HMAC) | Patrón probado: tabla central de vínculos → `Tenant::find()->run()` → procesar en la DB del tenant, con dedupe por id externo. |
| Credenciales cifradas | cast `encrypted` (`AiProvider.api_key`, `Channel.credentials`, `*ChannelLink`) + `maskedKey()` + preservar-si-vacío | Mismo patrón para las llaves de pasarela. |
| Mensajería saliente por canal | `OutboundMessenger::pushToConversation()` | Punto único para mandar el link de pago o la confirmación por WhatsApp/webchat. |
| Scheduler multitenant + Redis/Horizon | `routes/console.php` (`tenants:run …`), `QUEUE_CONNECTION=redis` | Infra lista para recordatorios de saldo y expiración de links. |
| Gating por plan | `Tenant::planLimit()` (patrón `max_channels`) | Mismo mecanismo para gatear pasarelas por plan. |
| Cortes de venta | `CashCutService`, `Payment.received_by` | Define qué entra al arqueo del encargado — los cobros en línea **no** deben contaminarlo. |

Conclusión: **no hay que rediseñar reservas ni pagos manuales**. Hay que añadir la
entidad "solicitud de cobro", los adapters de pasarela, el webhook central y las
herramientas del bot.

---

## 3. Decisiones de arquitectura (las preguntas grandes)

### 3.1 Cuentas propias del hotel, no cuenta de plataforma

Cada tenant conecta **sus propias cuentas** (Stripe / Mercado Pago / PayPal con sus
llaves API). El dinero va directo del huésped al hotel.

- A favor: cero manejo de fondos por nuestra parte (sin ser agregador/comisionista,
  sin obligaciones regulatorias), disputa/contracargo es del hotel con su pasarela,
  onboarding simple (pegar llaves, como ya hacen con Evolution o BYOK de IA).
- En contra: no podemos cobrar comisión por transacción automáticamente (se cobra
  vía plan, como hoy) y el hotel debe abrir su cuenta en la pasarela.
- **Stripe Connect / MP marketplace quedan como evolución futura** si algún día se
  quiere revenue-share por transacción; el diseño con adapters no lo bloquea.

### 3.2 Checkout alojado siempre; jamás tocamos datos de tarjeta

Solo **checkout hospedado por la pasarela** (Stripe Checkout, Mercado Pago Checkout
Pro, PayPal Orders). Nunca formulario de tarjeta propio, nunca números de tarjeta
por chat ni en nuestra DB. Eso nos mantiene en el nivel PCI más bajo (SAQ-A) y
elimina el riesgo #1. El bot recibe instrucción dura: si el huésped manda datos de
tarjeta, no los usa, pide borrarlos del chat y manda el link.

### 3.3 El LLM nunca decide que algo está pagado

Se conserva y refina la regla dura actual ("el bot nunca cobra ni confirma pagos"):

- El bot **sí puede emitir** una solicitud de cobro (el servidor calcula el monto;
  el LLM solo pasa el código de reserva — jamás cifras).
- "Pagado" solo lo declaran dos actores: un **webhook firmado** de la pasarela o un
  **humano del staff** (transferencias). El bot únicamente consulta y comunica ese
  estado.
- Ante "ya pagué" sin confirmación en sistema: el bot responde que está en
  verificación y, si el huésped insiste, escala a humano.

### 3.4 Webhooks entran por el dominio central, con firma del proveedor

Igual que Meta/Evolution: endpoint en `routes/webhooks.php`, tabla **central** de
vínculos pasarela↔tenant, y `$tenant->run()` para procesar en la DB del tenant.
Doble validación: token propio en la URL (enruta al tenant) **y** verificación de la
firma del proveedor (Stripe `Stripe-Signature`, MP `x-signature`, PayPal
verify-webhook-signature) con el secreto cifrado del vínculo. Idempotencia por
`event_id` del proveedor.

### 3.5 Una abstracción, N pasarelas

Interfaz única `PaymentGateway` con un adapter por proveedor. La entidad puente es
`PaymentRequest` (la "solicitud de cobro"): todo lo demás — bot, panel, scheduler,
webhook — habla con `PaymentRequest`, nunca con una pasarela concreta. La
transferencia manual es **el mismo objeto** con `method = transfer` (sin checkout,
con verificación humana); así el flujo de la conversación y el panel es uno solo.

```php
interface PaymentGateway
{
    public function createCheckout(PaymentRequest $request): CheckoutSession; // url, ref externo, expiración
    public function verifyWebhook(Request $request, GatewayLink $link): ?GatewayEvent; // valida firma, normaliza evento
    public function fetchStatus(PaymentRequest $request): GatewayStatus;      // consulta server-to-server (conciliación)
    public function refund(Payment $payment, float $amount): RefundResult;    // fase posterior
}
```

### 3.6 El dinero se calcula solo en el servidor

Montos siempre desde `Reservation` + `RatePlan` (`deposit_amount`,
`pendingBalance()`), en `decimal:2`, moneda de `Property.settings['currency']`
(default MXN). El checkout se crea con ese monto fijo; ningún monto viaja por el
chat ni por parámetros editables. Al LLM se le dan cifras en crudo y formateadas
(`{"monto": 200, "monto_label": "$200.00 MXN"}`) como ya dicta la nota técnica de
agentes.

---

## 4. Modelo de dominio

### 4.1 Nueva tabla tenant: `payment_requests` (la solicitud de cobro) `P0`

Una fila por intento de cobro. La reserva puede tener varias (anticipo, saldo,
reintentos), pero **solo una activa por concepto**.

```
payment_requests
  id
  uuid                    -- identificador público (links, webhooks, bot)
  reservation_id          -- FK cascade
  concept                 -- deposit | balance | full | custom
  amount decimal(10,2)    -- calculado server-side al emitir
  currency char(3)        -- copiada de settings al emitir
  method                  -- gateway | transfer
  provider  nullable      -- stripe | mercadopago | paypal (si method=gateway)
  mode                    -- test | live
  status                  -- pending | paid | expired | canceled | rejected
  checkout_url  nullable  -- URL del checkout hospedado
  gateway_ref   nullable  -- id externo (session / preference / order)
  expires_at    nullable  -- TTL del link
  requested_by  nullable  -- FK users (null = bot); auditoría
  payment_id    nullable  -- FK payments (el pago confirmado que la cerró)
  meta json               -- payload útil: comprobante, motivo de rechazo, etc.
  timestamps
  índices: (reservation_id, status) · unique (provider, gateway_ref)
```

Ciclo de vida:

```
pending ──(webhook aprobado / staff verifica)──> paid
pending ──(TTL vence / hold expira)────────────> expired
pending ──(staff cancela / reserva cancelada)──> canceled
pending ──(staff rechaza comprobante)──────────> rejected   (solo transfer)
```

Reglas:
- Emitir una nueva solicitud del mismo concepto **cancela la anterior pendiente**
  (y expira su checkout en la pasarela si el proveedor lo permite). Nunca dos links
  vivos por el mismo dinero.
- `paid` es terminal y siempre apunta a un `Payment`.
- Idempotencia para el bot: pedir "solicitar pago" de una reserva con solicitud
  `pending` vigente **devuelve la existente**, no crea otra (mismo espíritu que
  §4.2 de spec-pendientes).

### 4.2 Extensión mínima de `payments` `P0`

`Payment` sigue siendo el libro de dinero confirmado, append-only. Se añaden:

```
payment_request_id  nullable FK   -- de qué solicitud nació (null = mostrador)
gateway             nullable      -- stripe | mercadopago | paypal
gateway_ref         nullable      -- id de la transacción del proveedor
fee_amount          nullable      -- comisión reportada por la pasarela (conciliación)
```

- Nuevo método en `Payment::METHODS`: `online` (los actuales `cash/card/transfer`
  no cambian). `received_by = null` en pagos de pasarela (nadie los "recibió" en
  mostrador — clave para cortes, ver §9.4).
- Nueva action `RegisterGatewayPayment`: variante de `RegisterReservationPayment`
  que **no** rechaza por estado ni por exceder pendiente — si la pasarela dice que
  el dinero entró, se registra siempre (la verdad contable manda) y las anomalías
  (reserva cancelada, sobrepago) se marcan en `meta` y generan alerta (§6.4).

### 4.3 Nueva tabla tenant: `gateway_events` (idempotencia y auditoría) `P0`

```
gateway_events: id · provider · event_id · payment_request_id? · payload json · processed_at
unique (provider, event_id)
```

Las pasarelas reintentan webhooks; sin dedupe se duplican pagos. Además es la
bitácora para depurar "dice que pagó y no se reflejó".

### 4.4 Nueva tabla central: `payment_gateway_links` `P0`

Espejo del patrón `evolution_channel_links` — los webhooks llegan al dominio
central y hay que resolver el tenant sin levantar N tenants:

```
payment_gateway_links
  id · tenant_id · provider (stripe|mercadopago|paypal) · mode (test|live)
  public_key nullable            -- publishable key / client id
  secret_key  (cast encrypted)   -- secret key / access token
  webhook_secret (cast encrypted)-- signing secret del endpoint
  webhook_token unique           -- Str::random(48), va en la URL
  active · last_event_at (latido, como canales) · meta json
  unique (tenant_id, provider)
```

Ruta: `POST /webhooks/payments/{token}` → busca el link activo → verifica firma
del proveedor con `webhook_secret` → `Tenant::find($link->tenant_id)->run(...)`.
`last_event_at` alimenta el diagnóstico en admin (mismo latido que canales).

### 4.5 Políticas de cobro: `RatePlan` ya casi lo dice todo `P0`

El vocabulario existente cubre los tres escenarios del negocio **sin tabla nueva**:

| Escenario | Configuración de la tarifa | Comportamiento |
|---|---|---|
| Pago completo para confirmar | `deposit_percent = 100` | Una sola solicitud `full`; al pagarse → reserva `Paid` + confirmada. |
| Anticipo + saldo N días antes | `deposit_percent = 1..99`, `payment_due_unit/value` definidos | Solicitud `deposit` al apartar; el scheduler emite la de `balance` rumbo a `payment_due_at` (§7.2). |
| Anticipo + saldo a la llegada | `deposit_percent = 1..99`, `payment_due_*` = null | Solicitud `deposit`; el saldo lo liquida el folio en check-in/check-out (`SettleStay`, ya existe). |
| Sin cobro anticipado | `deposit_percent = 0` | Sin solicitudes automáticas; hold → confirmación manual del hotel (flujo actual). |

Ajustes menores:
- Hoy `deposit_percent` solo calcula números; falta el **efecto**: nueva regla
  "al cubrirse el anticipo → confirmar automáticamente" con interruptor por
  propiedad `settings['auto_confirm_on_payment']` (default `true`). El hotel que
  quiera seguir confirmando a mano lo apaga.
- `P2` — `deposit_fixed_amount` (anticipo fijo en pesos además del %) si algún
  hotel lo pide; el spec lo deja previsto, no se construye ahora.
- Un plan de N mensualidades **no se modela** (los hoteles reales cobran 1–2
  momentos); si llegara a necesitarse, `payment_requests` con `concept = custom`
  ya lo soporta emitiendo solicitudes sueltas desde el panel.

### 4.6 Cuentas bancarias del hotel (transferencias) `P0`

Lo pendiente de spec-modulos-profundidad §7.5: lista de cuentas en settings de la
propiedad (banco, titular, CLABE/cuenta, activa). Vive en `Property.settings
['bank_accounts']` (JSON, editable en `/ajustes` sección Pagos). Es lo que el bot
entrega cuando el método es transferencia. Sin cuentas capturadas, el bot no ofrece
transferencia.

---

## 5. Estados: cómo interactúan reserva, solicitud y pago

`Reservation.payment_status` **no cambia** (`Unpaid / DepositPaid / Paid`, derivado
de la suma de `payments` por `syncPaymentStatus()`); "vencido" sigue siendo derivado
(`isPaymentOverdue()`). Lo nuevo es la capa de solicitudes encima:

```
  Huésped                Sistema                                  Hotel
────────────────────────────────────────────────────────────────────────────
  pide reservar ──> hold Pending (30 min)
                    └─ política requiere prepago:
                       emite PaymentRequest(deposit|full)
                       extiende hold hasta expirar el link (§6.1)
  paga el link ───> webhook firmado → gateway_events (dedupe)
                    → RegisterGatewayPayment (Payment method=online)
                    → syncPaymentStatus (DepositPaid|Paid)
                    → anticipo cubierto + auto_confirm → confirm()   ── campana
                    → mensaje al huésped por su canal ("confirmada, código X")
  no paga ────────> link expira → request expired → hold expira →
                    reservations:expire-holds cancela (flujo actual)
```

Invariantes:
- `payment_status` jamás se escribe a mano; solo `syncPaymentStatus()`.
- Una solicitud `paid` sin su `Payment` es un bug (transacción DB envuelve ambos).
- La confirmación siempre pasa por `TransitionReservation::confirm()` (que re-checa
  disponibilidad) — nunca un `update(status)` directo desde el webhook.

---

## 6. Los casos difíciles (y su resolución explícita)

### 6.1 El hold dura 30 min pero la gente no paga en 30 min `P0`

Al emitir una solicitud de prepago se **extiende el hold** hasta la expiración del
link: nueva config `reservations.payment_hold_minutes` (sugerido **120 min**,
ajustable por env). Trade-off asumido: la habitación queda bloqueada más tiempo,
pero un hold que muere antes que su link genera pagos huérfanos (peor). El bot
avisa la vigencia ("tu apartado queda guardado 2 horas mientras realizas el pago").
Para transferencias, la extensión es mayor (sugerido 24 h) porque hay banco de por
medio — configurable por hotel en settings.

### 6.2 El pago llega cuando el hold ya expiró `P0`

El webhook puede llegar tarde (OXXO, transferencia SPEI de la pasarela, retraso de
red). Regla:

1. El dinero **siempre se registra** (`RegisterGatewayPayment`).
2. Si la reserva está `Cancelled` por expiración: se re-checa disponibilidad con
   `AvailabilityService`; si la habitación (u otra del mismo tipo) sigue libre →
   se **revive y confirma** la reserva (log en auditoría).
3. Si ya no hay disponibilidad → la solicitud queda `paid` con
   `meta.requires_attention = true`, alerta al staff (bandeja + campana): reubicar
   al huésped o reembolsar. **Nunca** se resuelve en silencio ni lo decide el bot.

### 6.3 Doble pago / doble link `P0`

- Prevención: una sola solicitud `pending` por concepto (§4.1); emitir nueva
  cancela la anterior.
- Si aun así entra un segundo pago (webhook del link viejo ya pagado en paralelo):
  se registra, la reserva queda con sobrepago visible (`paidTotal > total`), alerta
  `requires_attention` para reembolsar la diferencia. El dedupe de `gateway_events`
  evita el caso más común (mismo evento reintentado).

### 6.4 Cambia el total de la reserva con un link vivo `P1`

Editar fechas/tarifa de una reserva con solicitud `pending` → la solicitud se
cancela automáticamente y se emite una nueva con el monto recalculado. Un link
jamás puede cobrar un monto que ya no corresponde.

### 6.5 El hotel desconecta la pasarela con links vivos `P1`

Desactivar un `payment_gateway_link` cancela sus solicitudes `pending` y avisa
cuántas fueron. El webhook de un link desactivado responde 200 (para que el
proveedor no reintente eternamente) pero registra el evento como huérfano y alerta.

### 6.6 Reembolsos y cancelación con dinero `P2` (diseño previsto, fase posterior)

Va de la mano de spec-pendientes §2.6 (política de cancelación). Diseño reservado:
tabla `refunds` (payment_id, amount, status, gateway_ref, reason, created_by) +
`PaymentGateway::refund()`. En v1 los reembolsos son manuales (el hotel lo hace en
el dashboard de su pasarela y lo registra como nota); la automatización llega con
la política de cancelación. Contracargos: siempre manuales, solo alerta.

### 6.7 Fuera de alcance (explícito)

- **Facturación CFDI/SAT**: no es de este módulo (nota para un spec futuro).
- **Cobro de los planes SaaS al hotel** (fase 7 original): otro problema, otra
  cuenta (la de plataforma) — no mezclar con esta infraestructura de tenants.
- **Meses sin intereses / OXXO**: los soporta la pasarela en su checkout si el
  hotel los activa en su cuenta; nosotros solo mostramos el resultado (los métodos
  asíncronos tipo OXXO ya quedan cubiertos por el flujo de webhook diferido §6.2).

---

## 7. Flujos end-to-end

### 7.1 Bot vende con pago completo o anticipo (automático) `P0`

1. Conversación normal: cotiza (`consultar_disponibilidad`) → huésped acepta →
   `crear_apartado` (hold, flujo actual).
2. Si la tarifa requiere prepago, el bot llama la **nueva tool `solicitar_pago`**
   (solo pasa el código de reserva). El servidor decide concepto y monto, crea el
   checkout en la pasarela activa del hotel y devuelve `{url, monto_label,
   vigencia}`; el bot lo comunica: "Para confirmar tu reserva paga tu anticipo de
   $200.00 MXN aquí: <link>. Queda guardada 2 horas."
3. Huésped paga en el checkout hospedado → webhook → pago registrado → reserva
   confirmada → **mensaje proactivo por el mismo canal** vía `OutboundMessenger`:
   "Listo, recibimos tu pago. Tu reserva RES-0042 está confirmada." + campana en
   bandeja + evento en el hilo (mensaje `system`).
4. "¿Ya se reflejó mi pago?" → `consultar_reserva` (ampliada con estado de pago)
   responde con la verdad del sistema, nunca con suposición del LLM.

### 7.2 Anticipo pagado, saldo una semana antes `P0`

1. Reserva `Confirmed` + `DepositPaid`, `payment_due_at` ya calculado por la tarifa
   (existente).
2. Nuevo command `payments:collect-balance` (`tenants:run`, cada hora): reservas
   confirmadas con saldo cuya `payment_due_at` está a ≤ N días (config por hotel,
   default 3) y sin solicitud `balance` viva → emite la solicitud y manda el link
   por el canal de la conversación de origen (o email cuando exista §2.4 de
   pendientes). Sin conversación ligada → tarea para el staff en bandeja.
3. Recordatorio a las 24 h de vencer y aviso al staff al vencer (`isPaymentOverdue`
   ya lo detecta; hoy nadie lo escucha — este command es quien lo escucha).
4. **Impago del saldo NO cancela automáticamente** (default): genera alerta y el
   hotel decide (política por hotel `settings['cancel_on_balance_overdue']`,
   default `false`). Cancelar dinero en automático sin humano es la clase de
   sorpresa que cuesta clientes.

### 7.3 Anticipo pagado, saldo a la llegada `P0` (ya casi todo existe)

`payment_due_*` null → no se emite solicitud de saldo. El panel ya muestra saldo
pendiente y el folio de check-out (`SettleStay`) ya cobra hospedaje + consumos.
Único cambio: el modal de check-in muestra el saldo por cobrar como aviso.

### 7.4 Transferencia bancaria (humano en el loop) `P0`

1. Hotel sin pasarela (o huésped que la pide): `solicitar_pago` con
   `method = transfer` devuelve las cuentas bancarias (§4.6) y crea la solicitud
   `pending` con vigencia amplia (24 h). El bot pide el comprobante: "En cuanto
   tengas tu comprobante mándalo por aquí."
2. Comprobante llega (imagen) → se adjunta a la solicitud (`meta`), la conversación
   pasa a `pending` (espera humano) con etiqueta "verificar pago" — cae en la cola
   de verificación de la bandeja.
3. Staff verifica en su banca → botón **Aprobar** (registra `Payment
   method=transfer` con referencia, vía la solicitud → confirma reserva → mensaje
   automático al huésped) o **Rechazar** (motivo → el bot/staff lo comunica).
4. Guardrail de prompt: el bot **nunca** dice "pago recibido" por ver un
   comprobante; dice "lo pasamos a verificación, te confirmamos en breve".

### 7.5 Staff cobra desde el panel `P1`

En el detalle de reserva: botón "Generar link de cobro" (elige concepto si hay
ambigüedad) → copiar/enviar por el canal ligado. Mismo `PaymentRequest`, mismo
webhook. El registro manual de pagos de mostrador (existente) no cambia.

---

## 8. Las pasarelas: notas por proveedor

| | Mercado Pago | Stripe | PayPal |
|---|---|---|---|
| Producto | Checkout Pro (preference) | Checkout Session | Orders v2 |
| Referencia nuestra | `external_reference = uuid` | `client_reference_id = uuid` + metadata | `custom_id = uuid` |
| Webhook clave | topic `payment` (+ consulta `GET /v1/payments/{id}` como fuente de verdad) | `checkout.session.completed` / `.expired` / `.async_payment_*` | `PAYMENT.CAPTURE.COMPLETED` |
| Verificación | header `x-signature` (HMAC) + re-consulta server-to-server | header `Stripe-Signature` (HMAC, signing secret) | API `verify-webhook-signature` |
| Expiración del link | `expiration_date_to` en la preference | `expires_at` (30 min–24 h) | según orden |
| Relevancia MX | La más usada por huéspedes MX; efectivo/OXXO y MSI en su checkout | API más limpia; OXXO disponible; ideal test-mode | Menor en MX; útil para extranjeros |
| Comisión aprox.* | ~3.5% + IVA (según liberación) | ~3.6% + $3 MXN | ~3.95% + fija |

\* Indicativas a jul 2026 — **verificar al implementar**; las paga el hotel en su
cuenta, a nosotros solo nos importa `fee_amount` para conciliación si el proveedor
la reporta.

**Orden recomendado: Mercado Pago → Stripe → PayPal.** Mercado Pago por adopción
del huésped mexicano (el que paga es el huésped, no el hotel); Stripe inmediatamente
después porque su modo test acelera el desarrollo del resto del módulo (de hecho
conviene desarrollar el core contra Stripe test y estrenar MP en paralelo). PayPal
al final: aporta huéspedes extranjeros, no urgencia.

Particularidad MP a respetar: el webhook trae el id del pago pero el estado
confiable se obtiene **re-consultando la API** — el adapter siempre confirma
server-to-server antes de registrar (esto además nos regala `fetchStatus()` para
conciliación de los tres proveedores).

---

## 9. Panel y UX

### 9.1 `/ajustes` → sección Pagos (o página `/pagos`) `P0`

- **Pasarelas**: una tarjeta por proveedor (conectada/desconectada), llaves con
  `maskedKey()` y preservar-si-vacío, selector test/live, URL de webhook visible
  para pegar en el dashboard del proveedor, botón "Probar conexión" (patrón
  `AiProvider::test`), latido `last_event_at`.
- **Cuentas bancarias**: lista CRUD (banco, titular, CLABE, activa).
- **Política**: auto-confirmar al pagar (switch), días de anticipación para pedir
  saldo, extensión de hold para transferencias, cancelar por saldo vencido (switch,
  default off).

### 9.2 Detalle de reserva `P0`

Línea de tiempo de cobros: solicitudes con estado/vigencia, pagos con método y
referencia, saldo. Acciones: generar/copiar/cancelar link, registrar pago manual
(existente), reenviar link por el canal ligado.

### 9.3 Bandeja `P0`

- Chip de estado de pago en conversaciones con reserva ligada (Sin pago / Anticipo
  / Pagada / Por verificar / Vencido).
- Cola "verificar pagos": solicitudes de transferencia con comprobante, con
  Aprobar/Rechazar inline (§7.4).
- Mensajes `system` en el hilo cuando un pago entra o se confirma la reserva (la
  trazabilidad conversación↔dinero queda en el mismo lugar donde se vendió).

### 9.4 Cortes de venta: los cobros en línea NO entran al arqueo `P0`

Un pago `method = online` (`received_by = null`) no pasó por las manos de ningún
encargado: se **excluye del corte de caja** (efectivo esperado vs contado intacto)
y alimenta un reporte propio **"Cobros en línea"** (por periodo y proveedor, con
`fee_amount` cuando exista) para conciliar contra el dashboard de la pasarela. Las
transferencias verificadas ya no afectaban el efectivo; solo ganan visibilidad en
ese mismo reporte.

### 9.5 Admin central `P1`

En la ficha del tenant: pasarelas conectadas, modo, latido del webhook — espejo de
lo que ya se muestra para canales.

---

## 10. El bot: herramientas y prompt `P0`

Cambios en `AgentBrain::toolset()`:

- **Nueva tool `solicitar_pago(codigo_reserva)`**: el servidor resuelve el concepto
  pendiente (anticipo → saldo), reutiliza solicitud viva si existe (idempotente),
  crea el checkout o devuelve datos bancarios según configuración del hotel, y
  regresa `{metodo, url?, cuentas?, monto, monto_label, vigencia_label}`. Excluida
  en modo `readOnly`/copiloto igual que `crear_apartado` (en copiloto el link viaja
  dentro del mensaje que el humano aprueba).
- **`consultar_reserva` ampliada**: estado de pago, monto pendiente (crudo +
  label), solicitud viva con su vigencia.
- **System prompt** (adiciones a las reglas duras): nunca afirmar que un pago se
  recibió salvo que el sistema lo diga; nunca aceptar/leer datos de tarjeta (pedir
  que los borren del chat); transferencia = "queda en verificación"; ante reclamo
  de pago no reflejado dos veces → `transferir_a_humano`.
- **`crear_apartado`** ajusta su mensaje de cierre según la política de la tarifa:
  con prepago ya no dice "el hotel lo confirmará" sino "se confirma al recibir tu
  pago".
- Cuota/canal: emitir link cuenta como respuesta normal del bot (sin cuota
  aparte). Nota anti-ban Evolution: el playbook ya prohíbe links en los primeros
  mensajes de un número recién calentado — el link de pago respeta esa regla (solo
  en conversación establecida, iniciada por el huésped).

---

## 11. Seguridad (resumen de compromisos)

1. PCI SAQ-A: solo checkout hospedado; ni chat ni DB ven tarjetas (§3.2).
2. Webhook: token en URL + firma del proveedor + idempotencia `gateway_events` +
   verificación sobre el **raw body** (patrón `MetaWebhookController`).
3. Credenciales: cast `encrypted`, `maskedKey()`, preservar-si-vacío, jamás en
   logs.
4. Montos solo server-side; `uuid` no adivinable en todo lo público.
5. Auditoría: `activitylog` en solicitudes (quién emitió, qué webhook la pagó,
   quién verificó la transferencia). El pago huérfano/anómalo siempre alerta,
   nunca se auto-resuelve (§6.2, §6.3).
6. Rate-limit en el webhook central y respuesta 200 idempotente a reintentos.

---

## 12. Gating por plan `P1`

Patrón `max_channels`: columna nueva en `plans` central + `toConfigArray()` +
`planLimit()`. Propuesta:

| | Básico | Pro |
|---|---|---|
| Transferencias con verificación | Sí | Sí |
| Pasarelas conectadas (`max_gateways`) | 0 | 3 |
| Cobro automático de saldo (scheduler) | No | Sí |

(Transferencias en Básico porque no nos cuestan infra externa y hacen que el plan
chico ya "cobre"; las pasarelas y la automatización son el gancho del Pro. Ajustable
en la matriz de planes del admin, como todo lo demás.)

---

## 13. Roadmap de implementación

| Orden | Entregable | Contenido | Por qué en este orden |
|---|---|---|---|
| F0 `P0` | **Transferencias end-to-end** — *HECHO (jul 2026)* | `payment_requests` + extensión `payments` + `RegisterGatewayPayment` + cuentas bancarias en settings + cola de verificación en bandeja + tool `solicitar_pago` (solo transfer) + prompt. Además: `payments:expire-requests` en scheduler, extensión de hold (§6.1), revive/alerta (§6.2) y auto-confirmación (§4.5) quedaron desde F0. Tests en `PaymentRequestsTest`. | Valor inmediato sin dependencia externa; construye el 70% del dominio que las pasarelas reutilizan. |
| F1 `P0` | **Primera pasarela real** — *HECHO (jul 2026)* | Interface `PaymentGateway` + adapters Stripe y Mercado Pago (`app/Services/Payments/`) + `payment_gateway_links` central + webhook `GET/POST /webhooks/payments/{token}` (firma Stripe / re-consulta MP) + `gateway_events` (dedupe) + UI de conexión en /ajustes (llaves cifradas, probar conexión, latido, URL de webhook) + página pública `/pago/{uuid}` + el bot prefiere link con fallback a transferencia. Tests en `PaymentGatewaysTest`. | El corazón: cobro → webhook → confirmación automática. |
| F2 `P0` | **Saldos automáticos** — *HECHO (jul 2026)* | `payments:collect-balance` (hourly): emite el cobro N días antes (`balance_request_days`, default 3, editable en /ajustes), recuerda a las 24 h, y al vencer alerta en la franja "Saldos vencidos" de la bandeja (cancelación automática solo si el hotel activa `cancel_on_balance_overdue`, default off). Solicitudes de saldo por transferencia viven hasta la fecha límite. Reporte "Cobros en línea" en `/cobros-en-linea` (periodo + origen, comisiones, neto; cortes intactos) con entrada de menú. Tests en `PaymentBalanceCollectionTest`. | Cierra los escenarios anticipo+saldo; el dinero deja de depender de la memoria del staff. |
| F3 `P1` | **Operación fina** — *HECHO (jul 2026)* | Cobro desde el panel de reserva (botón "Generar cobro en línea" → link de pasarela o transferencia, copiar/cancelar; §7.5); `UpdateReservation` cancela el cobro pendiente al cambiar total/anticipo (§6.4); admin central `/admin/payments` (radiografía de pasarelas por tenant + interruptores de método por plataforma/hotel, §9.5); gating por plan `max_gateways` (Básico 0 = solo transferencias, Pro 3; enforcement en `PaymentGatewayController::store`, §12); **PayPal** (Orders v2, captura al retorno + webhook idempotente). Tests en `PaymentPanelAndPayPalTest`. | Robustez de producción. |
| F4 `P2` | **Dinero de vuelta y madurez** — *HECHO (jul 2026)* | Tabla `refunds` (append-only, ligada al pago) + política de cancelación por tarifa (`cancel_free_unit/value` + `cancel_penalty_percent` en el catálogo) con **sugerencia** de reembolso en el modal de pagos (la decisión siempre es humana); `PaymentGateway::refund()` en los 3 adapters (el dinero regresa por donde entró; `manual=true` = solo registro); aviso al huésped por su canal; embudo del periodo (solicitudes emitidas → pagadas → conversión) + total reembolsado en `/cobros-en-linea`. Tests en `PaymentRefundsTest`. Contracargos siguen manuales (§6.6). | Depende de decisiones de política de cada hotel. |

Encaje con el roadmap general de `spec-pendientes-y-agentes.md` §5: esto es la
continuación natural de los pasos 3–5 (Agent API + bandeja + notificaciones); F0
puede arrancar ya porque no depende de Meta ni de trámites externos. Los tests
siguen el patrón de `ReservationPaymentsTest`: cada fase trae los suyos (F1 simula
webhooks firmados; el caso §6.2 —pago tras expiración— es test obligatorio).

---

## 14. Decisiones que conviene confirmar antes de F1

1. **¿Mercado Pago y Stripe en F1, o uno solo?** Recomendado: desarrollar contra
   Stripe test y lanzar ambos (el adapter extra son días, no semanas).
2. **Extensión del hold al emitir link**: ¿120 min está bien como default de
   pasarela y 24 h para transferencia?
3. **Auto-confirmar al cubrir anticipo**: default `true` — ¿algún hotel piloto
   prefiere confirmar a mano?
4. **Impago de saldo**: confirmar que el default es alertar (no cancelar solo).
5. **Gating por plan**: validar la matriz del §12 con la estrategia comercial.
