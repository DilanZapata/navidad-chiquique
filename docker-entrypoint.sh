#!/bin/sh
set -e
# El volumen se monta como root: se lo entregamos a Apache para que pueda escribir la base.
mkdir -p "$FIESTA_DATA_DIR"
chown -R www-data:www-data "$FIESTA_DATA_DIR"
exec docker-php-entrypoint "$@"
