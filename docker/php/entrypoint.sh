#!/bin/sh
set -e

# En desarrollo el código se monta como volumen; instala dependencias si faltan.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
