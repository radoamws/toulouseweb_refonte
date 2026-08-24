<?php

namespace App\Services\Migration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Résout et copie les fichiers image legacy retrouvés sous `old/backEnd/public/`
 * vers `storage/app/public/{domaine}/`, pour les commandes `images:*` (voir
 * TECHNICAL_DOCUMENTATION.md §13 — correction de l'audit initial qui n'avait
 * repéré que 48 fichiers dans `old/` faute d'avoir exploré `old/backEnd/public`,
 * qui en contient en réalité plus de 33 000).
 *
 * Les valeurs stockées en base (`t_article.image`, `t_news.img_path`,
 * `t_cine_film.image`...) ne correspondent pas toujours EXACTEMENT au nom de
 * fichier réel sur disque : le site servait des variantes `.webp` compressées
 * dont le nom de base est identique mais l'extension diffère de l'originale
 * (`.jpg`/`.gif`/`.png`). La résolution tente donc d'abord une correspondance
 * exacte (nom + extension), puis une correspondance par nom de base sans
 * extension (insensible à la casse) — c'est cette seconde passe qui retrouve
 * l'écrasante majorité des fichiers réels.
 */
class LegacyImageImporter
{
    /** @var array<string, string> nom de fichier exact (minuscules) => chemin source absolu */
    protected array $exactIndex = [];

    /** @var array<string, string> nom de fichier sans extension (minuscules) => chemin source absolu */
    protected array $basenameIndex = [];

    protected int $indexedFiles = 0;

    /** @param array<int, string> $searchDirs Répertoires à indexer, dans l'ordre de priorité (le premier trouvé gagne). */
    public function __construct(array $searchDirs)
    {
        foreach ($searchDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $this->indexedFiles++;
                $filename = $file->getFilename();
                $this->exactIndex[strtolower($filename)] ??= $file->getPathname();
                $noExt = strtolower(preg_replace('/\.[^.]+$/', '', $filename));
                $this->basenameIndex[$noExt] ??= $file->getPathname();
            }
        }
    }

    public function indexedFilesCount(): int
    {
        return $this->indexedFiles;
    }

    /** Retrouve le chemin source réel sur disque pour une valeur telle que stockée en base, ou null si introuvable/non pertinente. */
    public function resolve(?string $legacyValue): ?string
    {
        if (! $legacyValue) {
            return null;
        }

        $legacyValue = trim($legacyValue);
        if ($legacyValue === ''
            || str_starts_with($legacyValue, 'http://')
            || str_starts_with($legacyValue, 'https://')
            || str_starts_with($legacyValue, 'data:')
        ) {
            return null;
        }

        // `basename()` ignore tout préfixe de répertoire déjà présent dans la
        // valeur legacy (ex. "agenda/xxx.jpg", "images/encadre_x.jpg") — seul
        // le nom de fichier final compte pour la résolution.
        $filename = basename($legacyValue);
        if ($filename === '' || ! str_contains($filename, '.')) {
            return null; // pas un nom de fichier exploitable (ex. synopsis collé par erreur en base, constaté sur t_cine_film)
        }

        $exactKey = strtolower($filename);
        if (isset($this->exactIndex[$exactKey])) {
            return $this->exactIndex[$exactKey];
        }

        $noExt = strtolower(preg_replace('/\.[^.]+$/', '', $filename));

        return $this->basenameIndex[$noExt] ?? null;
    }

    /**
     * Résout puis copie (idempotent) vers `storage/app/public/{destSubdir}/`.
     *
     * @return string|null Chemin relatif au disque `public` à stocker en base (ex. "movies/xxx.jpg"), ou null si introuvable.
     */
    public function import(?string $legacyValue, string $destSubdir): ?string
    {
        $source = $this->resolve($legacyValue);
        if (! $source) {
            return null;
        }

        $filename = basename($source);
        $destRelative = trim($destSubdir, '/').'/'.$filename;
        $destAbsolute = storage_path('app/public/'.$destRelative);

        if (! is_dir(dirname($destAbsolute))) {
            mkdir(dirname($destAbsolute), 0755, true);
        }

        if (! file_exists($destAbsolute)) {
            copy($source, $destAbsolute);
        }

        return $destRelative;
    }
}
