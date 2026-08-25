# Migración de kuirawebreserve a VPS (Hostinger)

Runbook para levantar **solo** el sistema de reservas en un VPS limpio.
Origen: `frontend@casa:/home/frontend/webserver` (entorno compartido con 15 proyectos).
Destino: VPS Hostinger, entorno podado exclusivo de kuirawebreserve.

> Orden real: **F0 se hace en la máquina de origen** (es lo único irrecuperable).
> F1–F2 preparan el servidor y la imagen *sin* el código. F3 en adelante ya es la app.

---

## Bitácora real (25 ago 2026)

El runbook se escribió antes de tocar el VPS. Esto es lo que cambió al ejecutarlo — léelo
antes de seguir los pasos, porque varios ya no aplican tal cual.

**El VPS no estaba limpio.** Traía Traefik + n8n + Chatwoot + Evolution API corriendo desde
hacía 7 semanas, dueños de los puertos 80, 443 y 8080. Se eliminaron a petición del dueño
(era un proyecto de prueba); respaldo en `/root/backups/2026-08-25-pre-wipe/`. Tras el
borrado, el `docker-compose.yml` de F2.3 volvió a ser válido tal como está escrito.

**El dominio cambió** de `kuirawebreserve.com` a `tureservaenlinea.com` (Namecheap,
PremiumDNS). El repo, la carpeta del proyecto y los nombres de las bases **no** cambian.

**Cuatro trampas que este documento no traía:**

1. `tar czf ... storage/tenant*` desde el usuario del host **falla en silencio** en carpetas
   con permisos `700` que pertenecen a root o www-data: genera el archivo igual, con menos
   contenido del que crees. Hay que hacer el tar **desde dentro del contenedor php**, y
   contar los archivos de los dos lados antes de confiar en el respaldo.

2. La tabla `domains` de la base central guarda el dominio de cada tenant. Al cambiar de
   dominio hay que actualizarla o **ningún panel resuelve** — `stancl/tenancy` usa el
   subdominio como llave.

3. `config/tenancy.php` → `central_domains` debe incluir el dominio nuevo, o el panel
   central se trata como un tenant inexistente. Hacerlo **en el repo y con commit**, no
   suelto en el servidor, o el próximo `git pull` se lo lleva.

4. Si publicas registros **AAAA**, la conf de nginx necesita `listen [::]:80;` además de
   `listen 80;`. Sin eso, los visitantes por IPv6 caen en el server por defecto y ven otra
   página, mientras que por IPv4 todo se ve bien.

5. **El build del frontend NO se puede hacer con un contenedor de solo Node.** El plugin
   `@laravel/vite-plugin-wayfinder` ejecuta `php artisan wayfinder:generate` durante el
   build; con `node:20` a secas falla con `php: not found` antes de transformar un módulo.
   Se resolvió con `Dockerfile.build` (`FROM webserver-php` + Node 20, imagen `kwr-build`),
   que hereda las extensiones de PHP de la app. Correrlo en la red de compose.

6. **`docker compose up -d` no recarga nginx** si el contenedor ya estaba arriba. Cambiar
   un archivo en `nginx/conf.d/` no basta: hace falta `docker compose restart nginx`. Sin
   eso sigue sirviendo la conf vieja y parece que todo funciona.

7. **`php artisan route:cache` falla** por dos rutas con el mismo nombre
   `admin.tenants.modules` (`routes/admin.php` líneas 33 y 48). No impide arrancar, pero
   deja el sitio sin caché de rutas. Ver la sección "Bug de rutas duplicadas" al final.

**`SESSION_DOMAIN` se queda en `null`.** Ponerlo en `.tureservaenlinea.com` compartiría la
cookie de sesión entre todos los subdominios, o sea entre tenants distintos. No lo cambies.

---

## Inventario: qué es kuirawebreserve

| Pieza | Detalle |
|---|---|
| Repo | `https://github.com/agenteOrange2/kuirawebreserve.git` (rama `main`) |
| Stack | Laravel 12 · PHP 8.2 · Inertia + Vue 3 + Vite · Horizon · Reverb |
| Multi-tenancy | `stancl/tenancy` ^3.10 — **una DB por tenant** |
| DB central | `kuirawebreserve` |
| DBs tenant | `tenanthoteltest`, `tenanthotelmexico`, `tenantmotellacupula`, `tenantcabanasrealdelasierra` |
| Colas / cache | Redis (`predis`, la imagen PHP no trae phpredis) |
| Websockets | Reverb en `:8080`, proxied por nginx en `/app` |
| Dominios | `tureservaenlinea.com` + **wildcard** `*.tureservaenlinea.com` (cada subdominio = panel de un tenant) |

