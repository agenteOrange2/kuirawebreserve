# Spec — Wizard público: precio transparente, capacidad conectada y pasos que el hotel controla

> Nace de una prueba en vivo (2026-07-10) contra motellacupula que reveló
> que el wizard `/reservar` **mostraba un precio y cobraba otro**, el paso
> de Extras nunca aparecía pese a estar activado, y no hay forma de ver
> ni de controlar qué compone el total ni qué pasos existen. Dos de los
> hallazgos eran bugs de integridad de precio — ya corregidos y
> verificados en producción (§2). El resto es rediseño: transparencia de
> precio (§3), un solo modelo de capacidad (§4) y control explícito de
> pasos (§5). Complementa `spec-motor-reservas-web.md` (el wizard base) y
> `spec-modulos-y-precio-unico.md` (precio único: la tarifa manda).

**Prioridades:** `P0` = ya corregido/bloqueaba confianza en el cobro · `P1` = cierra la confusión reportada · `P2` = pulido.

---

## 1. Qué se reportó y causa raíz de cada punto

| # | Síntoma reportado | Causa raíz | Estado |
|---|---|---|---|
| 1 | El wizard mostró **$1,300** por la Habitación Sencilla con 2 personas, pero al reservar con 3 cobró **$1,950** sin avisar | `holds()` nunca revalidaba `adults + children` contra `RoomType.capacity` (solo lo hacía `availability()`); si el huésped cambiaba el número de personas **después** de elegir una habitación sin volver a buscar, la tarjeta seleccionada quedaba desactualizada pero el envío usaba el número nuevo | ✅ **P0 — corregido y verificado en producción hoy** (§2) |
| 2 | El paso "Extras" nunca aparece en `/reservar` aunque está activado en `/ajustes/wizard` con productos marcados | Los productos curados tenían `stock_qty = 0` (con `track_stock` activo); el catálogo público los excluye a propósito (no se ofrece algo que no hay), pero `/ajustes/wizard` no avisaba de eso — el hotel veía el interruptor en verde y dos productos "visibles" sin saber que ninguno realmente se mostraría | ✅ **P0 — corregido hoy** (aviso visual en la página de ajustes); pendiente que el hotel registre stock real, eso es operación suya |
| 3 | Ningún paso del wizard explica de qué se compone el precio (tarifa, persona extra, cargos opcionales, productos) | Diseño original: solo se calculaba y mostraba un total plano | 🔲 P1 — §3 |
| 4 | La ficha de habitación (`Ajuste de precio` / `Personas incluidas` / `Cobro por persona extra`) es confusa incluso para el propio hotelero | Un concepto de "capacidad para vender" (`RoomType.capacity`, tope duro de búsqueda) y otro de "capacidad con recargo" (`Room.included_occupancy` + `extra_guest_fee`) conviven sin estar conectados ni explicados; en motellacupula están configurados de forma **contradictoria** (capacidad del tipo = 2, incluidas = 2 → el recargo nunca puede aplicar porque no cabe una 3ª persona) | 🔲 P1 — §4 |
| 5 | "No veo la libertad de activar el paso de pago, el paso de inventario" | El paso de Extras sí es un toggle (`wizard_extras_enabled`); el paso de Pago es 100% inferido de `ratePlan.requiresPrepayment()`, sin ningún control ni resumen visible en `/ajustes/wizard` que le diga al hotel "así se ve tu wizard hoy" | 🔲 P1 — §5 |

---

## 2. Ya corregido (2026-07-10)

**Fix 1 — `holds()` exige la misma capacidad que ya filtró `availability()`.**
`BookingController::holds()` ahora rechaza con 422 (`"Esta habitación admite hasta N personas..."`) si `adults + children > RoomType.capacity`, exactamente el mismo criterio que ya usaba `availability()` para decidir qué tarjetas mostrar. Antes de esto, el endpoint de creación no tenía ningún tope — aceptaba cualquier número de personas para cualquier habitación. Verificado en producción: la reserva de 3 personas en un tipo con capacidad 2 ahora se rechaza en vez de cobrarse en silencio.

