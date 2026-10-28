<?php

namespace Tests\Feature\Stock;

use App\Enums\MovementType;
use App\Models\Article;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * TST-011 : plusieurs caissiers valident en même temps la dernière unité.
 * Une seule vente réussit, le stock n'est jamais négatif.
 *
 * De vrais processus PHP concurrents sont nécessaires (connexions distinctes), d'où PostgreSQL et des
 * données réellement validées (DatabaseTruncation au lieu de RefreshDatabase).
 */
class StockConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const SELLERS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Test de concurrence exécuté sur PostgreSQL uniquement.');
        }
    }

    protected function tearDown(): void
    {
        // Laisser la base vide pour les classes de test suivantes (RefreshDatabase ne la recrée pas).
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateDatabaseTables();
        }

        parent::tearDown();
    }

    public function test_only_one_of_several_simultaneous_sales_gets_the_last_unit(): void
    {
        $this->seed(LocationSeeder::class);
        $user = User::factory()->cashier()->create();
        $article = Article::factory()->create();
        $shop = Location::where('code', 'BTQ')->sole();

        app(StockService::class)->apply([StockChange::in($article->id, $shop->id, MovementType::Adjustment, 1)], $user);

        $outputs = $this->runSellersConcurrently($article->id, $shop->id, $user->id);

        sort($outputs);
        $this->assertSame(array_merge(['ok'], array_fill(0, self::SELLERS - 1, 'refused')), $outputs, implode("\n", $outputs));
        $this->assertSame(0, StockBalance::sole()->quantity_on_hand);
        $this->assertSame(1, StockMovement::where('type', MovementType::Sale)->count());
    }

    /**
     * @return list<string>
     */
    private function runSellersConcurrently(int $articleId, int $locationId, int $userId): array
    {
        $php = (new PhpExecutableFinder)->find(false);
        $script = base_path('tests/Support/sell_one_unit.php');
        $startAt = (string) (microtime(true) + 3); // laisse aux processus le temps de démarrer
        $connection = config('database.connections.pgsql');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '',
        ];

        $processes = [];
        for ($i = 0; $i < self::SELLERS; $i++) {
            $process = new Process([$php, $script, $articleId, $locationId, $userId, $startAt], base_path(), $env, null, 60);
            $process->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process) {
            $process->wait();

            return trim($process->getOutput().$process->getErrorOutput());
        }, $processes);
    }
}
