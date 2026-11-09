#!/bin/sh
# Prépare l'application au démarrage du conteneur PHP, sans action manuelle.
set -e

# Le fichier .env n'est pas versionné : il est créé à partir du modèle.
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

# Le secret applicatif est généré localement, il ne figure jamais dans le dépôt.
if [ -f .env ] && grep -q '^APP_SECRET=$' .env; then
    sed -i "s/^APP_SECRET=$/APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')/" .env
fi

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
