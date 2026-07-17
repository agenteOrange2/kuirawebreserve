# Guía — Conectar Messenger e Instagram DM a la bandeja

> Qué hacer, paso a paso, para que los DMs de la fan page y de Instagram
> lleguen a la bandeja y el bot responda. **El código ya está listo**: el
> webhook central procesa ambos, el envío usa la Send API, y el admin tiene
> Diagnosticar/Reparar para páginas. Todo lo de esta guía se hace en los
> dashboards de Meta + el alta en `/admin`.

**Prerrequisitos que ya tienes** (de la fase WhatsApp): app de Meta creada,
`META_APP_ID`, `META_APP_SECRET` y `META_VERIFY_TOKEN` en el `.env`, y el
webhook `https://kuirawebreserve.com/webhooks/meta` verificado.

---

## Parte 1 — Messenger (fan page de Facebook)

1. **Agregar el producto**: en [developers.facebook.com](https://developers.facebook.com)
   → tu app → "Agregar producto" → **Messenger** → Configurar.
2. **Vincular la página**: en Messenger → Configuración → "Páginas de acceso"
   → Conectar la fan page del hotel (te pedirá sesión de un admin de esa
   página).
3. **Generar el token de página**: en esa misma pantalla, botón "Generar
   token" junto a la página. Ese es el `access_token` que se captura en
   /admin.
   - Para pruebas sirve tal cual. Para producción, genera uno que no
     caduque: **Business Manager → Usuarios del sistema** → crea un usuario
     de sistema, asígnale la página como activo y genera el token con los
     permisos `pages_messaging` y `pages_manage_metadata` (los tokens de
     usuario de sistema no expiran).
4. **Suscribir el webhook**: en Messenger → Configuración → Webhooks:
   - Callback: `https://kuirawebreserve.com/webhooks/meta`
   - Verify token: el valor de `META_VERIFY_TOKEN`
   - Campos: **messages** (y `messaging_postbacks` para botones futuros).
   - Suscribe LA PÁGINA en "Webhooks de página" (o después usa el botón
     **Reparar suscripción** del admin, que hace exactamente eso).
5. **Alta en `/admin` → Agentes IA → canales Meta**: Vincular canal:
   - Canal: **Messenger** · Hotel: el tenant
   - ID externo: el **page_id** (Configuración de la página → "Acerca de" →
     Identificador, o en la URL del administrador de páginas)
   - Access token: el token de página del paso 3.
6. **Probar**: manda un DM a la página desde OTRO perfil (en modo
   desarrollo solo funcionan perfiles con rol en la app — agrégate como
   tester si hace falta). Debe aparecer en la bandeja y, con el canal en
   Automático, responder el bot. Botón **Diagnosticar** del admin: token
   vigente + página + app suscrita con campo `messages`.

## Parte 2 — Instagram DM

> **Ruta implementada en producción (jul 2026): "API con inicio de sesión
> de Instagram"** (Instagram Login, tokens `IGAA…`). Es la que quedó
> conectada para motellacupula y NO requiere página de Facebook vinculada.
> La ruta clásica vía página (abajo) también está soportada por el código;
> usa la que convenga por hotel.

### Ruta A — Instagram Login (la conectada hoy)

1. En la app → producto **Instagram** → "Configuración de la API con inicio
   de sesión para empresas": **Añadir cuenta** (inicia sesión con la cuenta
   profesional del hotel). Eso genera el **ID de la cuenta** y un **token
   `IGAA…`**.
2. **Clave secreta de la app de Instagram**: cópiala (es DISTINTA a la de
   Facebook) y va en el `.env` como `META_IG_APP_SECRET` — los webhooks de
   esta ruta se firman con ella; sin configurarla, se rechazan con 401.
3. Webhooks: la sección "2. Configurar Webhooks" del producto Instagram con
   el mismo callback y verify token; campo **messages**.
4. Alta en `/admin`: canal Instagram, ID externo = ID de la cuenta, token
   `IGAA…`, sin page_id. El botón **Reparar suscripción** suscribe la
   CUENTA a la app por la propia API de Instagram (`/me/subscribed_apps`).
5. En la app de Instagram del teléfono: **"Permitir el acceso a mensajes"**
   (Configuración → Mensajes → Controles de mensajes).
6. Pruebas en modo desarrollo: quien escriba debe tener rol — agrégalo en
   Roles de la aplicación → **Evaluadores de Instagram** (y que acepte la
   invitación en IG → Configuración → Sitios web y aplicaciones).
