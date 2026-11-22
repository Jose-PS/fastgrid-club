# Despliegue de FastGrid

Procedimiento de despliegue en el VPS de producción y vuelta atrás.

## Topología

```
Caddy compartido (/opt/edge, proyecto de compose `edge`, puertos 80/443)
  -> contenedor `app` de este repo (alias `fastgrid-app` en la red `edge`, puerto interno 8000)
       -> `db` (PostgreSQL), `redis` y `ssr` en la red interna `backend`
  <- `queue` y `scheduler` salen a Internet y al panel del juego (`http://acevo-server:8090`) por `edge`
  `backup` en `backend` (lee `db`, escribe el latido en `redis`) y en `backup-egress`, una red propia solo para la subida externa
```

- Una sola imagen (`ghcr.io/jose-ps/fastgrid-club:<sha>`) para `migrate`, `app`, `queue`, `scheduler` y `ssr`; solo cambia el comando.
- El servicio `backup` usa su propia imagen (`fastgrid-backup:pg18.6-1`), que se construye en el VPS desde `docker/backup` (`pull_policy: build`): PostgreSQL 18.6 Alpine con `pg_dump`, `age` y `rclone` con versión fijada y el `redis-cli` de la misma imagen de Redis del compose.
- El compose no publica ningún puerto en el host. PostgreSQL y Redis solo están en la red `backend`, que es `internal`.
- Ficheros: `Dockerfile`, `docker/entrypoint.sh`, `docker/backup/`, `deploy/web/compose.yaml`, `deploy/web/.env.production.example`, `deploy/web/check-compose.sh`, `deploy/sites/fastgrid.club.caddy`, `.github/workflows/ci.yml`.

## Qué hace el CI

- `tests` (ya existente): suite, Pint, PHPStan, lint, tipos, Vitest, build y presupuesto de bundle.
- `ci` se ejecuta cuando `tests` termina bien en `main`: valida el compose (`deploy/web/check-compose.sh`), construye la imagen (`target: prod`), la escanea con Trivy y, si pasa, publica en GHCR esa misma imagen etiquetada solo con el SHA del commit. En pull requests que tocan la imagen o `deploy/` construye y escanea, sin publicar.
- Escáner (X6): `aquasecurity/trivy-action` fijada por commit (v0.36.0) con Trivy v0.76.1. Falla con vulnerabilidades `CRITICAL` que tengan arreglo publicado (`ignore-unfixed`); las que no tienen arreglo no bloquean. También construye y escanea la imagen de `backup`, que no se publica. Si una vulnerabilidad bloquea el despliegue, se actualiza la base (etiqueta y resumen) o el paquete afectado.
- El despliegue al VPS es automático: cuando `tests` termina bien en un push a `main` y el job de imagen publica, el job `deploy` de `ci.yml` despliega ese SHA. También se puede lanzar a mano con `workflow_dispatch` indicando el SHA de una imagen ya publicada (para volver a una anterior).

### Secrets y entorno de GitHub

- Entorno `production` en GitHub (se puede exigir aprobación manual).
- Secrets (del entorno o del repo): `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY` (clave privada). El usuario del VPS debe poder usar Docker (grupo `docker`) y escribir en `/opt/fastgrid` y en `/opt/edge/sites`.
- GHCR: no hace falta ningún secret. El job presta el `GITHUB_TOKEN` de la ejecución (permiso `packages: read`) para el `docker login` en el VPS y hace logout al terminar. Para que ese token pueda leer la imagen privada, el paquete `acevo-server` debe estar vinculado al repo (lo está al publicarlo desde su workflow) o tener el repo en «Manage Actions access» del paquete. Si GHCR devuelve `denied`, se arregla ahí o se sustituye por un PAT con `read:packages` guardado como secret.
- Qué hace el job `deploy` (tras un push a `main` con tests en verde e imagen publicada, o con `workflow_dispatch` y el SHA de la imagen):
  1. Descarga el repo en ese SHA y copia por SCP a `/opt/fastgrid`: `deploy/web/compose.yaml`, `deploy/web/check-compose.sh`, `deploy/sites/fastgrid.club.caddy` y `docker/backup` (el VPS no clona el repo). No copia ni toca `deploy/web/.env`.
  2. Copia el fragmento a `/opt/edge/sites/fastgrid.club.caddy` guardando el anterior, valida con `docker exec edge-caddy-1 caddy validate`; si falla, restaura el anterior (o borra el nuevo) y termina con error sin recargar. Si valida, recarga Caddy. No toca `00-cloudflare.caddy` ni los demás fragmentos.
  3. Fija `IMAGE_TAG` en `deploy/web/.env` (solo esa línea), hace login en GHCR, `docker compose pull --ignore-buildable`, levanta `db` y `redis`, ejecuta `migrate`, hace `up -d --remove-orphans` y espera a que `app` esté `healthy`.
  4. Solo si el despliegue terminó bien, borra las imágenes antiguas de `ghcr.io/jose-ps/fastgrid-club` conservando la nueva y la anterior (la que tenía `IMAGE_TAG` antes) y ejecuta `docker image prune -f` (solo imágenes sin etiqueta). No toca imágenes de otros repositorios y un fallo de la limpieza no falla el despliegue.
- Requisitos previos en el VPS: red `edge`, contenedor `edge-caddy-1`, `/opt/fastgrid/deploy/web/.env` creado y el certificado de origen en `/opt/edge/certs/fastgrid.club/` (sin él Caddy rechaza el fragmento y el job se detiene antes de tocar la app). Ver «Primer despliegue en fastgrid.club».

## Bootstrap único en el VPS

1. Red compartida (ya existe por los otros proyectos):
   ```sh
   docker network inspect edge || docker network create edge
   ```
2. Crear la carpeta. Los ficheros los copia el job `deploy` por SCP; para un arranque manual, copiar antes `deploy/`, `docker/backup` desde el repo:
   ```sh
   sudo mkdir -p /opt/fastgrid/deploy/web && sudo chown -R "$USER" /opt/fastgrid
   cd /opt/fastgrid/deploy/web
   ```
3. Crear el `.env` (permisos 600) a partir de la plantilla:
   ```sh
   cp .env.production.example .env
   chmod 600 .env
   ```
   Rellenar todo lo vacío:
   - `APP_KEY`: `echo "base64:$(head -c 32 /dev/urandom | base64)"`. Se genera una vez y no se cambia.
   - `DB_PASSWORD` y `REDIS_PASSWORD`: aleatorias y distintas de las de cualquier otro servicio (`head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9`).
   - `APP_URL=https://fastgrid.club`.
   - `TRUSTED_PROXIES`: subred de `edge`, para que la IP real del cliente llegue a Laravel. Se obtiene con `docker network inspect edge --format '{{(index .IPAM.Config 0).Subnet}}'`.
   - `MAIL_*`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` y `STEAM_API_KEY` (opcional).
   - `MAIL_SCHEME` solo admite `smtp` (STARTTLS en el 587, la plantilla lo trae así) o `smtps` (TLS directo, normalmente el 465). `tls` no es válido y provoca un 500 al pedir el enlace mágico.
   - `SERVER_USER` y `SERVER_PASS`: credenciales Basic Auth del panel del servidor de juego. `SERVER_URL` apunta a `http://acevo-server:8090` por la red `edge`.
   - `IMAGE_TAG`: SHA de la imagen a desplegar.
   - `BACKUP_AGE_RECIPIENT`: clave pública age (ver «Backups de PostgreSQL»). `BACKUP_TIME` (por defecto `04:30`) y `BACKUP_RCLONE_REMOTE` (vacío: solo copia local) son opcionales.
   Sin las variables obligatorias el contenedor se niega a arrancar: `php artisan env:check` (lo ejecuta el entrypoint) imprime cada variable que falta y sale con error.
