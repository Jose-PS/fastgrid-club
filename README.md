# FastGrid

[FastGrid](https://fastgrid.club) es una comunidad de carreras de Assetto Corsa EVO. Cada semana la comunidad vota el circuito y los coches, hay un servidor dedicado con el evento en marcha y la web publica las clasificaciones y las mejores vueltas.

Este repositorio es la web (Laravel + Inertia/React). El servidor de juego vive en [game-server/](game-server/README.md).

## Datos que se guardan

Solo lo que hace falta para iniciar sesión, votar y mostrar las clasificaciones:

- Cuenta: nombre, email (opcional), idioma, fecha del último acceso y si quieres aparecer en las clasificaciones.
- Inicio de sesión con Steam (OpenID): el SteamID64, que es público, el nombre de perfil y la URL del avatar. Steam no entrega ni se guarda ninguna contraseña.
- Inicio de sesión con Google: el identificador de Google, el nombre, el email y la URL del avatar. No se guarda ninguna contraseña de Google.
- Enlace mágico por email: el email, un hash del token (no el token), su caducidad, cuándo se usó y la IP desde la que se pidió.
- Passkeys, si las activas: la credencial pública que genera tu dispositivo.
- Sesiones web: identificador de sesión, IP y agente de usuario.
- Pilotos del servidor de juego: SteamID64, último nick usado en el juego y, por cada vuelta, el tiempo, circuito, coche, número de vuelta, validez y fecha. De ahí salen las clasificaciones y las mejores vueltas.
- Votos y propuestas de circuito y coches.

## Stack

Laravel 13, PHP 8.4, Inertia v3 con React 19, Tailwind 4, PostgreSQL, Redis y FrankenPHP con Octane.

## Desarrollo local

Requiere Docker y [Laravel Sail](https://laravel.com/docs/sail).

```
cp .env.example .env
vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed
vendor/bin/sail npm install
vendor/bin/sail npm run dev
```

Puertos locales: web 8085, Vite 5175, PostgreSQL 54325, Redis 63795.

## Flujo de calidad

Mientras se desarrolla y antes de proponer un commit se pasan solo los tests afectados (`vendor/bin/sail artisan test --compact <fichero>` o `--filter=`). Antes del push a la rama principal, por este orden:

1. Suite completa: `vendor/bin/sail artisan test --compact`
2. Pint: `vendor/bin/sail bin pint --format agent`. Si cambia algo, se repiten los tests afectados.
3. Front:
    - `vendor/bin/sail npm run check` (formato y lint, limitado al código de la app).
    - `vendor/bin/sail npm run types:check`
    - `vendor/bin/sail composer types:check` (PHPStan).
    - `vendor/bin/sail npm run test` (Vitest).
    - `vendor/bin/sail npm run build`
    - `vendor/bin/sail npm run budget` (falla si la portada pasa de 150 KB de JS gzip, 30 KB de CSS gzip o 100 KB de fuentes).
4. Análisis con https://checkpoint.web.ap.it. Se corrige lo que salga y se repite.

Para arreglar el formato solo se usa `vendor/bin/sail npm run check:fix -- resources/js`, siempre con ruta.

## Licencia

[GNU AGPL-3.0](LICENSE). Si despliegas una versión modificada de este software en un servicio accesible por red, debes publicar el código fuente de tus cambios bajo la misma licencia.

## English

FastGrid ([fastgrid.club](https://fastgrid.club)) is an Assetto Corsa EVO racing community: every week members vote on the track and cars, a dedicated server runs the event, and the site publishes leaderboards and best laps. This repository is the web app (Laravel 13, PHP 8.4, Inertia v3 + React 19, Tailwind 4, PostgreSQL, Redis, FrankenPHP/Octane). For local development use Laravel Sail (`cp .env.example .env`, `sail up -d`, `sail artisan key:generate`, `sail artisan migrate --seed`, `sail npm install`, `sail npm run dev`). Stored data is limited to what login, voting and leaderboards need (Steam ID, display name and avatar; Google ID, name, email and avatar; magic-link hashes; lap times); no Steam or Google passwords are stored. Licensed under AGPL-3.0: if you run a modified version as a public network service you must publish your changes.