7. **Caducidad**: los tokens `IGAA…` duran ~60 días. Renovación: volver a
   generar el token y pegarlo en el canal (editar). Auto-refresh
   (`GET /refresh_access_token`) queda como mejora P2.

### Ruta B — vía página de Facebook (la clásica de Messenger)

1. **Requisitos de la cuenta** (lo más olvidado):
   - La cuenta de Instagram debe ser **profesional** (Empresa o Creador).
   - Debe estar **vinculada a la fan page** (Página → Configuración →
     Instagram → Conectar cuenta).
   - En la APP de Instagram del teléfono: Configuración → Mensajes y
     respuestas a historias → Controles de mensajes → **"Permitir el acceso
     a mensajes"** encendido. Sin esto Meta NO manda los webhooks, aunque
     todo lo demás esté bien.
2. **Agregar el producto**: app de Meta → "Agregar producto" → **Instagram**
   → Configurar (la ruta "API de Instagram con inicio de sesión de
   Facebook", que usa el token de la página).
3. **Webhooks**: suscribir el objeto **Instagram** con el campo
   **messages** (mismo callback y verify token).
4. **Alta en `/admin`**: Vincular canal:
   - Canal: **Instagram** · Hotel: el tenant
   - ID externo: el **ID de la cuenta profesional de Instagram** (no el de
     la página). Se obtiene con el token de página en el explorador Graph:
     `GET /{page_id}?fields=instagram_business_account` — o el admin te lo
     dirá en Diagnosticar si pegas otro id.
   - **Page ID de la página vinculada** (campo WABA/Página): el page_id de
     la fan page — la suscripción del webhook vive ahí; sin él no funcionan
     Diagnosticar/Reparar.
   - Access token: **el mismo token de página** del paso 1.3 (agrega los
     permisos `instagram_basic` e `instagram_manage_messages` al generarlo).
5. **Probar**: DM a la cuenta de Instagram desde otra cuenta (mismo tema de
   roles en modo desarrollo). Diagnosticar debe mostrar la cuenta
   (`nombre · usuario`) y la app suscrita a la página.

## Parte 3 — De desarrollo a producción (los trámites)

En **modo desarrollo** todo lo anterior funciona ya, pero solo con
perfiles que tengan rol en la app (admins/testers) — suficiente para
pilotar con tus hoteles. Para que CUALQUIER huésped escriba:

1. **Business Verification** del negocio en Business Manager (documentos,
   días o semanas — iniciarlo ya).
2. **App Review** pidiendo en un solo trámite (nota de spec-pendientes
   §4.8: no pasar dos veces por esto):
   `pages_messaging`, `pages_manage_metadata`, `pages_read_engagement`,
   `instagram_basic`, `instagram_manage_messages` — y de una vez los de
   comentarios si vas a querer community management
   (`pages_manage_engagement`, `instagram_manage_comments`).
   Te pedirán un video screencast del flujo (huésped escribe → bandeja
   responde) — grábalo con el tenant demo.
3. Cambiar la app a **modo Live** y `META_MODE=production` en el `.env`
   (obliga la validación de firma del webhook).

## Parte 4 — Detalles operativos que ya quedaron resueltos o pendientes

- **Ventana de 24 horas**: Messenger e Instagram solo permiten responder
  dentro de las 24 h del último mensaje del huésped. Las respuestas del
  bot/staff caen dentro siempre; los avisos diferidos (saldos del
  scheduler, follow-ups) que caigan FUERA de la ventana fallarán y quedan
  en el log — el mensaje sí queda en el hilo de la bandeja. La etiqueta
  `HUMAN_AGENT` (ventana de 7 días, requiere aprobación extra) queda como
  mejora P2.
- **Nombre del contacto**: se consulta a Meta al crear la conversación
  (nombre en Messenger, usuario en Instagram) — ya no aparecen como
  "Visitante".
- **Medios entrantes** (fotos/audios): entran como "[tipo no soportado
  todavía]" — pendiente P2 de canales, igual que en Evolution.
- **Dedupe y firma**: los reintentos de Meta se deduplican por id de
  mensaje y la firma `X-Hub-Signature-256` se valida en producción — ya
  estaba construido.
- **Latido**: `last_event_at` del canal se actualiza con cada evento; si un
  canal deja de latir, Diagnosticar + Reparar suscripción son el primer
  auxilio (la causa #1 es la página/cuenta sin suscribir a la app).