4. Caddy: el fragmento `deploy/sites/fastgrid.club.caddy` se copia a `/opt/edge/sites/fastgrid.club.caddy` (lo hace el job; a mano, igual) y se valida y recarga:
   ```sh
   docker exec edge-caddy-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
   docker exec edge-caddy-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
   ```
   El fragmento supone Cloudflare delante (como el resto de sitios del VPS): usa el snippet `solo-cloudflare` (definido en `/opt/edge/sites/00-cloudflare.caddy`) y toma la IP de `CF-Connecting-IP`. `www.fastgrid.club` redirige de forma permanente a `https://fastgrid.club`.
5. Verificar la imagen antes de arrancar:
   ```sh
   docker login ghcr.io
   docker compose pull
   ```

## Primer despliegue en fastgrid.club

Pasos manuales previos, una sola vez:

1. DNS en Cloudflare: registros `A` para `@` y `www` hacia `<IP_DEL_VPS>`, con proxy activado (nube naranja).
2. Cloudflare, SSL/TLS: modo `Full (strict)`.
3. Origin Certificate (SSL/TLS, Origin Server) para `fastgrid.club` y `*.fastgrid.club`. Guardarlo en el VPS:
   ```sh
   sudo mkdir -p /opt/edge/certs/fastgrid.club
   sudo install -m 644 origin.pem /opt/edge/certs/fastgrid.club/origin.pem
   sudo install -m 600 origin.key /opt/edge/certs/fastgrid.club/origin.key
   ```
   El Caddy compartido las ve como `/etc/caddy/certs/fastgrid.club/origin.pem` y `origin.key` (mismo montaje que el resto de dominios; si Caddy corre como otro usuario, ajustar propietario o permisos para que pueda leer la clave).
