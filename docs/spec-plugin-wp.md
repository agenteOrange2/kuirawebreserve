# Spec: plugin de WordPress y la integración de sitios

Estado: F1–F3 CONSTRUIDOS (2026-07-20, plugin v1.1.0 desplegado en
realdelasierra y conexión reparada en vivo). Complementa `spec-integracion-sitios.md` (la API
de catálogo y los tokens `ksk_` ya existen y funcionan); esto cubre el LADO
CONSUMIDOR: el plugin `kuirawebreserve-rooms` y lo que el panel promete de él.

## §1 Diagnóstico (2026-07-20, caso Cabañas Real de la Sierra)

Síntoma: el shortcode `[kuirawebreserve_rooms]` muestra "configura el dominio
y el token" aunque dominio y token SÍ están guardados.

1. **Causa raíz del outage**: el hotel capturó el dominio con ruta
   (`cabanasrealdelasierra.kuirawebreserve.com/reservar`). La sanitización
   solo quita `https://`, no rutas, y el plugin arma
   `https://…/reservar/api/site/catalog` → **404** (verificado con curl).
   Un campo que acepta basura silenciosamente es el bug, no el hotelero.
2. **Un solo mensaje para todo**: `fetchCatalog()` devuelve `null` igual para
   "sin configurar", 404 (dominio mal), 401 (token revocado), 403 (módulo
   motor-web apagado), timeout o catálogo vacío. El admin ve siempre
   "configúralo" — mentira cuando ya lo hizo. Sin forma de corroborar la
   conexión: no hay botón de prueba ni estado.
3. **Cache que sobrevive al guardado**: cambiar dominio/token NO invalida el
   transient de 5 min; el admin prueba, ve lo mismo, y concluye que no sirvió.
4. **Errores de la API en HTML**: `abort(401/403)` sin `Accept: application/json`
   devuelve la página de error de Laravel — el plugin no puede leer el motivo.
5. **Diseño pobre**: tarjetas sin foto (la API ni manda fotos), estilos inline,
   grid de columnas fijas que NO es responsive (4 columnas también en móvil),
   precio sin etiqueta de duración ("desde $1,500" ¿por noche? ¿por rato?).
6. **Promesa rota en /integracion**: la página anuncia shortcodes
   `[kuira_reservas]` / `[kuira_experiencias]` / `[kuira_grupos]` "con el
   plugin kuira-reservas de WordPress" — **ningún plugin registra esos
   shortcodes**. Solo existe `kuirawebreserve-rooms` (tarjetas de catálogo),
   instalado a mano en un solo sitio. Hay copia maestra en
   `integrations/wordpress/` (también un `kuirawebreserve-chat`), pero sin
   zip descargable ni link en /integracion — se despliega copiando a mano.

## §2 Principios

- El precio JAMÁS se copia: el plugin consulta el catálogo vivo desde el
  servidor de WP (token nunca llega al navegador), cache corta.
- El visitante NUNCA ve mensajes técnicos: los errores se le muestran solo a
  quien puede arreglarlos (admin de WP); el público ve el respaldo o nada.
- Un solo plugin (`kuirawebreserve-rooms`) hace todo el trabajo de WP:
  tarjetas de catálogo Y shortcodes de widgets. No inventar un segundo plugin
  que habría que instalar/actualizar por separado.
- Copia maestra en este repo (`integrations/wordpress/`); los sitios WP
  reciben copias. Cambios se hacen AQUÍ y se sincronizan.

## §3 F1 — Conexión confiable (plugin v1.1)

- **Sanitizar dominio de verdad**: aceptar lo que peguen
  (`https://x.com/reservar/`, con espacios, con puerto) y quedarse SOLO con el
  host (`wp_parse_url` con esquema forzado). Guardar host limpio.
- **Invalidar cache al guardar**: hook en `update_option_kuira_rooms_settings`
  → borrar transients (fresco y respaldo).