### Servicios que SÍ se migran (7)

`nginx` · `php` · `mysql` · `redis` · `horizon` · `reverb` · `scheduler-reservas`

### Servicios que NO se migran

| Servicio | Por qué |
|---|---|
| `worker` | `working_dir` apunta a **crmkuiraweb**, no a reservas |
| `scheduler` | idem, es el de crmkuiraweb |
| `phpmyadmin` | expuesto en `:8080` sin auth extra — en VPS público no |
| `cloudflared` | ⚠️ el token sirve **otros dominios**, ver Trampa 1 |
| `cloudflared-sosawolf` | proyecto ajeno |

> Las colas de reservas las procesa **Horizon**, no `worker`. Por eso `worker` sobra.

---

## F0 · En la máquina de origen — salvar lo irrecuperable

### 0.1 Guardar el código (⚠️ crítico)

Hay **212 archivos modificados** y **20 commits sin pushear**. Si clonas de GitHub sin
esto, pierdes semanas de trabajo.

```bash
cd /home/frontend/webserver/projects/laravel/kuirawebreserve

git status --short | wc -l      # confirma el número antes de nada
git add -A
git commit -m "Estado previo a migración a VPS"
git push origin main

git log --oneline origin/main..main | wc -l   # debe imprimir 0
```

Si algo de eso NO debe ir al repo, sepáralo antes con `git add -p`.

### 0.2 Dumps de MySQL

```bash
mkdir -p ~/migracion-kwr && cd ~/migracion-kwr

# DB central
docker exec webserver-mysql-1 mysqldump -uroot -proot \
  --single-transaction --routines --triggers --databases kuirawebreserve \
  > central-kuirawebreserve.sql

# DBs de tenants (una por hotel)
for t in tenanthoteltest tenanthotelmexico tenantmotellacupula tenantcabanasrealdelasierra; do
  docker exec webserver-mysql-1 mysqldump -uroot -proot \
    --single-transaction --routines --triggers --databases "$t" > "$t.sql"
done

ls -lh *.sql
```

> `kuirawebreserve_test` no se migra (base de tests).

### 0.3 Storage privado de tenants + .env

`storage/tenant*` está en `.gitignore` **a propósito** (documentos de huéspedes con INE,
adjuntos de WhatsApp). No viaja en el repo — va aparte.

```bash
cd /home/frontend/webserver/projects/laravel/kuirawebreserve

tar czf ~/migracion-kwr/storage-tenants.tgz storage/tenant* storage/app storage/media-library
cp .env ~/migracion-kwr/env.origen        # referencia, NO se copia tal cual al VPS
```

Tamaño esperado: ~10 MB. **No** incluyas `storage/logs` (14 MB de basura).

### 0.4 Config del entorno (Dockerfile, php/, nginx)

Estos archivos viven en `webserver/`, **no** en el repo del proyecto:

```bash
cd /home/frontend/webserver
tar czf ~/migracion-kwr/entorno.tgz Dockerfile php/ nginx/conf.d/kuirawebreserve.conf
```

### 0.5 Contexto de Claude Code

```bash
cd /home/frontend
tar czf ~/migracion-kwr/claude-contexto.tgz \
  .claude/projects/-home-frontend/memory/ \
  .claude/settings.json \
  webserver/CLAUDE.md webserver/ENTORNO.md
```

> **No** metas `~/.claude/projects/*/[a-z]*.jsonl` (164 MB de historial). Claude no lo
> lee solo, únicamente sirve para `/resume`.

### 0.6 Enviar al VPS

```bash
cd ~ && tar czf migracion-kwr.tgz migracion-kwr/
scp migracion-kwr.tgz usuario@IP_DEL_VPS:~/
```

---

## F1 · VPS — base del sistema

### 1.1 Usuario y hardening mínimo

```bash
# como root
adduser deploy && usermod -aG sudo deploy
rsync -a ~/.ssh/authorized_keys /home/deploy/.ssh/ --chown=deploy:deploy

ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable
```

**Nunca** abras 3306 ni 8080.

### 1.2 Swap (obligatorio si el VPS tiene ≤4 GB)

