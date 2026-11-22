#!/bin/sh
set -eu

php artisan env:check

php artisan optimize

exec "$@"
