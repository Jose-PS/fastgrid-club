#!/bin/sh
set -eu
umask 077

BACKUP_DIR=${BACKUP_DIR:-/backups}
BACKUP_TIME=${BACKUP_TIME:-04:30}
BACKUP_RCLONE_REMOTE=${BACKUP_RCLONE_REMOTE:-}
BACKUP_REDIS_DB=${BACKUP_REDIS_DB:-1}
BACKUP_REDIS_PREFIX=${BACKUP_REDIS_PREFIX:-fastgrid-database-fastgrid-cache-}
BACKUP_RETRY_SECONDS=${BACKUP_RETRY_SECONDS:-1800}
KEEP_DAILY=7
KEEP_WEEKLY=4
MAX_AGE_SECONDS=129600
STATE_FILE="$BACKUP_DIR/.last-success"
STARTED_FILE=/tmp/fastgrid-backup.started
LOCK_DIR=/tmp/fastgrid-backup.lock

log() {
    printf '%s fastgrid-backup: %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*"
}

error() {
    log "ERROR: $*"
}

check_config() {
    missing=""
    for var in BACKUP_AGE_RECIPIENT PGHOST PGDATABASE PGUSER PGPASSWORD REDIS_HOST REDISCLI_AUTH; do
        eval "value=\${$var:-}"
        if [ -z "$value" ]; then
            missing="$missing $var"
        fi
    done

    if [ -n "$missing" ]; then
        error "faltan variables obligatorias:$missing. Sin BACKUP_AGE_RECIPIENT (clave pública age) no se hace ningún backup; ver deploy/README.md."
        exit 1
    fi

    case "$BACKUP_AGE_RECIPIENT" in
        age1*) ;;
        *)
            error "BACKUP_AGE_RECIPIENT debe ser una clave pública age (empieza por age1). Nunca la clave privada."
            exit 1
            ;;
    esac

    if ! printf 'x' | age -r "$BACKUP_AGE_RECIPIENT" >/dev/null 2>&1; then
        error "BACKUP_AGE_RECIPIENT no es una clave pública age válida."
        exit 1
    fi

    case "$BACKUP_TIME" in
        [01][0-9]:[0-5][0-9] | 2[0-3]:[0-5][0-9]) ;;
        *)
            error "BACKUP_TIME debe tener el formato HH:MM (por ejemplo 04:30)."
            exit 1
            ;;
    esac

    if [ ! -w "$BACKUP_DIR" ]; then
        error "no se puede escribir en $BACKUP_DIR."
        exit 1
    fi
}