`vite build` de este proyecto se come más de 2 GB y muere por OOM sin swap.

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

### 1.3 Docker + herramientas

```bash
apt update && apt install -y ca-certificates curl gnupg git rsync

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list

apt update && apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker deploy

docker --version && docker compose version
```

Cierra sesión y vuelve a entrar como `deploy` para que aplique el grupo `docker`.

> No hace falta instalar PHP, Composer ni Node en el host: todo corre en contenedores.

---

## F2 · VPS — entorno e imagen (todavía sin código)

### 2.1 Estructura

```bash
mkdir -p ~/webserver/{projects/laravel,nginx/conf.d,php}
cd ~/webserver
tar xzf ~/migracion-kwr/entorno.tgz    # trae Dockerfile, php/, nginx/conf.d/
```

### 2.2 ⚠️ Corregir el entrypoint

`php/entrypoint.sh` viene con la ruta de **crmkuiraweb** hardcodeada. Si no lo cambias,
`storage/` de reservas nunca se auto-repara y los uploads fallan con
*"Unable to create a directory"*.

```bash
sed -i 's|APP=/var/www/laravel/crmkuiraweb|APP=/var/www/laravel/kuirawebreserve|' ~/webserver/php/entrypoint.sh
grep '^APP=' ~/webserver/php/entrypoint.sh    # verifica
```

### 2.3 docker-compose.yml podado

```bash
cat > ~/webserver/docker-compose.yml <<'YAML'
services:

  nginx:
    image: nginx:latest
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./projects:/var/www
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./certbot/conf:/etc/letsencrypt:ro
      - ./certbot/www:/var/www/certbot
    depends_on:
      php:
        condition: service_healthy
    networks: [web]

  php:
    build: .
    restart: always
    volumes:
      - ./projects:/var/www
    networks: [web]
    healthcheck:
      test: ["CMD-SHELL", "bash -c 'echo > /dev/tcp/127.0.0.1/9000' || exit 1"]
      interval: 5s
      timeout: 3s
      retries: 12
      start_period: 15s

  # Colas del sistema de reservas (broadcasts, jobs de tenants). Dashboard en /horizon.
  # Tras desplegar código nuevo: docker compose restart horizon
  horizon:
    build: .
    restart: unless-stopped
    working_dir: /var/www/laravel/kuirawebreserve
    command: setpriv --reuid=33 --regid=33 --clear-groups php artisan horizon
    volumes:
      - ./projects:/var/www
    depends_on: [mysql, redis]
    networks: [web]

  # Websockets: el semáforo de habitaciones en vivo. nginx proxea /app aquí.
  reverb:
    build: .
    restart: unless-stopped
    working_dir: /var/www/laravel/kuirawebreserve
    command: setpriv --reuid=33 --regid=33 --clear-groups php artisan reverb:start --host=0.0.0.0 --port=8080
    volumes:
      - ./projects:/var/www
    depends_on: [redis]
    networks: [web]

  scheduler-reservas:
    build: .
    restart: unless-stopped
    working_dir: /var/www/laravel/kuirawebreserve
    command: setpriv --reuid=33 --regid=33 --clear-groups php artisan schedule:work
    volumes:
      - ./projects:/var/www
    depends_on: [mysql, redis]
    networks: [web]

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    ports:
      - "127.0.0.1:6379:6379"
    volumes:
      - redis_data:/data
    networks: [web]

  mysql:
    image: mysql:8
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    ports:
      - "127.0.0.1:3306:3306"     # NUNCA 0.0.0.0 en un VPS público
    volumes:
      - mysql_data:/var/lib/mysql
    networks: [web]

volumes:
  mysql_data:
  redis_data:

networks:
  web:
    driver: bridge
YAML
```

Diferencias vs. el compose de casa, a propósito:

- MySQL en `127.0.0.1:3306` (en casa estaba en `0.0.0.0` — en VPS es MySQL abierto a internet).
- Password de root por variable, no `root` literal.
- nginx expone también 443 y monta los certs.
- Fuera `worker`, `scheduler`, `phpmyadmin`, ambos `cloudflared`.

### 2.4 .env del entorno

```bash
cat > ~/webserver/.env <<'YAML'
MYSQL_ROOT_PASSWORD=CAMBIA_ESTO_POR_ALGO_LARGO
YAML
chmod 600 ~/webserver/.env
```

