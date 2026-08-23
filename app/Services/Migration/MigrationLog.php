<?php

namespace App\Services\Migration;

/**
 * Journal de migration par domaine — un fichier par commande `migrate:*`
 * (voir TECHNICAL_DOCUMENTATION.md §10). Trace ce qui est traité/créé/mis à
 * jour/ignoré et pourquoi, pour permettre un contrôle post-migration sans
 * rejouer les commandes.
 */
class MigrationLog
{
    /** @var resource */
    protected $handle;

    protected int $created = 0;

    protected int $updated = 0;

    protected int $skipped = 0;

    public function __construct(protected string $domain)
    {
        $dir = storage_path('logs/migration');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->handle = fopen("{$dir}/{$domain}.log", 'a');
        $this->write('INFO', str_repeat('-', 60));
        $this->write('INFO', "Démarrage migration [{$domain}]");
    }

    public function created(string $message): void
    {
        $this->created++;
        $this->write('CREATED', $message);
    }

    /** Pour les insertions en lot (bulk insert), où un seul appel représente plusieurs lignes. */
    public function createdMany(int $count, string $message): void
    {
        $this->created += $count;
        $this->write('CREATED', $message);
    }

    public function updated(string $message): void
    {
        $this->updated++;
        $this->write('UPDATED', $message);
    }

    public function skipped(string $message): void
    {
        $this->skipped++;
        $this->write('SKIPPED', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function summary(): string
    {
        $line = "Terminé [{$this->domain}] — créés: {$this->created}, mis à jour: {$this->updated}, ignorés: {$this->skipped}";
        $this->write('INFO', $line);

        return $line;
    }

    protected function write(string $level, string $message): void
    {
        fwrite($this->handle, sprintf("[%s] %s: %s\n", now()->toDateTimeString(), $level, $message));
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