**Fix 2 — la lista de opciones ya no queda "cotizada" para un número de personas viejo.**
En `Wizard.vue`, cambiar adultos/niños después de buscar ahora limpia la lista de tarjetas y obliga a volver a consultar disponibilidad antes de poder elegir una — así ninguna tarjeta seleccionable puede mostrar un precio que ya no corresponde al número de personas actual.

**Fix 3 — `/ajustes/wizard` avisa cuando un producto curado no aparecerá.**
Cada producto de la lista de extras ahora muestra "Sin existencias" si tiene control de stock en cero, y hay un aviso agregado cuando hay productos marcados como visibles pero sin stock — para que el hotel entienda de inmediato por qué el guest no los ve, en vez de asumir que el feature está roto.

**Nota:** la reserva `RES-2026-0008` (creada por el usuario probando en vivo, cobrada de más por el bug 1) quedó en la base de motellacupula — no se tocó porque es dato real del hotel, no de prueba; el hotel decide si la cancela o la ajusta manualmente.

---

## 3. Transparencia de precio (P1)

Hoy `availability()` y `holds()` YA calculan el total correcto (tarifa + persona extra + cargos opcionales + productos) — el problema es que solo se entrega el número final, nunca las líneas. Propuesta: exponer el mismo desglose que ya arma `Room::extraChargeLines()` en dos puntos:

1. **Tarjeta de disponibilidad** (`availability()`): agregar `price_breakdown: [{concept, amount}]` a cada opción — la tarjeta muestra "Desde $1,300" y un texto secundario "2 personas incluidas" (o, si `adults` ya supera lo incluido, la línea de persona extra ya sumada y visible: "+ $650 por 1 persona extra").
2. **Confirmación del hold** (`holds()`): el payload ya separa `room_total` / `products` / `products_total` (implementado en la iteración anterior) — falta que `room_total` en sí venga desglosado en sus propias líneas (tarifa base, persona extra, cargos opcionales elegidos) en vez de un solo número, y que `Wizard.vue` las renderice en la pantalla de confirmación tal como ya hace con `products`.

Con esto, el motelero que se confundió probando ve, ANTES de pagar: "Tarifa (12h): $1,300 + Persona extra (1): $650 = $1,950" — no solo "$1,950".

---

## 4. Un solo modelo de capacidad (P1)

Hoy hay dos campos de capacidad que no se hablan entre sí:

- `RoomType.capacity`: tope duro — el wizard nunca ofrece ese tipo si `adults+children` lo supera (ahora también lo exige `holds()`, §2).
- `Room.included_occupancy` + `Room.extra_guest_fee`: "cuántas personas trae la tarifa incluidas" y "cuánto se cobra por cada una de más" — pero como el tope duro es `RoomType.capacity`, el recargo **solo puede aplicar si `included_occupancy < capacity`**. En motellacupula, la única habitación con recargo configurado (101, Sencilla) tiene `included_occupancy = 2` y `capacity = 2` — el recargo está configurado pero es matemáticamente inalcanzable con las reglas actuales.

**Propuesta:** en el editor de habitación/tipo, mostrar la relación en vivo en vez de tres campos sueltos:

- Renombrar "Personas incluidas" → "Personas incluidas en la tarifa" y "Cobro por persona extra" se mantiene, pero debajo se agrega una vista calculada: *"Con esta configuración: hasta {capacidad del tipo} personas en total, de las cuales {capacidad − incluidas} pueden ser 'extra' pagando {extra_fee} cada una."*
- Si `included_occupancy >= RoomType.capacity`, mostrar una advertencia inline: *"El recargo nunca se va a cobrar: el tipo admite un máximo de {capacity} y ya las incluyes todas. Si quieres cobrar por gente extra, sube la capacidad del tipo."* — con un atajo directo a editar la capacidad del tipo desde ahí mismo.
- "Ajuste de precio" (`price_modifier`) es un concepto DISTINTO (más caro/barato por vista, piso, etc., no por número de personas) — separar visualmente ambos bloques con un encabezado que lo diga explícito, en vez de que aparezcan uno tras otro sin distinción (causa real de la confusión reportada).