- **Fetch con diagnóstico**: mandar `Accept: application/json`; ante fallo
  guardar `['code' => …, 'message' => …, 'at' => time()]` en un option
  (`kuira_rooms_last_error`) y limpiarlo en éxito (guardando también
  `kuira_rooms_last_ok` con hotel y conteo). El shortcode para admins muestra
  el motivo REAL con pista de arreglo:
  - sin dominio/token → "configura…"
  - DNS/timeout → "no se pudo contactar {dominio}"
  - 404 → "el dominio no parece ser tu panel (¿lleva ruta de más?)"
  - 401 → "token inválido o revocado: genera uno nuevo en Integración"
  - 403 → "tu plan no incluye el módulo Motor de reservas web"
  - catálogo vacío → "aún no hay tipos con tarifa activa"
- **Respaldo stale-if-error**: segundo transient de 12 h con la última
  respuesta buena; si la API falla, el público sigue viendo tarjetas (el
  admin sí ve el aviso del error arriba de las tarjetas).
- **Probar conexión**: botón en ajustes (admin-post, nonce) que consulta SIN
  cache y pinta notice: éxito → "Conectado: {hotel} — N tipos, M reservables"
  / fallo → el mensaje diferenciado de arriba.
- **Estado permanente en ajustes**: bajo el formulario, "Última conexión OK:
  {fecha} ({hotel})" y/o "Último error: {fecha} — {motivo}".

## §4 F2 — Diseño de tarjetas (plugin v1.1)

- **Fotos**: la tarjeta abre con la primera foto del tipo (alto fijo 200px,
  `object-fit: cover`); sin foto → bloque de color neutro con inicial. Fuente:
  §5 (la API las manda).
- **CSS propio encolado** (`wp_enqueue_style` solo cuando el shortcode se usa,
  archivo `assets/rooms.css` con clases `kuira-*`): nada de estilos inline.
- **Responsive real**: `--kuira-cols` en desktop (2/3/4 configurable),
  2 columnas ≤1024px, 1 columna ≤640px.
- **Precio con etiqueta**: "Desde $1,500 MXN / por noche" usando el
  `duration_label` de la tarifa más barata; capacidad "Hasta N personas";
  amenidades como chips (máx 6 + "+N más").
- **Botón Reservar**: si el hotel dejó la URL vacía, default al wizard
  `https://{dominio}/reservar` (ya existe y es suyo) en vez de esconder el
  botón; el campo pasa a ser override (WhatsApp, tel:…).
- Tipografía heredada del theme del sitio; sin emojis (regla de la casa).

## §5 F3 — Lado Laravel

- **Fotos en `/api/site/catalog`**: por tipo, `photos: [{url, thumb_url}]`
  con URLs absolutas de la ruta pública ya existente
  (`/fotos/habitaciones/{mediaId}`). Sin migraciones: RoomType ya es HasMedia.
- **Errores en JSON**: el grupo `api/site` responde JSON siempre (middleware
  que fuerza `Accept: application/json`), para que el plugin lea `message`.
- **Shortcodes de widgets en el MISMO plugin**: `[kuira_reservas]`,
  `[kuira_experiencias]`, `[kuira_grupos]` → div `data-kuira-widget` +
  `widget.js` del dominio conectado (el loader ya existe). Cierra la promesa
  de /integracion sin plugin nuevo.
- **/integracion dice la verdad**: el copy nombra al plugin real
  ("KuiraWebReserve Habitaciones"), y la tarjeta del catálogo ofrece
  **descargar el .zip del plugin** (servido desde `public/downloads/`,
  generado desde la copia maestra) + el estado "última consulta del token"
  que ya registra `last_used_at` (corrobora la conexión desde el panel).

## §6 Distribución y mantenimiento

- Copia maestra: `integrations/wordpress/kuirawebreserve-rooms/` en este repo.
- Zip descargable: `public/downloads/kuirawebreserve-rooms.zip` regenerado a
  mano al tocar el plugin (`cd integrations/wordpress && zip -r …`).
- Sitios con copia instalada: realdelasierra (hoy). Al tocar la maestra,
  sincronizar con `cp` y añadir el plugin al `.graphifyignore` del sitio.
- Versionar el plugin (constante + header) y mostrar la versión en ajustes.

## §7 Fuera de alcance (por ahora)

- Selector de tipos a mostrar / ordenamiento manual en el shortcode.
- Galería/carrusel de fotos por tarjeta (solo primera foto en v1.1).
- Deep-link del botón Reservar con tipo preseleccionado en el wizard.
- Auto-actualización del plugin desde el panel (update server propio).
