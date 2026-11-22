# Instalación

## En el VPS

```
git clone https://github.com/zino1337/acevo-server.git ~/acevo-server
cp deploy/server/docker-compose.override.yml ~/acevo-server/
cp deploy/server/.env.example ~/acevo-server/.env
chmod 600 ~/acevo-server/.env
```

Editar `.env` con las credenciales reales.

## Override de compose

- `cpus: 1.5`, `mem_limit: 2g`, `memswap_limit: 2.5g`, `restart: unless-stopped`.
- Publica `9700/tcp`, `9700/udp` y `8080/tcp`.
- El dashboard (8090) no se publica en el host: el contenedor se une a la red docker externa `edge` y lo sirve el Caddy compartido.

## Login de Steam

La cuenta tiene Steam Guard móvil. La aprobación por notificación la bloquea Steam como sospechosa (login desde el datacenter de OVH). El login automático del contenedor siempre pasa la contraseña, por eso vuelve a pedir confirmación móvil y falla (exit code 5).

Solución: `AUTO_UPDATE=false` y descarga manual interactiva.

```
cd ~/acevo-server
docker compose exec -it acevo-server steamcmd
@sSteamCmdForcePlatformType windows
force_install_dir /data/server
login <usuario>
app_update 4564210 validate
quit
```

`login` pide la contraseña y después el código Steam Guard de la app.

Después:

```
docker compose exec -T acevo-server chown -R 1001:1001 /data /root/.local/share/Steam
docker compose down && docker compose up -d
```

App id del servidor dedicado: `4564210`. Unos 435 MB de descarga y 1.1 GB instalado.

## Panel web

https://acevo.<DOMINIO>

El fragmento `deploy/sites/acevo.caddy` va en `/opt/edge/sites/` del VPS. Usa el Cloudflare Origin Certificate de <DOMINIO> (cubre `*.<DOMINIO>`).

Aplicar:

```
docker exec edge-caddy-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker exec edge-caddy-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
```

DNS: registro A `acevo` -> `<IP_DEL_VPS>`, proxied en Cloudflare.

## Cloudflare WAF

La regla custom «Bloquear spam» de la zona de <DOMINIO> bloqueaba `/static/` (CSS/JS del panel). Se cambió esa línea a:

```
(starts_with(lower(http.request.uri.path), "/static/") and http.host ne "acevo.<DOMINIO>")
```

## Puertos de juego

9700 TCP+UDP y 8080 TCP (listado/HTTP) van directos a la IP, sin pasar por Cloudflare. firewalld no necesitó cambios (Docker publica los puertos).
