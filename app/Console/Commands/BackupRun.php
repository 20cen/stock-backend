<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sauvegarde planifiée. Tâche cron cPanel, chaque nuit :
 *   cd ~/stock-backend && php artisan backup:run >> storage/logs/backup.log 2>&1
 */
#[Signature('backup:run')]
#[Description('Sauvegarde la base et les justificatifs dans storage/app/backups')]
class BackupRun extends Command
{
    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->create('auto');
        } catch (Throwable $e) {
            $this->error(now()->format('d/m/Y H:i').' : sauvegarde échouée. '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s : sauvegarde %s (%s Ko).', now()->format('d/m/Y H:i'), $backup['name'], number_format($backup['size'] / 1024, 0, ',', ' ')));

        return self::SUCCESS;
    }
}
