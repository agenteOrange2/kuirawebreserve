# Spec — Channel manager (módulo futuro, NO construir aún)

> Módulo `channel-manager`: que un hotel que también vende en Booking,
> Airbnb o Expedia no venda doble — hoy la única defensa es bloquear
> habitaciones a mano (room_blocks) cuando entra una reserva externa, y
> nadie lo hace a tiempo. Decisión: se registra el rumbo y las fases;
> la construcción queda para cuando haya demanda real de hoteles con OTA.

**Estado:** solo spec. `available => false` en `config/modules.php` el
día que se registre; hoy ni siquiera está en el catálogo.

---

## 1. Objetivo

Una sola verdad de disponibilidad. Lo vendido en una OTA bloquea el
calendario de Kuira y lo vendido en Kuira (panel, wizard, bot) bloquea la
OTA. Sin esto, el hotel con canales externos vive con miedo al overbooking
o castiga inventario (aparta cuartos "solo para Booking").

## 2. Fase 1 — iCal export/import por tipo de habitación

Lo que ofrecen Airbnb/Booking sin certificación ni contrato:

- **Export:** una URL `webcal` por tipo de habitación
  (`/ical/{room_type}/{token}.ics`) con los rangos ocupados (reservas
  blocking + stays + room_blocks). La OTA la consulta cada 15-60 min.
- **Import:** el hotel pega la URL iCal de su anuncio; un scheduler la lee
  y materializa los eventos como bloqueos por fechas (reusar
  `room_blocks` con `source = 'ical'`) sobre habitaciones del tipo.
- Resolución simple: el import NUNCA pisa reservas propias; si un evento
  externo choca con una reserva viva, se avisa en el panel (conflicto a
  decisión humana).
- Limitación conocida y aceptada: sincronización con minutos de retraso y
  sin precios — solo disponibilidad. Es la fase "deja de vender doble",
  no la de "administra tarifas".

## 3. Fase 2 — Channel manager API

Integración de dos vías con confirmación inmediata: recibir reservas de
la OTA como reservas reales (folio, huésped, pagos marcados como cobrados
por el canal) y empujar tarifas/disponibilidad. Dos rutas posibles, a
decidir cuando toque: certificarse directo (Booking.com Connectivity API)
o integrar un agregador (p. ej. Channex) que ya habla con todas las OTAs
y cobra por habitación/mes. El agregador es el camino corto; el costo se
traslada al plan.

## 4. Decisiones

- **Módulo por plan** (`channel-manager` en `config/modules.php` +
  semilla del plan alto), mismo patrón que grupos/cupones: rutas con
  `module:`, item de menú con `module:`, `tenant()->hasModule()`.
- **NO construir aún.** Disparador para arrancar fase 1: el primer hotel
  real que opere con OTA y lo pida — el costo de fase 1 es bajo (iCal es
  texto plano) pero mantenerlo sin usuarios es puro lastre.
- La fase 1 no toca el motor de reservas: solo lee disponibilidad y
  escribe `room_blocks`. El punto anti-doble-venta sigue siendo
  `AvailabilityService` con sus locks — el iCal solo lo alimenta.