4. Google OAuth (consola de Google Cloud, cliente OAuth): URI de redirección autorizado `https://fastgrid.club/auth/google/callback` (ruta `auth.google.callback`, `GET /auth/google/callback`; `config/services.php` lo declara relativo y se resuelve con `APP_URL`). Origen JavaScript autorizado: `https://fastgrid.club`.
5. Steam: no hay registro de dominio. El login OpenID usa `https://fastgrid.club` como `realm` y `https://fastgrid.club/auth/steam/callback` como `return_to` (ruta `auth.steam.callback`, `GET /auth/steam/callback`), ambos derivados de `APP_URL`; `STEAM_API_KEY` se obtiene en steamcommunity.com/dev/apikey indicando `fastgrid.club` como dominio.
6. Crear `/opt/fastgrid/deploy/web/.env` desde `.env.production.example` con permisos 600 (ver «Bootstrap único en el VPS»), con `APP_URL=https://fastgrid.club`, `TRUSTED_PROXIES` y las credenciales de Google y Steam.
7. Copiar los ficheros a `/opt/fastgrid` ejecutando el workflow `ci` (job `deploy`) o a mano, y seguir «Primer arranque con la planificación pausada»: el servidor se crea con `planning_paused=true`, de modo que `servers:reconcile` no toca el servidor de juego.
8. Verificar `https://fastgrid.club/healthz`, que `https://www.fastgrid.club` redirige a la raíz y un login con Google y con Steam.
9. Despausar: entrar a `https://fastgrid.club/admin/servers` como administrador (`docker compose exec app php artisan user:admin <email>` tras el primer login), abrir el servidor y desactivar el interruptor «Pausar planificación». Hacerlo fuera del sábado de 18:00 a 00:00 (Europe/Madrid).

## Primer arranque con la planificación pausada

La reconciliación (`servers:reconcile`, cada minuto) aplica la configuración de la semana al servidor de juego y puede reiniciarlo. En el primer arranque se crea el servidor ya pausado para que no toque nada hasta que Jose lo decida. Hacerlo fuera del sábado de 18:00 a 00:00 (Europe/Madrid).

```sh
cd /opt/fastgrid/deploy/web
docker compose up -d db redis
docker compose run --rm migrate
docker compose run --rm --no-deps -e HOME=/tmp app php artisan tinker --execute 'App\Models\Server::query()->updateOrCreate(["slug" => "main"], ["name" => "FastGrid", "ingame_prefix" => "FastGrid", "max_players" => 24, "credentials_ref" => "main", "driver" => App\Enums\ServerDriverType::ZinoPanel, "base_url" => "http://acevo-server:8090", "planning_paused" => true, "is_active" => true]);'
docker compose up -d
```

- No se ejecuta `db:seed`: el `DatabaseSeeder` crea un usuario de prueba y el servidor con el driver `fake`.
- Cuando la web esté verificada, quitar la pausa desde `/admin` (pantalla de servidores, interruptor «Pausar planificación»).
- Dar el rol de administrador tras el primer login: `docker compose exec app php artisan user:admin <email>`.

## Despliegue normal

Ventana: nunca el sábado de 18:00 a 00:00 (Europe/Madrid). El despliegue de la app no toca el servidor de juego.

1. El despliegue sale solo tras un push a `main` con `tests` y `ci` en verde, así que el push se hace fuera de la ventana. Para un despliegue manual, anotar el SHA del commit.
2. Lanzar el workflow `ci` con `workflow_dispatch` indicando ese SHA, o a mano:
   ```sh
   cd /opt/fastgrid && git pull --ff-only
   cd deploy/web
   sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=<sha>|" .env
   docker compose pull
   docker compose up -d --remove-orphans
   ```
