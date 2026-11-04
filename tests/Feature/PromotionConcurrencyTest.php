<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PromotionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_concurrent_creations_for_the_same_product_cannot_enable_overlapping_offers(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a disposable MySQL test database and independent connections.');
        }
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $actors = User::factory()->count(2)->create();
        $processes = [];
        $database = config('database.connections.mysql');
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        DB::beginTransaction();
        Product::query()->lockForUpdate()->findOrFail($product->id);
        try {
            foreach ($actors as $actor) {
                $code = '$app = require '.var_export(base_path('bootstrap/app.php'), true).'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                    .'Illuminate\\Support\\Facades\\Gate::define("PROMOTION_MANAGE", fn ($user) => true); '
                    .'$actor = App\\Models\\User::findOrFail('.$actor->id.'); echo "READY\\n"; flush(); '
                    .'try { $app->make(App\\Services\\PromotionManager::class)->store('.$product->id.', '
                    .var_export(['title' => 'Concurrent fixture', 'kind' => 'fixed_price', 'value' => '80.00', 'state' => 'enabled', 'timezone' => 'Africa/Casablanca', 'starts_at' => '2030-01-01 08:00:00', 'ends_at' => '2030-01-01 09:00:00'], true).', '
                    .var_export((string) Str::uuid(), true).', $actor); echo "CREATED\\n"; } '
                    .'catch (Illuminate\\Validation\\ValidationException $e) { echo isset($e->errors()["starts_at"]) ? "OVERLAP\\n" : "INVALID\\n"; }';
                $process = new Process([PHP_BINARY, '-r', 'require '.var_export(base_path('vendor/autoload.php'), true).'; '.$code], base_path(), $environment);
                $process->setTimeout(20)->start();
                $processes[] = $process;
                $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            sort($results);
            $this->assertSame(['CREATED', 'OVERLAP'], $results);
            $this->assertDatabaseCount('promotions', 1);
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }
}
