# Caseta de motel en dos momentos y daños al salir

Solo aplica a **motel** (y a "ambos", donde decide quien atiende). El hotel se
comporta exactamente como antes.

## El flujo real que había que cabar

> Llegan en carro a la caseta → eligen habitación y con qué van a pagar → se
> abre el acceso. Ya en la habitación, el encargado anota placa, marca, modelo
> y color, **y cobra** (efectivo o terminal). Regresa a caseta con el papel y
> ahí se termina el llenado en la aplicación. Si piden comida, pagan según el
> método. Al salir revisan la habitación: si hay daños se cobran, se registran
> y se penaliza a quien los hizo.

El registro exprés pedía todo de un jalón y **cobraba al registrar**, así que
ese "mientras tanto" —acceso dado, datos y dinero pendientes— no existía.

## Momento 1: la caseta abre el acceso

En el exprés aparece **quién cobra**: *aquí mismo* o *el encargado, en la
habitación*. En motel puro arranca en "el encargado"; en "ambos" arranca en
"aquí mismo", que es el hotel de siempre.

Con "el encargado":

- el botón principal dice **Abrir acceso** y el bloque de cobro desaparece;
- la estancia nace **sin `Payment`** — queda debiendo, que es la verdad;
- y nace **sin sellar** (`stays.arrival_completed_at` en null).

Los datos del carro se pueden capturar ahí si los dieron, pero no se exigen.

## El estado intermedio, visible

`arrival_completed_at` es una columna, no una deducción de "sin placa y sin
identificación", por un caso real que la deducción no cubre: **el cliente que
no quiso dar datos** dejaría el aviso encendido para siempre.

En el plano, la tarjeta del cuarto dice **"Capturar $1,700"** — y ese aviso
**gana al "Debe $X"**: en la caseta siempre hay saldo hasta que el encargado
cobre, así que el saldo solo no dice qué hay que hacer. En el modal, el tab
*Resumen* lo pone arriba de todo con el botón **Completar registro**.

## Momento 2: regresó el papel

`PATCH /api/stays/{stay}/arrival` (`reservations.manage`): placa, marca, modelo
y color —o la identificación si llegaron a pie— y **marcar el cobro**. Reusa
`VehicleRegistry::resolve()` para la ficha de la placa (normaliza y no duplica)
y sella la llegada.

- El cobro entra al corte de **quien lo captura**, no del que abrió el acceso:
  es quien tiene el dinero en la mano.
- El monto SIEMPRE es lo que falta de hospedaje, nunca lo que mande el cliente.
- **"No dieron datos"** sella el registro sin captura, con constancia en las
  notas.

## Daños al registrar la salida

**Catálogo** en `/ajustes/danos` (área aislada, como wizard, pagos y avisos):
concepto y precio sugerido, guardados en `property.settings.damage_catalog` con
el `PATCH /api/properties/{property}` que ya mezcla settings — sin tabla nueva.
Existe para que **todos los turnos cobren lo mismo**; el precio se puede
ajustar al cobrarlo.

En el diálogo de salida, sección **Revisión de la habitación** (solo con
`hasMotel`): se elige del catálogo o se escribe libre, y cada daño:

| Qué hace | Cómo |
|---|---|
| Sube la cuenta | `POST /api/stays/{stay}/charges` → línea en `extra_charges` y recálculo de `amount` |
| Queda registrado | Incidencia con `source=guest` (`POST /api/incidents`), así sale en los reportes que ya existen |
| Penaliza | Casilla de veto con motivo → `PATCH /api/vehicles/{id}` y `PATCH /api/guests/{id}` |

**Retener al cliente no necesitó código**: el check-out ya se niega con saldo
pendiente salvo que se fuerce. Cobrar el daño antes de levantar la pluma es el
camino natural.

**El veto cierra el círculo**: el registro exprés ya avisaba cuando la placa
está vetada, así que la siguiente visita la caseta lo ve *antes* de dar acceso.
Verificado de punta a punta.

## Dos arreglos del plano que venían con esto

1. **Nunca dos modales encimados.** El exprés, la reserva y la cuenta de salida
   se abrían como HERMANOS del modal de habitación, los dos en `z-[60]`.
   HeadlessUI solo trata como anidados los diálogos que viven dentro del panel
   del otro, así que el de afuera leía el fondo del de adentro como "clic
   fuera" y se cerraban los dos. Ahora `roomDialogHidden` apaga el de
   habitación **sin soltar el cuarto** (los flujos lo necesitan) y al cerrar el
   de adentro vuelve. Los dos formularios largos llevan `staticBackdrop` —con
   dinero de por medio, un clic afuera no debe tirarlos— y **Esc sí cierra**,
   porque el theme se lo traga junto con el clic y se escucha aparte.
2. **"Nueva habitación" en la barra del plano.** Existía solo dentro del tab
   *Cuarto* y solo con el candado "Editar plano" abierto: nadie la encontraba.
   El formulario corto salió a `room/RoomForm.vue` y lo usan los dos lugares.
