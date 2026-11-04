<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceSearchMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_raw_codes_and_verification_survive_key_backfill_without_merging_records(): void
    {
        $migration = require database_path('migrations/2026_11_04_120125_add_reference_search_keys_to_products_and_cross_references.php');
        $migration->down();
        $id = DB::table('products')->insertGetId([
            'sku' => 'LEGACY-001', 'designation' => 'Legacy hub', 'original_reference' => ' CR F 200028 ',
            'publication' => 'published', 'net_purchase_price' => '67.89',
        ]);
        DB::table('product_cross_references')->insert([
            ['product_id' => $id, 'brand' => 'Example', 'reference' => '03 434 3010 00', 'verified_at' => '2026-11-01 10:00:00'],
            ['product_id' => $id, 'brand' => 'Example', 'reference' => '03434301000', 'verified_at' => null],
        ]);

        $migration->up();

        $this->assertDatabaseHas('products', ['id' => $id, 'original_reference' => ' CR F 200028 ', 'net_purchase_price' => '67.89']);
        $this->assertDatabaseHas('product_cross_references', [
            'product_id' => $id, 'reference' => '03 434 3010 00', 'verified_at' => '2026-11-01 10:00:00',
            'normalized_reference' => '03434301000',
        ]);
        $this->assertDatabaseCount('product_cross_references', 2);
        $this->getJson('/api/v1/catalogue/products?reference=03434301000')->assertOk()->assertJsonPath('data.0.id', $id);
    }
}
