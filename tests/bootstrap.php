<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Les tests s'exécutent sur le schéma réel, issu des migrations.
passthru('php '.dirname(__DIR__).'/bin/console --env=test doctrine:migrations:migrate --no-interaction --quiet', $exitCode);

if (0 !== $exitCode) {
    fwrite(STDERR, "Migrations impossibles : démarrez la base de test avec « docker compose --profile test up -d --wait db_test ».\n");
    exit(1);
}
