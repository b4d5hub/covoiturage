#!/bin/sh
# Prépare l'application au démarrage du conteneur PHP, sans action manuelle.
set -e

# Un indicateur de démarrage précédent ne doit jamais faire croire que l'application est prête.
if [ "$1" = 'php-fpm' ]; then
    mkdir -p var
    rm -f var/.ready
fi

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

# Au démarrage du serveur : base mise à jour, puis données de démonstration si la base est vide.
# Le conteneur n'est déclaré prêt (fichier var/.ready) qu'une fois ces étapes terminées.
if [ "$1" = 'php-fpm' ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console app:demo-data:load-if-empty --no-interaction
    touch var/.ready
fi

exec "$@"
