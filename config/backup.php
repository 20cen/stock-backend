<?php

return [

    /*
    | Sauvegardes (B19) : base PostgreSQL (pg_dump, format custom) + fichiers privés (justificatifs de dépenses),
    | réunis dans une archive .zip. Lancées chaque nuit par une tâche cron (php artisan backup:run) ou à la demande
    | depuis Paramètres. Restauration : php artisan backup:restore <fichier>.
    */

    // Dossier des archives : hors du disque « local » (qui est sauvegardé) et jamais servi par le web.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Nombre d'archives conservées (les plus récentes). 14 = deux semaines de sauvegardes quotidiennes.
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Outils PostgreSQL (chemin complet si absents du PATH, ex. sous Windows :
    // C:\Program Files\PostgreSQL\17\bin\pg_dump.exe).
    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
    'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),

    // Durée maximale d'un export ou d'une restauration, en secondes.
    'timeout' => (int) env('BACKUP_TIMEOUT', 600),

];