### 2.5 Construir la imagen y levantar la base

```bash
cd ~/webserver
docker compose build php          # ~3-5 min: gd, zip, intl, pcntl, bcmath, composer
docker compose up -d mysql redis
docker compose logs -f mysql      # espera "ready for connections", Ctrl-C
```

En este punto tienes contenedor, imagen y herramientas listas **sin una sola línea de la app**.

---

## F3 · Código y datos

### 3.1 Clonar

```bash
cd ~/webserver/projects/laravel
git clone https://github.com/agenteOrange2/kuirawebreserve.git
cd kuirawebreserve
```

### 3.2 .env de producción

Parte del `env.origen` y cambia **solo** estas líneas:

```ini
APP_ENV=production
APP_DEBUG=false                 # en casa está en true
APP_URL=https://tureservaenlinea.com
APP_KEY=<<< EL MISMO DE ORIGEN >>>

DB_HOST=mysql                   # nombre del servicio, NO 127.0.0.1
DB_PASSWORD=<el MYSQL_ROOT_PASSWORD del paso 2.4>

REDIS_HOST=redis
REVERB_HOST=reverb
```

Todo lo demás (Meta, VAPID, Reverb keys, Stripe) se copia **idéntico**.

> ⚠️ **El `APP_KEY` NO se regenera.** Si corres `php artisan key:generate` en el VPS,
> todo lo cifrado con esa llave —incluidas las credenciales de conexión que guarda
> `stancl/tenancy`— queda ilegible y los tenants dejan de abrir. Es irreversible.

```bash
chmod 600 .env
```

### 3.3 Restaurar las bases

```bash
cd ~/migracion-kwr
PASS=$(grep MYSQL_ROOT_PASSWORD ~/webserver/.env | cut -d= -f2)

for f in central-kuirawebreserve.sql tenant*.sql; do
  echo "-> $f"
  docker exec -i webserver-mysql-1 mysql -uroot -p"$PASS" < "$f"
done

docker exec webserver-mysql-1 mysql -uroot -p"$PASS" -e "SHOW DATABASES" | grep -E 'kuira|tenant'
```

Deben aparecer las 5.

### 3.4 Storage privado

```bash
cd ~/webserver/projects/laravel/kuirawebreserve
tar xzf ~/migracion-kwr/storage-tenants.tgz
```

---

## F4 · Dependencias y arranque

Todo se corre **dentro del contenedor** (PHP 8.2 correcto de fábrica, sin pines).

```bash
cd ~/webserver
docker compose up -d php
K="docker exec -w /var/www/laravel/kuirawebreserve webserver-php-1"

$K composer install --no-dev --optimize-autoloader
```

> El pin `composer config platform.php 8.2.30` **solo hace falta si corres composer en el
> host**. Dentro del contenedor ya es 8.2 y el pin sobra.

### 4.1 Build del frontend (Node 20, contenedor efímero)

El contenedor PHP no trae Node, y `public/build` está gitignoreado — hay que compilar.
El `.env` debe estar completo **antes** de esto: Vite hornea `VITE_REVERB_APP_KEY` en el bundle.

```bash
cd ~/webserver/projects/laravel/kuirawebreserve
docker run --rm -v "$PWD":/app -w /app -e NODE_OPTIONS=--max-old-space-size=3072 \
  node:20 sh -c "npm ci && npm run build"
ls public/build/manifest.json     # debe existir
```

### 4.2 Migraciones y caches

```bash
cd ~/webserver
K="docker exec -w /var/www/laravel/kuirawebreserve webserver-php-1"

$K php artisan storage:link
$K php artisan migrate --force            # DB central
$K php artisan tenants:migrate            # las 4 DBs de tenant
$K php artisan config:cache
$K php artisan route:cache
$K php artisan view:cache
```

### 4.3 Permisos

Cualquier `docker exec` como root deja archivos root en `storage/`. El entrypoint lo
repara al arrancar, pero después de este bloque conviene forzarlo:

```bash
docker exec webserver-php-1 chown -R www-data:www-data \
  /var/www/laravel/kuirawebreserve/storage \
  /var/www/laravel/kuirawebreserve/bootstrap/cache
```

### 4.4 Levantar todo

```bash
cd ~/webserver
docker compose up -d
docker compose ps        # 7 servicios en "running"
```

---

## F5 · Dominio y SSL

### 5.1 DNS en Hostinger

