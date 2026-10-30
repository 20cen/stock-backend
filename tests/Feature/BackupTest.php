<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backups');
        File::deleteDirectory($this->dir);
        config(['backup.path' => $this->dir, 'backup.keep' => 3]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function fakeArchive(string $name): void
    {
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/'.$name, 'zip');
    }

    public function test_the_owner_lists_and_downloads_backups(): void
    {
        $this->fakeArchive('empil-20261028-020000.zip');
        $this->fakeArchive('empil-20261029-020000.zip');
        File::put($this->dir.'/autre.txt', 'ignoré');
        Sanctum::actingAs(User::factory()->owner()->create());

        $this->getJson('/api/v1/backups')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'empil-20261029-020000.zip')
            ->assertJsonPath('meta.keep', 3);

        $this->get('/api/v1/backups/empil-20261029-020000.zip')->assertOk()->assertDownload('empil-20261029-020000.zip');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded']);

        $this->getJson('/api/v1/backups/autre.txt')->assertNotFound();
        $this->getJson('/api/v1/backups/..%2F..%2F.env')->assertNotFound();
    }

    public function test_the_cashier_has_no_access(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/backups')->assertForbidden();
        $this->postJson('/api/v1/backups')->assertForbidden();
    }

    public function test_a_failure_is_reported_in_french(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Process::fake(['*' => Process::result(errorOutput: 'pg_dump: error: server version mismatch', exitCode: 1)]);
        }
        Sanctum::actingAs(User::factory()->owner()->create());

        $message = $this->postJson('/api/v1/backups')->assertUnprocessable()->json('message');

        $this->assertStringStartsWith('Sauvegarde impossible.', $message);
        $this->assertSame([], glob($this->dir.'/*.zip') ?: []);
    }

    /** PostgreSQL seulement : archive complète (export + justificatifs + manifeste) et rotation. */
    public function test_an_archive_contains_the_dump_and_the_attachments_and_old_ones_are_pruned(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sauvegardes prévues pour PostgreSQL.');
        }
        Storage::fake('local');
        Storage::disk('local')->put('expenses/2026/facture.pdf', '%PDF-test');
        foreach (['empil-20261026-020000.zip', 'empil-20261027-020000.zip', 'empil-20261028-020000.zip'] as $old) {
            $this->fakeArchive($old);
        }
        // pg_dump simulé : il écrit le fichier demandé par --file=…
        Process::fake(function (PendingProcess $process) {
            $file = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--file='));
            File::put(substr($file, 7), 'PGDMP');

            return Process::result();
        });

        $backup = app(BackupService::class)->create('test');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->dir.'/'.$backup['name']));
        $this->assertSame('PGDMP', $zip->getFromName('database.dump'));
        $this->assertSame('%PDF-test', $zip->getFromName('files/expenses/2026/facture.pdf'));
        $this->assertSame('test', json_decode($zip->getFromName('manifest.json'), true)['trigger']);
        $zip->close();

        $names = array_column(app(BackupService::class)->all(), 'name');
        $this->assertSame([$backup['name'], 'empil-20261028-020000.zip', 'empil-20261027-020000.zip'], $names);
        Process::assertRan(fn (PendingProcess $p) => in_array('--format=custom', $p->command, true));
    }

    /** PostgreSQL seulement : la restauration remet exactement les fichiers de l'archive, fichiers cachés compris. */
    public function test_a_restore_replaces_the_files_including_hidden_ones(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sauvegardes prévues pour PostgreSQL.');
        }
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('.gitignore', "*\n");
        $disk->put('expenses/2026/facture.pdf', '%PDF-avant');
        Process::fake(function (PendingProcess $process) {
            $file = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--file='));
            if ($file) {
                File::put(substr($file, 7), 'PGDMP');
            }

            return Process::result();
        });
        $backup = app(BackupService::class)->create('test');

        $disk->put('expenses/2026/facture.pdf', '%PDF-apres');
        $disk->put('expenses/2026/nouveau.pdf', '%PDF-nouveau');
        app(BackupService::class)->restore($this->dir.'/'.$backup['name']);

        $this->assertSame("*\n", $disk->get('.gitignore'));
        $this->assertSame('%PDF-avant', $disk->get('expenses/2026/facture.pdf'));
        $this->assertFalse($disk->exists('expenses/2026/nouveau.pdf'));
        Process::assertRan(fn (PendingProcess $p) => in_array('--single-transaction', $p->command, true));
    }
}
