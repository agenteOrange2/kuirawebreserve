# Flujo del bot con los 4 métodos de pago — cómo funciona hoy

> Recorrido de punta a punta tal como quedó implementado (F0-F4 de
> `spec-pagos.md`), suponiendo un hotel con TODO configurado: cuentas
> bancarias para transferencia + Stripe + Mercado Pago + PayPal conectados.
> Sirve para validar si el comportamiento es el deseado; al final está la
> lista de decisiones que puedes querer cambiar.

---

## 0. Lo que el sistema revisa antes de ofrecer cualquier cobro

Cuando el bot va a cobrar, el servidor (no el LLM) decide el método con
estas reglas, en orden:

1. **Interruptores del admin de plataforma** (`/admin/payments`): un método
   apagado global o para ese hotel NO existe — ni el bot ni el panel lo
   ofrecen, aunque esté conectado.
2. **Pasarela conectada y activa**: de las pasarelas vivas del hotel
   (Stripe, Mercado Pago, PayPal) toma **la primera que se conectó** (orden
   de alta). El huésped NO elige entre pasarelas: recibe UN link.
3. **Transferencia como respaldo**: si no hay pasarela viva (o la pasarela
   falla al generar el checkout en ese momento), el cobro sale con las
   cuentas bancarias de `/ajustes`. Si tampoco hay cuentas, el bot no
   promete cobro: dice que recepción confirmará el apartado.

> Dato importante: dentro del checkout de la pasarela el huésped SÍ ve
> varias formas de pagar (tarjetas, OXXO/efectivo en MP, meses sin
> intereses…) según lo que el hotel tenga activado en su cuenta del
> proveedor. Lo que no elige es "¿Stripe o Mercado Pago?" — eso lo decide
> el orden de conexión.

---

## 1. Conversación tipo (tarifa con anticipo del 20%)

**Huésped**: "Hola, ¿tienen habitación para el 15 de julio?"

1. El bot consulta tarifas y disponibilidad (`consultar_tarifas`,
   `consultar_disponibilidad`). Los precios y el TOTAL los calcula el
   servidor; el bot solo los repite con las etiquetas exactas.
2. **Bot**: "Sí, tenemos la Suite a $500 por noche. Para el 15 al 17 de
   julio serían $1,000 en total. ¿Te la aparto? Me confirmas tu nombre
   completo."
3. Huésped confirma → `crear_apartado` crea la reserva **Pendiente** con
   hold de 30 minutos. Como la tarifa exige anticipo, la respuesta interna
   le dice al bot: "se confirma al recibir el pago; usa solicitar_pago".
4. El bot llama `solicitar_pago(RES-2026-0042)` — solo pasa el código,
   **jamás montos**. El servidor:
   - Resuelve qué toca cobrar: anticipo de $200 (20% de $1,000).
   - Crea la solicitud de cobro (`payment_request`) y **extiende el hold**
     para que no muera antes que el cobro: 2 horas con link de pasarela,
     24 horas con transferencia.
   - Crea el checkout en la pasarela elegida y devuelve el link, o las
     cuentas bancarias si el método es transferencia.

**Caso A — hay pasarela (Stripe/MP/PayPal):**

> **Bot**: "Para confirmar tu reserva RES-2026-0042 paga tu anticipo de
> $200.00 MXN en este link seguro: https://checkout.stripe.com/…
> Tu apartado queda guardado 2 horas."

**Caso B — solo transferencia:**

> **Bot**: "Para confirmar tu reserva paga tu anticipo de $200.00 MXN por
> transferencia: BBVA, titular Hotel Demo Centro, CLABE 0123…
> Cuando lo hagas, mándame por aquí tu comprobante; el equipo del hotel lo
> verificará. Tu apartado queda guardado 24 horas."

---

## 2. Qué pasa cuando el huésped paga (por método)

| Método | El huésped… | La confirmación llega por… | ¿Interviene un humano? |
|---|---|---|---|
| **Stripe** | Paga en el checkout hospedado (tarjeta, OXXO si está activo) | Webhook firmado (`Stripe-Signature` validada) | No |
| **Mercado Pago** | Paga en Checkout Pro (tarjeta, efectivo, MSI) | Webhook + re-consulta a la API de MP (la re-consulta es la verdad) | No |
| **PayPal** | Aprueba en PayPal y regresa a nuestra página `/pago/{uuid}` | Captura server-to-server al regresar; el webhook es respaldo | No |
| **Transferencia** | Transfiere y manda el comprobante por el chat | El staff **Aprueba** en la cola "Pagos por verificar" de la bandeja | **Sí, siempre** |

En los cuatro casos el cierre es EL MISMO camino (`RegisterGatewayPayment`):

1. El pago se registra en el libro contable (`payments`, método `online` o
   `transfer`; con la comisión del proveedor si la reporta). No entra al
   corte de caja de ningún encargado.
2. El estado de pago de la reserva se re-deriva solo: Sin pagar → Anticipo
   pagado → Pagada.
3. Si el pago cubre el anticipo y el hotel tiene activo "confirmar
   automáticamente" (default: sí), la reserva pasa a **Confirmada** —
   re-checando disponibilidad antes, nunca a ciegas.
