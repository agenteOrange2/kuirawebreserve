# Sistema de Reservas del Hotel — Visión general

Este documento explica, en lenguaje sencillo, **qué hace el sistema de reservas y
hasta dónde llega**: qué problemas resuelve para el hotel y qué puede hacer el
personal con él en el día a día.

---

## En una frase

Es el sistema que controla **todo el recorrido de un huésped**: desde que aparta
una habitación, pasando por su llegada y su estancia, hasta su salida y su pago —
todo en un solo lugar, siempre actualizado y sin errores de doble venta.

---

## ¿Qué problemas resuelve?

- **Se acabaron las libretas y los Excel.** Toda la ocupación del hotel vive en un
  sistema ordenado, no en papeles sueltos.
- **Nunca se vende la misma habitación dos veces.** El sistema no permite apartar
  una habitación que ya está ocupada o reservada.
- **Recepción trabaja más rápido y con menos errores.** El sistema propone fechas,
  calcula los precios y muestra solo las habitaciones libres.
- **La dirección sabe cómo va el negocio.** Reportes con números claros del hotel:
  cuánto se vendió, cuántas cancelaciones hubo, de dónde vienen los clientes.

---

## Lo que el hotel puede hacer

### Apartar y registrar huéspedes

- **Reservas anticipadas:** el cliente aparta con días de anticipación.
- **Llegadas sin reserva (walk-in):** el cliente que llega en el momento se acomoda
  al instante.
- **Registro de entrada (check-in) y salida (check-out):** con un clic, el hotel
  marca cuándo llega y cuándo se va cada huésped.

### Cobrar de distintas formas

El hotel decide cómo cobra cada tipo de habitación:

- **Por noche** — la forma clásica.
- **Por rato (horas), por día, por semana o por mes** — ideal para estancias
  cortas, largas o por temporada.

Además puede pedir un **anticipo** (un porcentaje por adelantado) y exigir que se
reserve con cierta **anticipación mínima**.

Lo mejor: **el sistema calcula solo la fecha de salida y el total a cobrar** en
cuanto se elige la tarifa. Recepción no tiene que sacar cuentas a mano.

### Llevar el control del dinero

- Registra los **pagos** de cada reserva (efectivo, tarjeta o transferencia).
- Muestra si una reserva está **sin pagar, con anticipo o pagada**.
- **Avisa cuando un pago está vencido**, para no dejar saldos olvidados.

### Conocer a sus clientes

Cada reserva se conecta con el **directorio de huéspedes**:

- Se ve **cuántas veces ha venido** el cliente y su historial.
- Se marcan clientes problemáticos en **lista negra**.
- Se guarda su **identificación y los datos de su vehículo** (placa, color), que se
  llenan solos al reservar para agilizar el registro y el control de acceso.

---

## Cómo se ve el día a día

La pantalla principal está organizada en tres vistas simples:

- **Próximas** — quién está por llegar.
- **En uso** — qué habitaciones están ocupadas ahora mismo.
- **Historial** — reservas que ya terminaron, se cancelaron o el cliente no llegó.

Cada vista muestra el conteo de un vistazo, para tener el pulso del hotel en
segundos.

Las acciones importantes (confirmar una reserva, registrar una llegada, cancelar,
marcar que el cliente no se presentó) **siempre piden confirmación** y explican qué
va a pasar, para evitar equivocaciones.

---

## Cosas que el sistema hace solo

Para que el personal no tenga que estar pendiente de todo:

- **Libera automáticamente los apartados que no se concretan.** Si alguien aparta
  una habitación y no confirma en un tiempo, vuelve a estar disponible sola. Así no
  se quedan habitaciones "bloqueadas" sin motivo.
- **Cierra estancias vencidas.** Cuando pasa la hora de salida, el sistema hace el
  check-out y marca la habitación como "por limpiar", para que limpieza la atienda.
  El tablero siempre refleja la realidad del hotel.

*(Estos tiempos se ajustan a la operación de cada hotel.)*

---

## Reportes para la dirección

El sistema entrega **reportes claros** del periodo que se elija (semana, mes, año o
un rango de fechas personalizado):

- Cuántas **reservas** hubo y cuántas fueron efectivas.
- Cuántas **cancelaciones y "no-shows"**, con su porcentaje.
- Cuánto se **ingresó** (hospedaje + consumos).
- Cuántos **check-ins y check-outs** se hicieron.
- Desglose **por tipo de habitación** y **por canal** (mostrador, teléfono, web,
  WhatsApp, walk-in).

Todo se puede **descargar en PDF**, listo para imprimir o enviar.

---

## Seguridad y control

Cada empleado ve y hace **solo lo que le corresponde según su puesto**. Por
ejemplo, un recepcionista puede crear reservas y cobrar, mientras otras acciones
quedan reservadas para supervisores. El hotel decide quién puede qué.

---

## En resumen, el sistema abarca:

- ✅ Reservas anticipadas y llegadas sin reserva
- ✅ Cobro por noche, por rato, por día, semana o mes
- ✅ Anticipos y control de pagos y saldos
- ✅ Cálculo automático de fechas y precios
- ✅ Disponibilidad en tiempo real, sin doble venta
- ✅ Registro de llegada y salida (check-in / check-out)
- ✅ Directorio de huéspedes con historial, lista negra y vehículo
- ✅ Tareas automáticas (liberar apartados y cerrar estancias vencidas)
- ✅ Reportes del negocio con descarga en PDF
- ✅ Control de acceso por puesto

---

*El sistema ya está en funcionamiento y en uso. Este documento resume el alcance
del módulo de reservas; el hotel puede adaptar precios, tiempos y permisos a su
operación.*
