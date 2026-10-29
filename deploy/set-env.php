<?php

/**
 * Écrit ou remplace des clés du fichier .env, sans éditeur.
 *
 *   SET_DB_PASSWORD='…' php deploy/set-env.php DB_PASSWORD
 *
 * Les valeurs sont lues dans les variables d'environnement SET_<CLÉ> (jamais sur la ligne de commande,
 * qui serait visible dans la liste des processus). Les valeurs non triviales sont mises entre guillemets.
 */
$file = dirname(__DIR__).'/.env';
if (! is_file($file)) {
    fwrite(STDERR, "Fichier .env introuvable.\n");
    exit(1);
}

$env = file_get_contents($file);

foreach (array_slice($argv, 1) as $key) {
    if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
        fwrite(STDERR, "Clé invalide : {$key}\n");
        exit(1);
    }
    $value = getenv("SET_{$key}");
    if ($value === false) {
        fwrite(STDERR, "Valeur manquante pour {$key} (variable SET_{$key}).\n");
        exit(1);
    }

    $quoted = preg_match('#^[A-Za-z0-9_.:/@-]*$#', $value)
        ? $value
        : '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    $line = "{$key}={$quoted}";

    $pattern = '/^#?\s*'.$key.'=.*$/m';
    $env = preg_match($pattern, $env)
        ? preg_replace($pattern, addcslashes($line, '\\$'), $env, 1)
        : rtrim($env)."\n{$line}\n";
}

file_put_contents($file, $env);
