<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegarde et restauration (B19, TST-023).
 *
 * Une archive `empil-AAAAMMJJ-HHMMSS.zip` contient :
 * - database.dump : export PostgreSQL complet (pg_dump, format custom, restaurable par pg_restore) ;
 * - files/… : le disque « local » (justificatifs de dépenses) ;
 * - manifest.json : date, base, version de l'application, nombre de fichiers.
 */
class BackupService
{
    public const NAME_PATTERN = '/^empil-\d{8}-\d{6}\.zip$/';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crée une archive, puis supprime les plus anciennes au-delà de backup.keep.
     *
     * @return array{name: string, size: int, created_at: string}
     */
    public function create(string $trigger): array
    {
        $this->ensurePgsql();
        $dir = $this->directory();
        $name = 'empil-'.now()->format('Ymd-His').'.zip';
        $work = $this->tempDir();

        try {
            $dump = $work.DIRECTORY_SEPARATOR.'database.dump';
            $this->run([config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', ...$this->connectionArgs(), '--file='.$dump, $this->database()], 'L’export de la base');
            if (! is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException('L’export de la base n’a produit aucun fichier.');
            }

            $zip = new ZipArchive;
            $target = $dir.DIRECTORY_SEPARATOR.$name;
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer l’archive de sauvegarde.');
            }
            $zip->addFile($dump, 'database.dump');
            $files = Storage::disk('local')->allFiles();
            foreach ($files as $file) {
                $zip->addFile(Storage::disk('local')->path($file), 'files/'.$file);
            }
            $zip->addFromString('manifest.json', (string) json_encode([
                'application' => 'EMPIL',
                'created_at' => now()->toIso8601String(),
                'trigger' => $trigger,
                'database' => $this->database(),
                'files' => count($files),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->close();
        } finally {
            File::deleteDirectory($work);
        }

        $backup = $this->describe($dir.DIRECTORY_SEPARATOR.$name);
        $this->audit->log('backup.created', after: ['name' => $name, 'size' => $backup['size'], 'trigger' => $trigger]);
        $this->prune();

        return $backup;
    }

    /**
     * Archives disponibles, la plus récente en premier.
     *
     * @return list<array{name: string, size: int, created_at: string}>
     */
    public function all(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'empil-*.zip') ?: [];
        $files = array_values(array_filter($files, fn (string $f) => preg_match(self::NAME_PATTERN, basename($f)) === 1));
        rsort($files); // le nom contient la date : ordre alphabétique = ordre chronologique

        return array_map(fn (string $f) => $this->describe($f), $files);
    }

    /** Chemin d'une archive existante, ou null (nom invalide ou absent). */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * Remplace la base et les fichiers par le contenu d'une archive. Les données actuelles sont perdues :
     * la commande backup:restore fait d'abord une sauvegarde de sécurité.
     */
    public function restore(string $archive): void
    {
        $this->ensurePgsql();
        $work = $this->tempDir();

        try {
            $zip = new ZipArchive;
            if ($zip->open($archive) !== true || $zip->locateName('database.dump') === false) {
                throw new RuntimeException('Archive illisible ou sans export de base (database.dump).');
            }
            $zip->extractTo($work);
            $zip->close();

            // --clean --if-exists : les tables sont supprimées puis recréées ; tout ou rien (--single-transaction).
            $this->run([
                config('backup.pg_restore'), '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction',
                ...$this->connectionArgs(), '--dbname='.$this->database(), $work.DIRECTORY_SEPARATOR.'database.dump',
            ], 'La restauration de la base');

            $disk = Storage::disk('local');
            foreach ($disk->allFiles() as $file) {
                $disk->delete($file);
            }
            $source = $work.DIRECTORY_SEPARATOR.'files';
            if (is_dir($source)) {
                // Fichiers cachés compris (.gitignore du disque) : sinon la restauration les ferait disparaître.
                foreach (File::allFiles($source, true) as $file) {
                    $disk->put(str_replace('\\', '/', $file->getRelativePathname()), $file->getContents());
                }
            }
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function prune(): void
    {
        foreach (array_slice($this->all(), max(1, config('backup.keep'))) as $old) {
            @unlink($this->directory().DIRECTORY_SEPARATOR.$old['name']);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $what): void
    {
        $result = Process::timeout(config('backup.timeout'))
            ->env(['PGPASSWORD' => (string) $this->connection()['password']])
            ->run($command);

        if ($result->failed()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("{$what} a échoué".($detail !== '' ? ' : '.Str::limit($detail, 500) : '.'));
        }
    }

    /**
     * @return list<string>
     */
    private function connectionArgs(): array
    {
        $c = $this->connection();

        return ['--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username'], '--no-password'];
    }

    private function database(): string
    {
        return (string) $this->connection()['database'];
    }

    /**
     * @return array<string, mixed> configuration de la connexion par défaut
     */
    private function connection(): array
    {
        return config('database.connections.'.config('database.default'));
    }

    private function ensurePgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Les sauvegardes ne sont prévues que pour PostgreSQL.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('L’extension PHP « zip » est nécessaire aux sauvegardes.');
        }
    }

    private function directory(): string
    {
        $dir = (string) config('backup.path');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/backup-'.Str::random(12));
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    /**
     * @return array{name: string, size: int, created_at: string}
     */
    private function describe(string $path): array
    {
        return [
            'name' => basename($path),
            'size' => (int) filesize($path),
            'created_at' => date(DATE_ATOM, (int) filemtime($path)),
        ];
    }
}