4. El huésped recibe el aviso por su mismo canal: *"Recibimos tu pago de
   $200.00 MXN (Anticipo). Tu reserva RES-2026-0042 está confirmada. Te
   esperamos."* Y el hilo de la bandeja registra el evento.
5. Idempotencia: si el proveedor reintenta el webhook (lo hacen), el
   evento se deduplica y el dinero NO se registra dos veces.

---

## 3. Lo que el bot tiene PROHIBIDO (y qué hace en su lugar)

- **Nunca declara un pago como recibido.** Si el huésped dice "ya pagué",
  el bot usa `consultar_reserva` y responde con lo que el sistema diga. Si
  el sistema no lo refleja y el huésped insiste, transfiere a humano.
- **Nunca inventa ni negocia montos.** Los montos van del servidor al chat
  con etiqueta exacta ($200.00 MXN); el LLM no puede alterarlos porque no
  participa en el cálculo.
- **Nunca acepta datos de tarjeta por el chat.** Si el huésped los manda,
  le pide borrarlos y lo dirige al link (el cobro ocurre en la página del
  proveedor; nosotros no vemos tarjetas — PCI SAQ-A).
- **Con un comprobante de transferencia dice "queda en verificación"**,
  jamás "recibido".
- En **modo copiloto**, el bot ni aparta ni emite cobros: redacta y un
  humano aprueba.
- **Reembolsos**: el bot no puede tocarlos; son 100% del panel (F4).

---

## 4. Si el huésped NO paga

1. **El link vence** (2 h pasarela / 24 h transferencia): la solicitud se
   marca vencida (scheduler cada 5 min) y el hold expira → la reserva se
   cancela sola y la habitación se libera.
2. El follow-up existente le avisa: *"Tu apartado venció y la habitación se
   liberó. Si aún te interesa, dime y hacemos uno nuevo."* Si vuelve, el
   ciclo reinicia (nuevo hold, nuevo cobro — nunca se reusa un link viejo).
3. **Si el pago llega tarde** (típico OXXO o SPEI nocturno, con la reserva
   ya cancelada): el dinero SIEMPRE se registra; si la habitación sigue
   libre, la reserva **revive y se confirma sola**; si ya se vendió, queda
   alerta "requiere atención" para reubicar o reembolsar. Nada se resuelve
   en silencio.

---

## 5. El saldo restante (los $800)

Configurado por tarifa:

- **"Liquidar 1 semana antes de la llegada"**: el scheduler
  (`payments:collect-balance`, cada hora) emite el cobro del saldo N días
  antes (default 3, editable), lo manda por el mismo chat con link o
  cuentas, recuerda 24 h antes de vencer, y si vence alerta al staff en la
  franja "Saldos vencidos" de la bandeja. Cancelar por impago es opcional y
  viene APAGADO.
- **Sin fecha límite**: el saldo se cobra a la llegada en el check-in /
  check-out con el folio (efectivo/tarjeta de mostrador — eso sí entra al
  corte del encargado).
- El staff también puede cobrarlo manualmente en cualquier momento:
  "Generar cobro en línea" en el modal de pagos de la reserva usa
  exactamente el mismo mecanismo del bot.

---

## 6. Cancelaciones y reembolsos (F4)

1. Si la tarifa define política ("sin costo hasta 2 días antes; después se
   retiene 50%"), al abrir el modal de pagos el staff ve la **sugerencia**:
   "correspondería reembolsar $X si se cancela ahora".
2. El staff decide monto y motivo. El dinero regresa **por donde entró**:
   Stripe/MP/PayPal vía API del proveedor; transferencias/efectivo es
   devolución manual que solo se registra.
3. El huésped recibe el aviso por su canal. El bot no participa.

---

## 7. Puntos a validar (decisiones tomadas que puedes querer cambiar)

1. **"La primera pasarela conectada gana."** Con Stripe + MP + PayPal
   conectados, todos los links salen de la que se dio de alta primero (las
   otras solo cobrarían si esa se desactiva). Alternativas posibles: un
   selector "pasarela preferida" en /ajustes, o mandar al huésped a una
   página nuestra donde él elija. Hoy NO es así.
2. **Vigencias**: 2 h el link de pasarela, 24 h la transferencia, y el
   saldo por transferencia vive hasta su fecha límite. ¿Se ajustan a tu
   operación?
3. **Auto-confirmar al cubrir el anticipo** viene ENCENDIDO por default
   (apagable por hotel). ¿Correcto para tus pilotos?
4. **Impago del saldo NO cancela** por default (solo alerta). ¿Correcto?
5. **El plan Básico no incluye pasarelas** (`max_gateways = 0`): un hotel
   Básico solo cobra por transferencia verificada. ¿Va con tu estrategia
   comercial?
6. **Comprobantes de transferencia**: hoy el huésped manda la foto por el
   chat y el staff la ve en la conversación; la solicitud NO adjunta la
   imagen (los medios entrantes de WhatsApp siguen pendientes, P2 de
   canales). El staff verifica contra su banca de todos modos.