---

## 5. Pasos visibles y control explícito (P1)

`/ajustes/wizard` ya tiene un toggle real para Extras (`wizard_extras_enabled`). Lo que falta es que el hotel pueda **ver** su wizard actual de un vistazo y tener control real sobre el paso de pago:

1. **Vista previa de pasos**: agregar en `/ajustes/wizard` el mismo indicador de pasos que ve el huésped (Fechas → Tus datos → [Extras] → Confirmación/Pago), calculado con la config real del hotel — así "cuántos pasos tiene mi wizard" deja de ser una pregunta que solo se responde probando en incógnito.
2. **Pago: de inferido a explícito con override**. Regla por defecto se mantiene (la tarifa decide si pide anticipo), pero se agrega un control en Ajustes → Pago: *"¿Siempre pedir pago en línea al reservar?"* con 3 opciones — `Automático (según la tarifa)` [default, comportamiento actual] / `Siempre pedir anticipo` / `Nunca pedir pago en línea (confirmo yo directo)`. Esto le da al hotel la palanca literal que pidió sin romper la lógica de anticipo por tarifa que ya funciona bien en los casos donde SÍ está bien configurada.
3. Ambos controles (paso de extras, modo de pago) se resumen en la misma vista previa del punto 1, para que quede una sola pantalla que responda "¿qué va a ver mi huésped hoy?".

---

## 6. Plan de implementación

| Paso | Qué | Tamaño |
|---|---|---|
| P0 | Guardas de capacidad + aviso de stock en ajustes | ✅ Hecho |
| P1.a | `price_breakdown` en `availability()` y `holds()` + render en `Wizard.vue` | ✅ Hecho |
| P1.b | Vista previa de pasos + override de modo de pago (`settings.payment_mode`: automático/siempre/nunca) en `/ajustes/wizard` | ✅ Hecho |
| P1.c | Rediseño de campos de capacidad/precio en el editor de habitación (labels, cálculo en vivo, warning de config inalcanzable) | ✅ Hecho |
| P2 | Extender `price_breakdown` a la Agent API / panel interno para que el desglose sea consistente en todos los canales, no solo el wizard público | ✅ Hecho (Agent API); panel interno queda igual, ver nota |

**P1 completo (2026-07-10), 209 tests pasando, verificado en vivo contra motellacupula** (`price_breakdown` confirmado por curl en `/api/booking/availability`; `payment_mode` por default `automatic` — cero cambio de comportamiento para tenants que no lo toquen).

**P2 (2026-07-10):** `RatePlan::priceFor()`/`unitsFor()` ya centralizaban el cálculo — se le agregó `RatePlan::priceBreakdown()` al lado, como fuente única para las tres piezas que necesitan explicar el precio (`BookingController` se refactorizó para delegar en vez de duplicar la lógica que tenía). `AgentToolsController::storeHold()` (tool `create_reservation_hold`) ahora devuelve `price_breakdown` con `amount`+`amount_label` por línea, seguido el mismo patrón "crudo + etiqueta" que ya usa el resto del archivo para minimizar alucinación de cifras del LLM. 211 tests pasando (2 nuevos en `tests/Feature/AgentToolsTest.php`).

El panel interno (`reservations/Index.vue`) se dejó tal cual a propósito: su estimación en vivo ya está honestamente etiquetada como estimado ("el total definitivo lo recalcula el servidor al guardar") y la usa personal capacitado que ve los mismos campos de configuración directamente en la ficha del cuarto — el riesgo de confusión que motivó todo este spec (huésped o bot sin visibilidad de la config) no aplica ahí; refactorizarlo para consumir el breakdown del servidor sería una mejora de DRY, no una corrección, y tocaría un formulario grande y activo sin necesidad.

Siguiente paso sugerido: P1.a y P1.b primero (son los que más directamente responden "no veo por qué cobra eso" y "no controlo mis pasos"); P1.c después, con calma, porque toca el editor de habitación que ya usa el hotel a diario.
