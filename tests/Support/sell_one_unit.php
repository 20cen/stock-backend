<?php

/*
 * Processus enfant du test TST-011 (StockConcurrencyTest) : tente de vendre une unité à l'instant donné.
 * Usage : php sell_one_unit.php <article_id> <location_id> <user_id> <timestamp_de_depart>
 * Affiche « ok », « refused » (stock insuffisant) ou « error: … ».
 */

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $articleId, $locationId, $userId, $startAt] = $argv;

$user = User::findOrFail((int) $userId);
$stock = $app->make(StockService::class);

// Tous les processus partent au même instant pour se disputer la dernière unité.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $stock->apply([StockChange::out((int) $articleId, (int) $locationId, MovementType::Sale, 1)], $user);
    echo 'ok';
} catch (InsufficientStockException) {
    echo 'refused';
} catch (Throwable $e) {
    echo 'error: '.get_class($e).': '.$e->getMessage();
}
