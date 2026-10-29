<?php

use Illuminate\Support\Facades\Route;

// L'API n'a pas de page d'accueil : réponse neutre, sans afficher le framework ni sa version.
Route::get('/', fn () => response()->json(['name' => 'EMPIL API', 'status' => 'ok']));
