# acevo-server

Servidor dedicado de Assetto Corsa EVO en Docker. Usa el proyecto comunitario [zino1337/acevo-server](https://github.com/zino1337/acevo-server), que ejecuta el servidor Windows bajo Proton con renderizado por software (sin GPU).

## Dónde corre

VPS compartido con otras webs detrás del mismo Caddy. Panel web en https://acevo.<DOMINIO>.

## Estructura

```
(rutas relativas a game-server/)
deploy/server/docker-compose.override.yml   override del compose upstream (límites, puertos, red edge)
deploy/server/.env.example                  variables del servidor, con marcadores en lugar de secretos
deploy/sites/acevo.caddy                    fragmento del Caddy compartido (/opt/edge/sites/ en el VPS)
docs/instalacion.md                         cómo se montó
docs/operacion.md                           uso diario, mediciones, pendientes y desinstalación
```

El `.env` real nunca se versiona (`.gitignore`).

## Web

La app Laravel que gestiona el servidor vive en la raíz del repo.
