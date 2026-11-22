#!/bin/sh
set -eu

here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
: > "$work/.env"

render() {
    IMAGE_TAG=check DB_DATABASE=x DB_USERNAME=x DB_PASSWORD=x REDIS_PASSWORD=x \
        docker compose --project-directory "$work" -f "$here/compose.yaml" config "$@"
}

if ! config=$(render --format json); then
    echo "El compose no valida" >&2
    exit 1
fi

fail=0

published=$(printf '%s' "$config" | jq -r '.services | to_entries[] | select((.value.ports // []) | length > 0) | .key')
if [ -n "$published" ]; then
    echo "Servicios con puertos publicados: $published" >&2
    fail=1
fi

latest=$(printf '%s' "$config" | jq -r '.services | to_entries[] | select(.value.image | (split("@")[0] | (endswith(":latest") or (contains(":") | not)))) | .key')
if [ -n "$latest" ]; then
    echo "Servicios con imagen sin etiqueta fijada o con latest: $latest" >&2
    fail=1
fi

unhardened=$(printf '%s' "$config" | jq -r '.services | to_entries[] | select((.value.read_only != true) or ((.value.cap_drop // []) | index("ALL") | not) or ((.value.security_opt // []) | index("no-new-privileges:true") | not) or (.value.privileged == true)) | .key')
if [ -n "$unhardened" ]; then
    echo "Servicios sin read_only, cap_drop ALL o no-new-privileges: $unhardened" >&2
    fail=1
fi

exposed=$(printf '%s' "$config" | jq -r '.services | to_entries[] | select(.key == "db" or .key == "redis") | select(.value.networks | keys | index("edge")) | .key')
if [ -n "$exposed" ]; then
    echo "Servicios de datos en la red edge: $exposed" >&2
    fail=1
fi

backup_edge=$(printf '%s' "$config" | jq -r '.services.backup.networks // {} | keys | index("edge") // empty')
if [ -n "$backup_edge" ]; then
    echo "El servicio backup no debe estar en la red edge" >&2
    fail=1
fi

internal=$(printf '%s' "$config" | jq -r '.networks.backend.internal // false')
if [ "$internal" != "true" ]; then
    echo "La red backend no es interna" >&2
    fail=1
fi

if [ "$fail" -eq 0 ]; then
    echo "Compose de producción correcto"
fi

exit "$fail"
