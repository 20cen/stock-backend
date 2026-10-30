<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sauvegardes depuis Paramètres : liste, sauvegarde immédiate, téléchargement (pour garder une copie hors du serveur).
 * La restauration se fait en ligne de commande uniquement (backup:restore), jamais depuis l'interface.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->backups->all(), 'meta' => ['keep' => config('backup.keep')]]);
    }

    public function store(): JsonResponse
    {
        set_time_limit(max(60, config('backup.timeout')));

        try {
            $backup = $this->backups->create('manuelle');
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'Sauvegarde impossible. '.$e->getMessage()], 422);
        }

        return response()->json(['data' => $backup], 201);
    }

    public function download(string $name): BinaryFileResponse|JsonResponse
    {
        $path = $this->backups->path($name);
        if (! $path) {
            return response()->json(['message' => 'Sauvegarde introuvable.'], 404);
        }
        $this->audit->log('backup.downloaded', after: ['name' => $name]);

        return response()->download($path, $name, ['Content-Type' => 'application/zip']);
    }
}
