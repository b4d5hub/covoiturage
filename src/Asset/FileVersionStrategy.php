<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Version des fichiers publics (styles, scripts, images) tirée de leur date de modification :
 * « app.js?v=1763041234 ». Le navigateur recharge un fichier dès qu'il change, sans étape de construction.
 */
final class FileVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir.'/'.ltrim(parse_url($path, \PHP_URL_PATH) ?? $path, '/');

        return is_file($file) ? (string) filemtime($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        if ('' === $version) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').'v='.$version;
    }
}