```
A   tureservaenlinea.com   ->  72.60.66.38
A   *.tureservaenlinea.com ->  72.60.66.38      <- imprescindible: cada tenant es un subdominio
```

### 5.2 Certificado wildcard

Un wildcard **no** se puede emitir por HTTP-01; requiere DNS-01 (un TXT en
`_acme-challenge`). Con el DNS en Hostinger va en modo manual:

```bash
docker run -it --rm -v ~/webserver/certbot/conf:/etc/letsencrypt \
  certbot/certbot certonly --manual --preferred-challenges dns \
  -d tureservaenlinea.com -d '*.tureservaenlinea.com'
```

Certbot te dicta el TXT; lo pones en el panel de Hostinger, esperas propagación y
confirmas. **Ojo**: en modo manual la renovación no es automática — agenda recordatorio a
los 60 días, o mueve el DNS a Cloudflare y usa el plugin `--dns-cloudflare`.

### 5.3 nginx

El `kuirawebreserve.conf` que trajiste solo escucha en `:80`. Añade el bloque `:443` con
los certs, y —**esto es lo que se olvida**— **replica el `location /app`** dentro del
bloque SSL. El cliente detecta TLS solo (`forceTLS` sale de `window.location.protocol`),
pero si el proxy `/app` no está en el bloque 443, el semáforo en vivo se queda mudo bajo
HTTPS sin dar error visible.

Conserva también el ajuste de buffers, o cualquiera con sesión recibe 502:

```nginx
fastcgi_buffer_size 64k;
fastcgi_buffers 32 32k;
```

```bash
docker compose exec nginx nginx -t && docker compose restart nginx
```

---

## F6 · Claude Code en el VPS

```bash
npm install -g @anthropic-ai/claude-code    # o el instalador nativo
claude    # login con marcohernandezr@zoho.com
```

> La cuenta lleva **autenticación y suscripción, nada de contexto**. El contexto son
> archivos en disco — por eso se copia a mano:

```bash
cd ~ && tar xzf ~/migracion-kwr/claude-contexto.tgz
```

⚠️ **La carpeta de memoria se nombra según la ruta del proyecto.** En casa es
`-home-frontend` porque el directorio es `/home/frontend`. Si en el VPS trabajas desde
`/home/deploy`, hay que renombrarla o Claude no la encuentra:

```bash
mv ~/.claude/projects/-home-frontend ~/.claude/projects/-home-deploy
```

Y deja un `CLAUDE.md` en `~/webserver/` describiendo **este** entorno (7 servicios, un
proyecto), no el de casa con sus 15 proyectos y graphify.

---

## Verificación final

```bash
cd ~/webserver
docker compose ps                                   # 7 running
curl -I https://tureservaenlinea.com                 # 200
curl -I https://hoteltest.tureservaenlinea.com       # 200 (tenant vivo)
docker compose logs --tail=50 horizon               # sin excepciones
docker compose logs --tail=50 reverb                # "Server started"
docker exec -w /var/www/laravel/kuirawebreserve webserver-php-1 php artisan about
```

En el navegador, con la consola abierta:
- El panel de un tenant conecta el websocket (`wss://.../app/...` en Network → WS).
- `/horizon` muestra los supervisors activos.
- Subir un documento a una reserva funciona (valida permisos de `storage/`).

---

## Trampas conocidas

| # | Trampa | Consecuencia | Cómo se evita |
|---|---|---|---|
| 1 | Reusar `CLOUDFLARE_TUNNEL_TOKEN` | Cloudflare reparte tráfico **al azar** entre casa y VPS; el token además sirve otros dominios | No migrar cloudflared. VPS con IP pública → nginx + certbot. Si quieres túnel, **crea uno nuevo** en Zero Trust |
| 2 | `key:generate` en el VPS | Datos cifrados ilegibles, tenants caídos, **irreversible** | Copiar el `APP_KEY` de origen tal cual |
| 3 | `entrypoint.sh` con ruta de crmkuiraweb | `storage/` sin auto-reparar → uploads "Unable to create a directory", descargas 404 | `sed` del paso 2.2 |
| 4 | MySQL en `0.0.0.0:3306` | Base de datos expuesta a internet | Bind `127.0.0.1` + ufw |
| 5 | Olvidar `storage/tenant*` | INEs y adjuntos de WhatsApp perdidos (están gitignoreados) | Tar del paso 0.3 |
| 6 | `location /app` solo en el bloque `:80` | Semáforo de habitaciones mudo bajo HTTPS, sin error visible | Replicarlo en el bloque `:443` |
| 7 | Build de Vite sin swap | OOM a media compilación | Swap del paso 1.2 |
| 8 | Usuario MySQL limitado en vez de root | `stancl/tenancy` necesita `CREATE DATABASE`; crear tenants nuevos falla | Root, o un usuario con ese GRANT |
| 9 | `DB_HOST=127.0.0.1` | El contenedor no ve MySQL | `DB_HOST=mysql` (nombre del servicio) |
| 10 | Buffers fastcgi por defecto | 502 para cualquiera con sesión (Laravel manda un header `Link` enorme) | `fastcgi_buffer_size 64k` |

