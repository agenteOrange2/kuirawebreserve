# Spec — Integración con sitios: tokens, catálogo vivo y agente importador

> Aterriza las preguntas de jul 2026: "si el precio se actualiza en el
> sistema, ¿se actualiza solo en las páginas?" (sí: lectura en vivo, nunca
> copia), "¿qué plugin usamos?" (aparador conectado, no motor), y el
> requisito nuevo: conexión con **token único y protegido**, un apartado
> **Integración** en el tenant habilitable desde el admin, y un **agente
> que analiza/scrapea** el sitio del hotel para proponer la ficha de las
> habitaciones **siempre con validación humana**. Complementa
> `spec-motor-reservas-web.md` (aquí se implementa su §7 "site keys" en
> versión lectura) y `spec-plan-maestro.md` (el módulo `motor-web` de E1
> es el interruptor de todo esto).

---

## 1. La respuesta corta a "¿se actualiza en ambos?"

Sí, y sin sincronizar nada: el precio (y la disponibilidad) se editan UNA
vez en el sistema y las páginas los **leen en vivo** por API cada vez que
se muestran (con caché de minutos permitida). WordPress jamás guarda una
copia. Cambias la tarifa en el catálogo → el WP, el bot, el panel y el
wizard muestran lo nuevo solos. Eso es exactamente el ahorro que buscas y
es la regla del spec motor §2: lo transaccional se consulta, no se copia.

## 2. Tokens de sitio (la conexión protegida)

- Tabla central `site_integrations` (patrón `*_links`): `tenant_id`,
  `label` (ej. "WordPress motellacupula"), `token_hash` (sha256 — el token
  NUNCA se guarda en claro), `token_prefix` (para identificarlo en la UI),
  `domains` (lista blanca opcional), `active`, `last_used_at`.
- El token (`ksk_…`, 40+ caracteres aleatorios) se muestra **una sola
  vez** al crearlo; después solo se ve el prefijo. Revocable y
  re-generable desde la página Integración. Un token por sitio.
- **Habilitación**: toda el área vive detrás del módulo `motor-web`
  (E1). El admin la enciende por plan o por hotel (forzar activado en la
  ficha del hotel) — exactamente el mecanismo pedido "se permita
  habilitar en el admin".
- El uso queda auditable: `last_used_at` se actualiza en cada consulta.

## 3. API pública de sitio (v1: lectura)

Ruta stateless en el subdominio del tenant, con throttle:

- `GET /api/site/catalog` + `Authorization: Bearer {token}` → propiedad,
  tipos de habitación activos con `price_from` (derivado de tarifas, E2),
  descripción, capacidad, amenidades y sus tarifas activas (nombre,
  duración, precio, anticipo).
- El plugin de WP la consulta **desde el servidor** (transient de
  minutos): no hay CORS ni token expuesto en el navegador del visitante.
- El token es de LECTURA de catálogo: no crea reservas ni ve datos de
  huéspedes. Los holds públicos llegan con la Booking API del spec motor
  (E2) sobre esta misma tabla de tokens.
- Módulo apagado o token inactivo → 403/401; el sitio muestra su copia
  cacheada o nada.

## 4. Agente importador (scrape + IA con validación)

Para no capturar a mano lo que ya está publicado en el sitio del hotel:

- En Integración: "Analizar mi sitio" con la URL de la página de
  habitaciones. El servidor descarga la página (timeout y tamaño
  acotados), la limpia a texto y un LLM (la misma cadena de proveedores
  del bot: BYOK o plataforma) extrae las habitaciones: nombre,
  descripción, capacidad, amenidades.
- **Nunca precios.** El precio nace en las tarifas (precio único, E2); un
  precio scrapeado de una página vieja es exactamente el "precio
  fantasma" que eliminamos. El agente propone ficha, no dinero.
- Lo extraído NO se aplica solo: cae en `site_import_suggestions`
  (pendiente) como cola de validación. Cada sugerencia dice si es
  **actualizar** un tipo existente (match por nombre) o **crear** uno
  nuevo, y muestra qué campos propone. El humano aplica o descarta una
  por una.
- Aplicar "crear" genera el tipo **inactivo y sin tarifa** — la guarda
  "Sin tarifa — no reservable" (E2) obliga a ponerle precio conscientemente
  antes de venderlo.
- Re-analizar la misma URL reemplaza las sugerencias pendientes de esa
  fuente (no se acumulan duplicados).

## 5. Amenidades

Catálogo de amenidades sugeridas (chips clicables) en los formularios de
tipos y habitaciones: lo común de hotel/motel MX (wifi, aire
acondicionado, jacuzzi, cochera privada, TV por cable…). Sigue siendo
texto libre — las sugerencias aceleran, no restringen. El importador usa
el mismo vocabulario para que lo scrapeado quede consistente.

## 6. Qué NO es esto

- No es el wizard (`/reservar`, E0 del spec motor): esa es la siguiente
  pieza; esta área ya deja lista su conexión y su token.
- No es sincronización: no hay cron copiando datos a WP ni de WP.
- No crea reservas ni cobra: lectura de catálogo. El dinero sigue las
  reglas de spec-pagos, siempre en el tenant.

## 7. Roadmap de esta área

| Paso | Entregable |
|---|---|
| I0 (este) | Tokens + página Integración + `GET /api/site/catalog` + agente importador con validación + amenidades sugeridas; módulo `motor-web` pasa a disponible |
| I1 | Wizard alojado `/reservar` (E0 spec motor) — la tarjeta "Tu página de reservas" se agrega a Integración |
| I2 | Plugin WP mínimo: shortcode de precio vivo + shortcode del wizard, usando este token |
| I3 | Booking API transaccional (holds) sobre estos mismos tokens + dominio allowlist estricto (E2 spec motor) |

## 8. Preguntas abiertas

1. ¿Un solo token por sitio o varios con permisos distintos (lectura vs
   holds) cuando llegue I3? Propuesto: mismo token, permisos por fase.
2. ¿El importador también lee la galería de fotos del sitio (para la
   ficha mínima del wizard)? Útil para E0; entra cuando exista media en
   tipos.
3. Rotación de token: ¿forzar expiración periódica o solo revocación
   manual? Propuesto: manual (es llave de lectura).