3. Orden real: `migrate` (un solo uso, `php artisan migrate --force`) corre primero y `app`, `queue`, `scheduler` y `ssr` solo se recrean cuando termina bien; si la migración falla, el despliegue se detiene y los servicios viejos siguen. Las migraciones deben ser compatibles con la versión anterior (aditivas; los cambios destructivos en dos pasos).
4. Octane y la cola cargan el código nuevo porque los contenedores se recrean con la imagen nueva; no se usa `octane:reload`. El servicio `ssr` también se recrea, así que arranca con el bundle nuevo y no hace falta `inertia:stop-ssr`. Si el SSR no responde, Inertia sirve la página renderizada en cliente.
5. Comprobar:
   ```sh
   docker compose ps
   docker compose exec app curl -fsS http://127.0.0.1:8000/healthz
   ```

## Vuelta atrás

```sh
cd /opt/fastgrid/deploy/web
sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=<sha-anterior>|" .env
docker compose up -d
```

GHCR conserva cada imagen por su SHA. Las migraciones no se revierten en producción salvo plan explícito: por eso deben ser aditivas y la versión anterior tiene que funcionar con el esquema nuevo. Un cambio destructivo se hace en dos despliegues.

## Backups de PostgreSQL

El servicio `backup` (X32-X36) hace un `pg_dump` en formato personalizado una vez al día a las `BACKUP_TIME` (por defecto `04:30`, hora de Europe/Madrid) y lo cifra con `age` en el mismo flujo, antes de escribir nada a disco: el dump sin cifrar nunca toca el disco ni sale del contenedor. El servicio solo tiene la clave pública; la privada se guarda fuera del VPS.

- Retención en el volumen `fastgrid-backups`: 7 diarias (`daily/fastgrid-AAAAMMDD-HHMMSS.dump.age`) y 4 semanales (`weekly/fastgrid-AAAA-Wss.dump.age`, la primera copia de cada semana ISO).
- Copia fuera (X33): si `BACKUP_RCLONE_REMOTE` está definido, tras cada copia hace `rclone sync` del volumen a ese destino, así que el remoto tiene la misma retención. Si no está definido, solo hay copia local y el log y `/admin` lo dicen («Solo copia local»).
- Latido: tras cada copia correcta (y subida, si hay destino) escribe en Redis `heartbeat:backup` (marca de tiempo) y `heartbeat:backup:offsite` (`1` si subió fuera, `0` si no) en la base y con el prefijo de la caché de Laravel. Si algo falla no escribe el latido, deja el error en el log (`docker compose logs backup`) y reintenta a los 30 minutos. Cada minuto vuelve a publicar el latido del último backup correcto, para que un reinicio de Redis no lo borre.
- Si `/admin` ve el último backup correcto con más de 36 horas abre la alerta «backup antiguo» (X36).
- Sin `BACKUP_AGE_RECIPIENT` el servicio no arranca: el log dice qué falta y Docker lo reintenta. El resto de la web sigue funcionando.
- Redis no se respalda (X35).

### Generar la clave age (una vez, fuera del VPS)

En el portátil de Jose (o donde se custodie la clave), con `age` instalado (`dnf install age`, `apt install age` o `brew install age`):

```sh
age-keygen -o fastgrid-backup.key
chmod 600 fastgrid-backup.key
age-keygen -y fastgrid-backup.key
```

La última orden imprime la clave pública (`age1...`), que es lo único que va al VPS, en `BACKUP_AGE_RECIPIENT` del `.env`. El fichero `fastgrid-backup.key` es la clave privada: se guarda en el gestor de contraseñas y en una segunda copia offline. Sin ella los backups no se pueden leer; nunca se copia al VPS ni al repo.

### Configurar el destino externo con rclone

El destino está pendiente de elegir (X33). Cualquier backend de rclone sirve (S3 compatible, Backblaze B2, SFTP a otra máquina...). La configuración va en `deploy/web/.env.backup` (permisos 600, ignorado por git), que solo lee el servicio `backup`, con las variables `RCLONE_CONFIG_<NOMBRE>_*` de rclone. Ejemplo para un bucket S3 compatible:

```sh
cat > /opt/fastgrid/deploy/web/.env.backup <<'ENV'
RCLONE_CONFIG_OFFSITE_TYPE=s3
RCLONE_CONFIG_OFFSITE_PROVIDER=Other
RCLONE_CONFIG_OFFSITE_ENDPOINT=https://s3.ejemplo.com
RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=...
RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_OFFSITE_NO_CHECK_BUCKET=true
ENV
chmod 600 /opt/fastgrid/deploy/web/.env.backup
```

