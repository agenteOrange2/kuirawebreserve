# Spec — Plan maestro: base de módulos primero, núcleo hotelero guiado

> El orden de construcción decidido (jul 2026): PRIMERO la base de planes
> y módulos — para que todo lo que se construya después nazca con su
> interruptor y se active según el plan — y encima el núcleo hotelero
> (catálogo, habitaciones, reservas, plano) rehecho con UX guiada.
> El diseño fino de módulos y de precio único vive en
> `spec-modulos-y-precio-unico.md`; este documento es el PLAN DE
> EJECUCIÓN: etapas, entregables, qué ya existe, qué se agrega y cuándo
> está "hecho". El motor web (`spec-motor-reservas-web.md`) y pagos
> (`spec-pagos.md`) arrancan DESPUÉS de estas etapas.

**Tamaños:** `S` = días · `M` = ~1 semana · `L` = 2+ semanas.

---

## 1. Principios (lo que hace que todo se sienta "guiado")

1. **El sistema lleva de la mano.** Ninguna pantalla en blanco: todo
   estado vacío dice qué es, para qué sirve y tiene el botón para crear
   lo que falta. El dashboard trae la checklist "Primeros pasos" (§8).
2. **Cada dato se captura UNA vez.** El precio vive en la tarifa; la
   habitación hereda del tipo y solo guarda lo que es suyo. Nada de
   campos espejo que luego divergen (la lección de motellacupula).
3. **Módulo = interruptor, límite = número.** Lo contable
   (`max_rooms`, `max_users`…) sigue siendo límite; lo que se enciende o
   apaga (POS, cobros, IA, motor web) es módulo del plan.
4. **Todo lo nuevo nace detrás de su módulo.** A partir de la Etapa 1,
   ninguna feature opt-in se construye sin su key en `config/modules.php`.
   Así "activar según el plan" es automático, no un refactor posterior.
5. **UI = kuira-ui**: theme Raze/Midone, sin emojis, textos en español,
   móvil primero. Los patrones transversales (§8) son el estándar de
   todas las etapas.

---

## 2. Mapa de etapas

| Etapa | Nombre | Para quién | Tamaño | Depende de |
|---|---|---|---|---|
| **E1** | Base de planes y módulos | Plataforma (nosotros) + tenant ve su plan | `M` | — |
| **E2** | Precio único y catálogo guiado | Tenant (dueño/gerente) | `M` | — (paralelo a E1) |
| **E3** | Habitaciones guiadas | Tenant (gerente) | `S`–`M` | E2 |
| **E4** | Reservas: rack de ocupación | Tenant (recepción) | `L` | E2 (precios limpios) |
| **E5** | Plano operativo en tiempo real | Tenant (recepción/limpieza) | `S`–`M` | — |
| **T** | Onboarding y patrones UX | Transversal | incremental | se entrega dentro de E1–E5 |

```
E1 (módulos) ──────────────┐
E2 (precio único) → E3 (habitaciones) → E4 (rack reservas)   → luego: motor web E0
                                          E5 (plano)            (spec motor, ya gateado
T (onboarding) se va sumando en cada etapa                       por módulo motor-web)
```

E1 y E2 pueden ir en paralelo (no comparten tablas). E4 y E5 son
intercambiables entre sí.

---

## 3. Etapa 1 — Base de planes y módulos `M`

**Objetivo:** que un plan diga qué módulos incluye, que el admin lo
administre sin tocar código, y que apagar un módulo lo desaparezca del
tenant (menú, rutas, bot). Diseño detallado: spec módulos Parte II.

### Ya existe
Tabla central `plans` con límites numéricos + `ai_enabled`, CRUD en
`/admin/plans`, `tenant()->planLimit()` en 8 controladores,
`PlatformAgentGate` para IA, patrón `payment_method_settings`
(interruptor plataforma + override tenant) que se replica aquí.

### Entregables

1. **`config/modules.php`** — catálogo en código: key, label,
   descripción corta (la que ve el admin) y `available` (los módulos aún
   no construidos — `motor-web`, `extras`, `experiencias` — aparecen como
   "En desarrollo": se pueden incluir en planes desde ya, su área aparece
   cuando exista).
2. **Migraciones**: columna `plans.modules` (JSON) + tabla central
   `tenant_modules` (`tenant_id`, `module`, `enabled`; sin fila = hereda
   del plan). Backfill: Básico → `[pos]`; Pro → `[pos, cobros,
   agente-ia]`. `ai_enabled` migra a `agente-ia` (compat en
   `toConfigArray()` un ciclo).
