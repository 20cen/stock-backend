<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restauration d'une sauvegarde : remplace TOUTE la base et les justificatifs.
 * Une sauvegarde de sécurité de l'état actuel est faite avant, pour pouvoir revenir en arrière.
 */
#[Signature('backup:restore {archive : nom (empil-AAAAMMJJ-HHMMSS.zip) ou chemin d’une archive} {--force : ne pas demander de confirmation}')]
#[Description('Restaure la base et les justificatifs depuis une archive de sauvegarde')]
class BackupRestore extends Command
{
    public function handle(BackupService $backups): int
    {
        $archive = (string) $this->argument('archive');
        $path = $backups->path($archive) ?? (is_file($archive) ? realpath($archive) : null);
        if (! $path) {
            $this->error("Archive introuvable : {$archive}");
            $this->line('Archives disponibles : '.(implode(', ', array_column($backups->all(), 'name')) ?: 'aucune'));

            return self::FAILURE;
        }

        $database = config('database.connections.'.config('database.default').'.database');
        $this->warn("Toutes les données actuelles de la base « {$database} » et les justificatifs seront remplacés par ceux de ".basename($path).'.');
        if (! $this->option('force') && ! $this->confirm('Continuer ?')) {
            $this->line('Restauration abandonnée.');

            return self::FAILURE;
        }

        try {
            $safety = $backups->create('avant-restauration');
            $this->info("Sauvegarde de sécurité de l'état actuel : {$safety['name']}");

            $backups->restore($path);
        } catch (Throwable $e) {
            $this->error('Restauration échouée : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->call('cache:clear');
        $this->info('Restauration terminée. Les utilisateurs devront peut-être se reconnecter.');

        return self::SUCCESS;
    }
}