y en `.env`: `BACKUP_RCLONE_REMOTE=offsite:fastgrid-backups`. La credencial del destino debe poder escribir y borrar solo en ese bucket o carpeta. Comprobar y forzar una copia:

```sh
docker compose up -d backup
docker compose exec backup rclone lsd offsite:
docker compose exec backup fastgrid-backup run
docker compose exec backup rclone ls offsite:fastgrid-backups
```

La subida sale por la red `backup-egress`, que solo tiene este contenedor: el servicio no está en `edge` (no alcanza el panel del juego ni Caddy) y `backend` sigue siendo interna.

### Operación

```sh
docker compose logs --tail=50 backup
docker compose exec backup fastgrid-backup run
docker compose exec backup ls -l /backups/daily /backups/weekly
```

`fastgrid-backup run` fuerza una copia completa (cifrado, retención, subida y latido) sin esperar a la hora programada. Si el contenedor arranca después de la hora programada y ese día aún no hay copia correcta, la hace al arrancar.

## Restauración (X34)

Probada en local el 10-11-2026 con un proyecto de compose aislado: copia forzada, restauración en una base vacía de otro proyecto y mismos recuentos de filas y el mismo hash de los correos en origen y destino. Se repite antes del lanzamiento y cada trimestre.

El procedimiento sirve para un VPS nuevo, para netcup o para una máquina local. Necesita Docker, el repo clonado y la clave privada.

1. Preparar el entorno limpio sin arrancar la app (los pasos de «Bootstrap único» hasta el `.env`; mismas o nuevas credenciales de BD):
   ```sh
   cd /opt/fastgrid/deploy/web
   docker compose up -d --wait db redis
   ```
   La base debe estar vacía (volumen `fastgrid-pgdata` nuevo). Si se restaura sobre el mismo VPS, antes `docker compose down` y `docker volume rm fastgrid-prod_fastgrid-pgdata` (destructivo: solo si se ha decidido volver a un backup).
2. Elegir el backup. Del volumen local:
   ```sh
   docker compose run --rm --no-deps --entrypoint ls backup -l /backups/daily /backups/weekly
   docker compose cp backup:/backups/daily/fastgrid-AAAAMMDD-HHMMSS.dump.age ./restore.dump.age
   ```
   (`docker compose cp` necesita el contenedor `backup` creado; en un entorno nuevo sin volumen, del remoto: `docker compose run --rm --no-deps --user "$(id -u):$(id -g)" --entrypoint rclone -v "$PWD:/restore" backup copyto offsite:fastgrid-backups/daily/fastgrid-AAAAMMDD-HHMMSS.dump.age /restore/restore.dump.age`).
3. Copiar la clave privada al host en un fichero temporal con permisos 600 (por ejemplo `/root/fastgrid-backup.key`).
4. Descifrar y restaurar en flujo, sin dejar el dump en claro en disco. Con `age` en el host:
   ```sh
   age -d -i /root/fastgrid-backup.key restore.dump.age \
     | docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error --single-transaction'
   ```
   Sin `age` en el host, con la imagen de backup en un contenedor de un solo uso sin red (no con `docker compose run`, que mezcla su salida con el flujo):
   ```sh
   docker run --rm -i --network none --user "$(id -u):$(id -g)" --entrypoint age \
       -v "$PWD/restore.dump.age:/restore/backup.dump.age:ro" -v /root/fastgrid-backup.key:/restore/key.txt:ro \
       fastgrid-backup:pg18.6-1 -d -i /restore/key.txt /restore/backup.dump.age \
     | docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error --single-transaction'
   ```
   `--single-transaction` hace que un fallo deje la base vacía en vez de a medias.
5. Borrar la clave y el fichero descargado: `shred -u /root/fastgrid-backup.key restore.dump.age`.
6. Comprobar los datos antes de abrir la web:
   ```sh
   docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM users" -c "SELECT max(batch) FROM migrations"'
   ```
7. Aplicar migraciones posteriores al backup y arrancar todo (con la planificación pausada si es un servidor nuevo, ver «Primer arranque»):
   ```sh
   docker compose run --rm migrate
   docker compose up -d
   ```
8. Verificar `/healthz`, un login y `/admin` (latidos en verde). Los usuarios tendrán que volver a iniciar sesión: Redis no se restaura.

## Verificaciones de infraestructura

