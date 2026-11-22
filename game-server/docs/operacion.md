# Operación

## Panel

Panel web del autor del repo (no es oficial de Kunos), en Python stdlib (`dashboard/app.py`). API JSON con Basic Auth (usuario `admin`):

- `/api/config`
- `/api/configs`
- `/api/configs/get`
- `/api/server/status`
- `/api/metadata`

Guardar ajustes en el panel reinicia el servidor y expulsa a los jugadores.

Con el panel, su configuración (`/data/server_launcher.json`, `config_source=dashboard`) manda sobre el `.env`. Al guardar desde el panel, la contraseña de piloto quedó vacía.

## Configuración actual del panel

- Clasificación de 15 min y carrera de 15 min por tiempo (desde el 22-10-2026; antes, 8 vueltas). Sin entrenamientos ni warmup.
- Máximo de 10 jugadores. Manda el panel: el `SERVER_MAX_PLAYERS=2` del `.env` no se aplica.
- Contraseña de piloto vacía. El servidor es público y hoy entraron desconocidos.

## Actualizar el servidor

Repetir el procedimiento manual de SteamCMD de [instalacion.md](instalacion.md).

## Comandos

Desde `~/acevo-server` en el VPS:

```
docker compose up -d
docker compose stop
docker compose logs -f
docker stats acevo-server
```

### Revisar logs

Los logs viven en el json-file de Docker desde que se creó el contenedor: unos 10 MB al día, sin rotación. Sobreviven a los reinicios desde el panel, pero `docker compose down` los borra.

Desde `~/acevo-server`:

```
docker compose logs --since 24h --no-log-prefix acevo-server | grep -a "ConnectionRequest\|Creating Car\|Destroying Car"
docker compose logs --since 24h --no-log-prefix acevo-server | grep -a "setSessionPhase"
docker compose logs --since 24h --no-log-prefix acevo-server | grep -a "\[error\]\|10054\|High client ping"
docker compose logs --since 24h --no-log-prefix acevo-server | grep -a "Server updated:"
```

## Mediciones (prueba del 20-10-2026)

- En reposo: ~450-890 MB de RAM y ~16-18 % de CPU.
- Con 1 jugador: ~24 % de CPU y ~450 MB.
- Ping en partida: 45 ms desde España.
- El listado del juego muestra ~120-126 ms frente a 45 ms en partida. Medido el 22-10-2026 con captura en el contenedor: el listado manda un ping UDP de 38 bytes al 9700 y el servidor responde en 2-10 ms (~5,5 ms de media). Ping ICMP desde España: 46 ms.
- El inflado lo pone el cliente del juego y afecta a todos los servidores: EVOHUB (Francia) sale a ~117, otros cercanos a ~70. La lista muestra ~2,5× el ping real; en partida se juega al ping real.
- No se arregla desde el VPS. La cifra de la lista solo baja con un servidor más cercano a los jugadores (España).

### Con 4 jugadores (20-10-2026, 21:24–21:38 UTC)

- Throttling de CPU del contenedor (cpu.stat del cgroup) prácticamente nulo: 17 periodos frenados de ~20.000 en ese tramo (~0,08 %).
- Avisos «Server Late» esporádicos (0,17–0,52 s). También aparecen sin jugadores, así que no parecen ligados a la carga.
- «High client ping» solo aparece al entrar alguien, no durante la carrera.
- Load del VPS ~0,72 sobre 2 núcleos.
- Con 2 jugadores después: ~27 % de CPU y ~574 MB.
- No hay histórico de % de CPU. Para cifras reales, registrar `docker stats` durante una sesión.

## Incidencias (20-10-2026)

### Jugador con «server unresponsive»

Llegaba a conectar: el servidor registró ConnectionRequest successful y le creó el coche. Pero le medía pings de 1,6–2,6 s («High client ping») y su cliente cortaba la conexión a los pocos segundos (error TCP 10054, reset del cliente).

El servidor estaba sano: puertos accesibles, carga baja y otros jugadores entrando. El problema era su red, y lo resolvió él.

Para diagnosticarlo, buscar en los logs ConnectionRequest, High client ping y 10054 (ver Comandos).

### Cuelgue al entrar con la carrera por vueltas en marcha

El juego se queda en la pantalla de carga aunque se oyen los coches. En el servidor la entrada sí se completa (ClientLoaded, «teleported to pit position»).

Causa probable, deducida de los logs y sin confirmar: al arrancar una carrera por vueltas, el servidor pasa en el mismo milisegundo de la fase «Session» a «Overtime_Waiting_For_Leader» y «Overtime_Waiting_For_Others». Es decir, la trata como acabada desde el inicio, y quien entra espera a la siguiente sesión. En otros servidores (carreras por tiempo, se supone) se entra bien a mitad de carrera.

Se repitió la noche del 21-10-2026 (salidas de las 23:46 y 00:23 hora peninsular). Decisión (22-10-2026): carrera por tiempo de 15 min para comprobar si desaparece. Si en la próxima carrera la fase se queda en «Session» sin saltar a «Overtime», queda resuelto.

## Sesión del 21-10-2026

Horas en hora peninsular (CEST). Los logs van en UTC (2 h menos).

| Piloto | Conexión |
|---|---|
| Jose PS | 22:15–00:23 (varias reconexiones al principio: 22:15, 22:29, 22:33, 22:48) |
| Roi Campos | 22:49–00:41 |
| Icebergslam | 23:03–23:25 |
| baba86 baba | 23:06–23:15 |
| Omiley O'Neal | 23:35–23:38 |

Máximo 4 a la vez (23:06–23:15). Nadie después de las 00:41.

Incidencias:

- A las 00:07 se desconectaron Jose y Roi a la vez (2 s de diferencia), un minuto después de acabar la carrera y recolocarse los coches. Reconectaron 30 s después. Causa sin confirmar (posiblemente el propio ciclo de sesiones).
- «High client ping 760» de Roi a las 00:23.
- Error «ServerEngineChannel: doesn't implement message handler for message WeatherNoiseData»: sale en cada arranque del servidor, es interno y sin efecto.
- De madrugada, errores HTTP («request has too little data», «Failed to receive data or connection closed») sin nadie conectado: sondeos o escáneres al puerto del listado, sin efecto.

El contenedor llevaba 44 h arriba sin reinicios.

## Pendientes

- Cambiar la contraseña de Steam: quedó visible en la lista de procesos del contenedor porque el script de update la pasa por argumentos.
- Los ficheros de `deploy/` no se despliegan automáticamente.
- Comprobar en los logs de la próxima carrera que la carrera por tiempo no salta a «Overtime» al empezar.
- Decidir si poner contraseña de piloto.

## Desinstalar

1. `docker compose down`
2. Borrar `~/acevo-server` y `/opt/edge/sites/acevo.caddy`.
3. Recargar Caddy.
4. Quitar el DNS `acevo` y la excepción en la regla WAF.