---

## Despliegues posteriores

Todo esto lo hace **`/root/webserver/deploy.sh`** en el VPS:

```bash
ssh root@72.60.66.38 /root/webserver/deploy.sh
```

Qué hace y por qué, en orden:

1. **Aborta si hay cambios sin commitear en el servidor.** Un `git pull` encima de
   ediciones hechas a mano las pisa o se queda a medias.
2. `git pull --ff-only origin main`. Si no hay nada nuevo, termina ahí y no toca nada.
3. **Modo mantenimiento**, con un `trap` que devuelve el sitio aunque algo reviente
   a media migración.
4. `composer install` **solo si cambió `composer.lock`**.
5. Build del front **solo si cambió `resources/`, `package*.json` o el tooling** —
   y con la imagen `kwr-build`, no con `node:20`. Ver trampa 5 de la bitácora.
6. `migrate --force` **y** `tenants:migrate`. El segundo es el que se olvida: sin él
   la migración solo entra en la base central y los paneles de hotel truenan con
   *"column not found"*.
7. `config:cache`, `view:cache` y `route:cache` (este último tolera el fallo por
   rutas duplicadas, ver la sección del bug).
8. `chown` de `storage/` y `bootstrap/cache` a `www-data`: todo lo anterior corrió
   como root dentro del contenedor.
9. `docker compose restart horizon reverb scheduler-reservas`. Los tres cargan el
   código en memoria al arrancar y no lo recargan solos — el sitio ya muestra la
   versión nueva mientras las colas siguen ejecutando la vieja.

> El script vive **fuera del repo** (es config del servidor, con rutas del VPS).
> Si lo cambias, cámbialo en el servidor.

Un commit **no** despliega nada por sí solo: `git push` llega a GitHub y ahí se queda.
El VPS solo cambia cuando alguien corre `deploy.sh`. Si algún día quieres despliegue
automático en cada push a `main`, es GitHub Actions con una llave de deploy — pero
conviene esperar a tener tests, o un commit a medias en `main` se publica solo.

---

## Bug de rutas duplicadas (pendiente de decisión)

`routes/admin.php` define **dos rutas distintas con el mismo nombre**:

```php
// línea 33 — la PÁGINA
Route::get('modulos', [TenantAreaController::class, 'modules'])->name('modules');
//   => admin.tenants.modules  ->  GET /admin/tenants/{tenant}/modulos

// línea 48 — la ACCIÓN
Route::patch('tenants/{tenant}/modules', [TenantController::class, 'updateModule'])
    ->name('tenants.modules');
//   => admin.tenants.modules  ->  PATCH /admin/tenants/{tenant}/modules
```

Laravel lo tolera en runtime —gana la última registrada, la `PATCH`— pero `route:cache`
se niega a serializar y aborta. Efecto secundario en el navegador: la pestaña **"Módulos"**
de `TenantHeader.vue` (línea 55) genera la URL de la acción `PATCH` y al navegar por `GET`
no hay ruta que la atienda. `Modules.vue` (línea 82) sí obtiene la URL correcta porque
manda `router.patch`.

**Arreglo propuesto** (no aplicado; cambia comportamiento de la app):

```php
// línea 48
Route::patch('tenants/{tenant}/modules', [TenantController::class, 'updateModule'])
    ->name('tenants.modules.update');
```

```ts
// resources/js/pages/admin/tenants/Modules.vue:82
route('admin.tenants.modules.update', props.tenant.id)
```

Con eso la página conserva el nombre corto, la pestaña vuelve a funcionar y `route:cache`
pasa. Después: `php artisan route:cache` y rebuild del frontend (Wayfinder regenera tipos).