- Compose: `deploy/web/check-compose.sh` valida el fichero y falla si hay puertos publicados, etiquetas sin fijar o `latest`, servicios sin `read_only`, `cap_drop: ALL` y `no-new-privileges`, PostgreSQL o Redis en `edge` o la red `backend` no interna. Corre en el CI.
- Aislamiento de red (a mano, en el VPS o en la prueba de humo): desde un contenedor de la red `edge` ni el nombre `db`/`redis` resuelve ni las IP de esos contenedores responden:
  ```sh
  docker run --rm --network edge --entrypoint sh ghcr.io/jose-ps/fastgrid-club:<sha> -c 'getent hosts db redis; timeout 3 bash -c "</dev/tcp/<ip-de-db>/5432" && echo ABIERTO || echo inalcanzable'
  ```
  En el host: `ss -ltn | grep -E ':(5432|6379)\b'` no debe mostrar nada del proyecto.
- Usuario y solo lectura: `docker inspect <contenedor> --format '{{.Config.User}} {{.HostConfig.ReadonlyRootfs}} {{.HostConfig.CapDrop}}'`.
- Latidos (X11, X39): el scheduler escribe `heartbeat:scheduler` cada minuto (`heartbeats:beat`) y encola cada minuto el trabajo `RecordQueueHeartbeat`, que al procesarlo `queue:work` escribe `heartbeat:queue`; así el latido de la cola prueba que la cola procesa, no solo que el proceso existe. Los healthchecks de `queue` y `scheduler` ejecutan `php artisan heartbeats:alive <origen> --max-age=180` (falla si el latido tiene más de 3 minutos). Si el scheduler se cae, `queue` también queda `unhealthy` porque nadie encola su latido. `heartbeats:check` (cada minuto) abre la alerta si scheduler o cola llevan más de 5 minutos sin latido o el backup más de 36 horas, y la cierra al recuperarse. Como un scheduler caído no puede abrir su propia alerta, `/admin` calcula el estado de los tres latidos al renderizar (verde o rojo con su antigüedad). Docker no reinicia un contenedor `unhealthy`: el aviso es el estado en `/admin` y `docker compose ps`.
- Comprobar a mano: `docker compose exec app php artisan heartbeats:alive backup --max-age=129600` y `docker compose exec app php artisan heartbeats:check`.

## Notas de diseño

- La base es `dunglas/frankenphp` con etiqueta exacta y resumen (PHP 8.4); `setcap -r` quita la capacidad de red del binario, necesaria para que arranque con `cap_drop: ALL`. Escucha en el 8000 (sin puerto privilegiado).
- El front y el bundle SSR se compilan en una etapa intermedia que lleva PHP y Node, porque el plugin de Wayfinder ejecuta `php artisan` durante el build. La imagen final solo lleva el binario de Node 22 y el bundle SSR (compilado con `ssr.noExternal`, sin `node_modules`).
- Los cachés de Laravel se generan al arrancar (`php artisan optimize` en el entrypoint) en un `tmpfs` en `bootstrap/cache`; `storage` es un volumen compartido por los servicios de la app. Caddy interno de FrankenPHP escribe en `/tmp/caddy`.
- Redis sin persistencia, con contraseña y `maxmemory-policy volatile-lru` para que los trabajos de la cola (sin TTL) no se expulsen.
- Los límites de recursos son los de X12 salvo `scheduler` (384 MB: con 128 MB y con 192 MB el cgroup mató `servers:poll` en los picos del minuto) y `ssr` (192 MB, medido ~145 MB de pico en arranque). Revisar tras medir con jugadores.
- Los logs del acceso de Caddy no incluyen la query ni el token de `/login/link/{token}` (filtro `request>uri` del fragmento).
- Tamaño de la imagen: ~1,2 GB, casi todo la base de FrankenPHP (~840 MB).

## Pendiente

- Dominio (X20): decidido, `fastgrid.club`; falta ejecutar los pasos de «Primer despliegue en fastgrid.club». Si Cloudflare no se mantiene, quitar `import solo-cloudflare` y usar `{remote_host}` en `header_up X-Forwarded-For`.
- Destino externo del backup (X33): elegir proveedor, crear `.env.backup` y definir `BACKUP_RCLONE_REMOTE`. Hasta entonces solo hay copia local en el VPS.
- Alertas por correo (X38 y aviso de latidos y backup al admin): de momento las alertas solo se ven en `/admin`; la comprobación externa de disponibilidad está sin elegir.
- Ruta interna de resultados del spike (X18): no existe aún; cuando exista, no se enruta desde Caddy.
- Medición con jugadores (X43) y ajuste de límites.