minutes_of() {
    hours=${1%:*}
    minutes=${1#*:}
    echo $(( ${hours#0} * 60 + ${minutes#0} ))
}

publish_heartbeat() {
    if [ ! -f "$STATE_FILE" ]; then
        return 0
    fi

    read -r at offsite < "$STATE_FILE"

    reply=$(redis-cli -h "$REDIS_HOST" -p "${REDIS_PORT:-6379}" -n "$BACKUP_REDIS_DB" --no-auth-warning \
        MSET "${BACKUP_REDIS_PREFIX}heartbeat:backup" "$at" "${BACKUP_REDIS_PREFIX}heartbeat:backup:offsite" "$offsite" 2>&1) || true

    if [ "$reply" != "OK" ]; then
        error "no se pudo escribir el latido en Redis: $reply"
        return 1
    fi
}

prune() {
    directory=$1
    keep=$2

    ls -1 "$directory" | grep -E '^fastgrid-.*\.dump\.age$' | sort -r | tail -n +"$((keep + 1))" | while read -r old; do
        rm -f "$directory/$old"
        log "retención: borrada $directory/$old"
    done
}

run_backup() {
    if ! mkdir "$LOCK_DIR" 2>/dev/null; then
        error "ya hay un backup en curso."
        return 1
    fi
    trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT

    status=0
    backup_once || status=$?

    rmdir "$LOCK_DIR" 2>/dev/null || true
    trap - EXIT

    return "$status"
}

backup_once() {
    mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"
    rm -f "$BACKUP_DIR"/daily/.*.partial

    name="fastgrid-$(date '+%Y%m%d-%H%M%S').dump.age"
    target="$BACKUP_DIR/daily/$name"
    partial="$BACKUP_DIR/daily/.$name.partial"

    log "inicio del backup $name"

    if ! (set -o pipefail; pg_dump --format=custom | age -r "$BACKUP_AGE_RECIPIENT" -o "$partial"); then
        rm -f "$partial"
        error "pg_dump o el cifrado fallaron; no se ha escrito ningún backup."
        return 1
    fi

    if [ "$(head -c 21 "$partial")" != "age-encryption.org/v1" ]; then
        rm -f "$partial"
        error "el fichero generado no tiene la cabecera de age; descartado."
        return 1
    fi

    if ! mv "$partial" "$target"; then
        rm -f "$partial"
        error "no se pudo mover el backup a $target."
        return 1
    fi
    size=$(wc -c < "$target" | tr -d ' ')

    weekly="$BACKUP_DIR/weekly/fastgrid-$(date '+%G-W%V').dump.age"
    if [ ! -f "$weekly" ]; then
        ln "$target" "$weekly" 2>/dev/null || cp "$target" "$weekly"
        log "copia semanal: $weekly"
    fi

    prune "$BACKUP_DIR/daily" "$KEEP_DAILY"
    prune "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"

    offsite=0
    if [ -n "$BACKUP_RCLONE_REMOTE" ]; then
        if ! rclone sync "$BACKUP_DIR" "$BACKUP_RCLONE_REMOTE" \
            --include '/daily/fastgrid-*.dump.age' --include '/weekly/fastgrid-*.dump.age' \
            --retries 3 --low-level-retries 10 --stats 0 --log-level NOTICE; then
            error "la subida a $BACKUP_RCLONE_REMOTE falló; el backup local $target existe, pero no se escribe el latido."
            return 1
        fi
        offsite=1
    fi

    if ! { printf '%s %s\n' "$(date +%s)" "$offsite" > "$STATE_FILE.tmp" && mv "$STATE_FILE.tmp" "$STATE_FILE"; }; then
        error "no se pudo guardar el estado del último backup."
        return 1
    fi

    if [ "$offsite" = "1" ]; then
        log "backup correcto: $target ($size bytes), copiado fuera en $BACKUP_RCLONE_REMOTE"
    else
        log "backup correcto: $target ($size bytes), solo copia local (BACKUP_RCLONE_REMOTE sin definir)"
    fi

    publish_heartbeat || true
}

last_success_day() {
    if [ -f "$STATE_FILE" ]; then
        read -r at _ < "$STATE_FILE"
        date -d "@$at" '+%F'
    fi
}

daemon() {
    check_config
    date +%s > "$STARTED_FILE"

    if [ -n "$BACKUP_RCLONE_REMOTE" ]; then
        destination="local y $BACKUP_RCLONE_REMOTE"
    else
        destination="solo local (BACKUP_RCLONE_REMOTE sin definir)"
    fi
    log "en marcha: backup diario a las $BACKUP_TIME (${TZ:-UTC}), retención $KEEP_DAILY diarias y $KEEP_WEEKLY semanales, destino $destination"

    trap 'log "parada"; exit 0' TERM INT

    scheduled=$(minutes_of "$BACKUP_TIME")
    done_day=$(last_success_day)
    retry_at=0

    while :; do
        publish_heartbeat || true

        today=$(date '+%F')
        current=$(minutes_of "$(date '+%H:%M')")
        epoch=$(date +%s)

        if [ "$current" -ge "$scheduled" ] && [ "$done_day" != "$today" ] && [ "$epoch" -ge "$retry_at" ]; then
            if run_backup; then
                done_day=$today
                retry_at=0
            else
                retry_at=$((epoch + BACKUP_RETRY_SECONDS))
                error "nuevo intento en $((BACKUP_RETRY_SECONDS / 60)) minutos."
            fi
        fi

        sleep 60 &
        wait $! || true
    done
}

health() {
    now=$(date +%s)

    if [ -f "$STATE_FILE" ]; then
        read -r at _ < "$STATE_FILE"
        if [ $((now - at)) -le "$MAX_AGE_SECONDS" ]; then
            echo "último backup correcto hace $(((now - at) / 60)) minutos"
            exit 0
        fi
    fi

    if [ -f "$STARTED_FILE" ] && [ $((now - $(cat "$STARTED_FILE"))) -le "$MAX_AGE_SECONDS" ]; then
        echo "sin backup reciente todavía; en marcha desde hace $(((now - $(cat "$STARTED_FILE")) / 60)) minutos"
        exit 0
    fi

    echo "no hay backup correcto en las últimas 36 horas"
    exit 1
}

case "${1:-daemon}" in
    daemon)
        daemon
        ;;
    run)
        check_config
        run_backup
        ;;
    health)
        health
        ;;
    *)
        echo "uso: fastgrid-backup [daemon|run|health]" >&2
        exit 2
        ;;
esac