3. **`Tenant::hasModule(string $key)`** — único punto de verdad
   (override ?? plan ?? false). `PlatformAgentGate` y el gate de
   pasarelas migran a usarlo.
4. **Menú y frontend**: `HandleInertiaRequests` comparte
   `panel.modules`; cada item de `useMenu.ts` declara su `module`
   opcional y se filtra. Apagado = el item no existe.
5. **Middleware `module:{key}`** en los grupos de rutas: `pos` (POS,
   inventario, turnos, cortes), `cobros` (pasarelas, links de pago),
   `agente-ia` (asistente). 403 con página amable: "Este módulo no está
   incluido en tu plan" + CTA de contacto.
6. **Admin — `/admin/plans`**: sección "Módulos incluidos" en
   crear/editar (un switch por módulo con su descripción; los "En
   desarrollo" marcados). Las tarjetas del índice listan módulos como
   badges junto a los límites.
7. **Admin — ficha del hotel** (`/admin/tenants/{id}`): tarjeta
   "Módulos" con estado efectivo y origen ("Incluido en plan Pro",
   "Forzado: activado", "Forzado: desactivado") y control
   forzar/heredar. Es la palanca de cortesías y pruebas.
8. **Tenant — `/ajustes`**: tarjeta "Tu plan" — nombre del plan, cada
   límite con su uso real (barra "12 de 30 habitaciones"), módulos
   incluidos y, en gris, los no incluidos con "Solicitar activación"
   (v1: registra la solicitud y la muestra en el admin; sin correo aún).

### Mejoras UX que agrega esta etapa
- Para nosotros: de un vistazo en la ficha del hotel se ve qué tiene y
  por qué (plan u override) — hoy hay que leer config y deducir.
- Para el tenant: primera vez que VE su plan (hoy solo hay un label
  suelto en ajustes) — transparencia + upsell pasivo.

### Aceptación
- Apagar `pos` a un plan: los hoteles de ese plan pierden POS/turnos/
  cortes/inventario del menú y sus rutas responden 403; sus datos
  siguen intactos y reaparecen al reactivar.
- Forzar `agente-ia` a un hotel Básico enciende el bot solo para él.
- Crear un plan nuevo con su mezcla de módulos: cero código.
- Tests: resolución hasModule (plan/override/default), middleware,
  filtro de menú, backfill.

### No entra en E1
Cobro automático de planes (fase 7 del roadmap general), página pública
de precios, trials con vencimiento.

---

## 4. Etapa 2 — Precio único y catálogo guiado `M`

**Objetivo:** eliminar la doble captura de precio (diagnóstico: spec
módulos Parte I) y que el catálogo se arme en orden, guiado. Es
PRERREQUISITO del motor web: primero un solo precio adentro, luego
publicarlo afuera.

### Ya existe
`/catalogo` con zonas, tipos y tarifas en tablas separadas (el precio se
ve doble), banner de guía (`showGuide`) en la página, tarifas ricas
(bloques/noche, anticipo, antelación, vencimiento).

### Entregables

1. **Migración precio único**: los tipos con tarifa descartan
   `base_price`; los tipos sin tarifa generan "Tarifa base" (por noche)
   desde su `base_price`. La columna se deja de escribir/mostrar (se
   elimina físicamente un ciclo después, verificado en producción).
2. **Alta de tipo en una captura**: el formulario pide "Precio y
   modalidad" (por noche / por bloque de N horas) y crea la tarifa
   automáticamente. Editar precio después = editar la tarifa (link
   directo desde el tipo).
3. **Catálogo agrupado**: la sección "Tipos y tarifas" reemplaza las dos
   tablas — cada tipo es una tarjeta expandible con sus tarifas
   anidadas y "Agregar tarifa" contextual (ya sabe a qué tipo). El
   "precio desde" del tipo se DERIVA (mínima tarifa activa).
4. **Guarda "sin tarifa"**: tipo sin tarifa activa = badge "Sin tarifa —
   no reservable" en catálogo, habitaciones y buscador de
   disponibilidad. Hoy falla silencioso.
5. **Stepper del catálogo** (patrón §8): "1 Zonas → 2 Tipos y tarifas →
   3 Habitaciones", con progreso real y link al paso incompleto.
6. **Duplicar tipo** (con sus tarifas) — alta de 6 tipos parecidos en
   minutos, el caso motel.

### Aceptación
- Ningún precio se captura dos veces; ningún lugar muestra dos montos
  distintos para el mismo concepto.
- motellacupula migra sin cambio de comportamiento (sus 6 tarifas ya
  coinciden con sus base_price).
- Un tipo recién creado ya es reservable (tiene tarifa) sin pasos
  extra.
- Tests: migración ambos caminos, derivación del "desde", guarda.

---

## 5. Etapa 3 — Habitaciones guiadas `S`–`M`

**Objetivo:** que dar de alta un hotel completo tome minutos, y que la
ficha de habitación deje claro qué hereda del tipo y qué es propio (la
otra mitad de la sensación de "todo se repite").

### Ya existe
CRUD con camas/vista/m²/amenidades/modifier, límite `max_rooms` del
plan, historial por habitación, ficha Show.

### Entregables

1. **Alta masiva por rango**: "del 101 al 110, tipo X, zona Y" crea N
   habitaciones de golpe (respetando `max_rooms`, con preview de números
   a crear y colisiones marcadas).
2. **Alta rápida motel** ("habitación única"): un formulario crea
   tipo + tarifa + habitación juntos. Pensado para el caso 1-a-1
   (motellacupula): 6 formularios en vez de 18 pantallas.
3. **Duplicar habitación** (mismo tipo/zona, número siguiente sugerido).
4. **Ficha con herencia explícita**: la ficha/formulario separa
   visualmente "Del tipo (se edita en el catálogo)" — precio desde,
   capacidad, horarios, amenidades base — de "De esta habitación" —
   número, camas, vista, ajuste de precio, amenidades extra. Los campos
   heredados llevan link "editar en el tipo". Mata la confusión de dónde
   se edita qué.
5. **Filtros y búsqueda**: por zona, tipo y estado + búsqueda por
   número (con 30+ habitaciones la lista actual no escala).
6. **Límite visible**: barra "X de Y habitaciones del plan" (patrón §8)
   con CTA a "Tu plan" al acercarse al tope.

### Aceptación
- Hotel de 30 habitaciones dado de alta en < 15 minutos partiendo de
  cero (zonas → tipos → rango masivo).
- En la ficha, ningún dato del tipo es editable "por accidente" desde la
  habitación.

---

## 6. Etapa 4 — Reservas: rack de ocupación `L`

**Objetivo:** la vista que hoy NO existe y que toda recepción espera: el
calendario habitaciones × días. Responder "¿qué hay libre el sábado?"
con los ojos, no con el buscador.

### Ya existe (y es bastante)
Buscador de disponibilidad con cotización en vivo, creación con
directorio de huéspedes, walk-in, acciones de estado con confirmación,
no-show, historial (timeline) por reserva, reportes. La lista de
reservas es sólida; lo que falta es la vista de OCUPACIÓN.

### Entregables

1. **Endpoint rack**: `GET /api/reservations/rack?from=&to=` —
   habitaciones (agrupadas por zona/tipo) × días, con las reservas y
   estancias que tocan el rango (estado, huésped, hold/confirmada/en
   casa). Reutiliza `AvailabilityService`; sin lógica nueva de negocio.
2. **Vista "Calendario"** en `/reservas` (tab junto a la lista):
   - grid con scroll horizontal por fechas (hoy marcado, 14 días
     visibles por defecto, rango ajustable);
   - barras por reserva coloreadas por estado (mismos colores del
     plano y la lista — un solo lenguaje de color);
   - click en barra → el mismo panel de detalle de la lista;
   - click en celda vacía → crear reserva con habitación y fecha
     precargadas (reusa el formulario existente);
   - solo lectura en v1: mover/estirar reservas arrastrando es `P2`
     (cambio de fechas ya existe por el formulario).
3. **Holds por vencer**: tarjeta en `/reservas` y en el dashboard con
   los apartados que expiran en < 30 min y acceso directo (hoy expiran
   sin que nadie los vea morir).
4. **Filtros de la lista**: por estado y rango de fechas (complemento,
   no rehacer la lista).

### Aceptación
- Recepción encuentra hueco para "2 noches desde el viernes" sin usar
  el buscador.
- El rack y el plano cuentan la misma historia (estados y colores
  consistentes).
- Rendimiento: 150 habitaciones × 30 días carga fluida (agregación en
  servidor, no N+1).

---

## 7. Etapa 5 — Plano operativo en tiempo real `S`–`M`

**Objetivo:** el plano ya es bueno operando UNA sesión; hacerlo confiable
con varias personas a la vez y conectarlo con el día.

### Ya existe
Drag de cuartos con posiciones, colores por estado, transiciones con
confirmación (check-in, limpieza, mantenimiento…), panel de detalle,
tooltips con horarios.

### Entregables

1. **Auto-actualización**: polling ligero (30–60 s + al volver el foco a
   la pestaña) del estado de cuartos. Dos recepcionistas dejan de verse
   versiones distintas. (Websockets/Echo: `P2`, si el polling queda
   corto.)
2. **Modo operación vs modo edición**: candado explícito — por defecto
   NADIE mueve cuartos; "Editar plano" (solo `rooms.manage`) habilita el
   drag. Evita el plano desacomodado por accidente.
3. **Leyenda fija de estados** (colores + significado), visible sin
   abrir nada.
4. **El día en el cuarto**: badge "Llega hoy" / "Sale hoy" en los
   cuartos con movimientos del día (datos que el dashboard ya calcula) y
   el detalle en el panel.
5. **Acción desde cuarto libre**: "Reservar" / "Walk-in" directo desde
   el panel del cuarto (precarga habitación).

### Aceptación
- Cambio de estado en una sesión visible en otra sin recargar (< 1 min).
- Imposible mover un cuarto sin entrar a modo edición.

---

## 8. Transversal T — Onboarding y patrones UX (se entrega DENTRO de E1–E5)

No es una etapa aparte con fecha: es el estándar que cada etapa debe
cumplir al tocar sus pantallas.

1. **Checklist "Primeros pasos"** (dashboard, entra con E2–E3): pasos
   DERIVADOS de los datos (no tabla nueva): datos del hotel → 1ª zona →
   1er tipo con tarifa → habitaciones → según módulos: canal conectado,
   pasarela, bot. Progreso visible, CTA por paso, se colapsa al
   completarse. Un hotel nuevo siempre sabe qué sigue.
2. **Estado vacío estándar** (componente único): icono + qué es esta
   sección + botón crear + link "cómo funciona". Prohibida la tabla
   vacía muda.
3. **Guía de página** (generalizar el `showGuide` del catálogo a
   componente `PageGuide`): banner colapsable por página con los 2–3
   conceptos clave y el orden sugerido; recordar cierre por usuario.
4. **Límites del plan visibles** (patrón único, entra con E1): barra
   "X de Y" en habitaciones/usuarios/canales/pasarelas + aviso al 80% +
   CTA "Tu plan". Nunca más un 403 sorpresa por tope.
5. **Lenguaje de color único de estados de reserva/habitación**:
   definido una vez (tokens), consumido por lista, rack, plano y
   dashboard.

---

## 9. Qué NO entra en este plan (y cuándo le toca)

| Fuera por ahora | Le toca |
|---|---|
| Wizard público `/reservar`, embed, Booking API | Inmediato siguiente: E0–E2 del spec motor, ya gateado por `motor-web` |
| Plugin WP "modo conectado" | E3 del spec motor (realdelasierra) |
| Temporadas y precios por fecha | Antes del motor web para realdelasierra (E0.5 spec motor) |
| Extras / experiencias | Spec motor §12, nacen con sus módulos ya listos por E1 |
| Cobro automático del plan (suscripción) | Fase 7 del roadmap general |
| Drag para mover reservas en el rack | `P2` de E4 |

---

## 10. Criterios globales de "hecho" (toda etapa)

- Tests Pest de lo nuevo corriendo en el contenedor (patrón actual).
- UI con tokens del theme Raze/Midone, sin emojis, textos en español
  (skill kuira-ui).
- Usable en móvil (recepción opera desde teléfono).
- Permisos respetados (`rooms.manage`, roles actuales) y, desde E1,
  módulos respetados.
- Migraciones reversibles y probadas contra los 3 tenants reales
  (demo, motellacupula, palmas) antes de producción.

---

## 11. Preguntas abiertas (decidir sobre la marcha, no bloquean E1)

1. **"Solicitar activación" de módulo** (E1): ¿solo registro visible en
   el admin (propuesto) o también correo/notificación?
2. **Rack v1** (E4): ¿14 días visibles por defecto está bien, o
   recepción prefiere vista semanal?
3. **Checklist "Primeros pasos"**: ¿incluye "primera reserva creada"
   como paso final (celebración) o termina en configuración?
4. **Polling del plano** (E5): ¿30 s es suficiente para la operación
   real de motellacupula?
