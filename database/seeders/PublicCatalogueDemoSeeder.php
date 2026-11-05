<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Services\ProductReferenceNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PublicCatalogueDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['001', 'Filtre de démonstration A', 'published', 'Filtres', 'Marque démo A'],
            ['002', 'Filtre de démonstration B', 'published', 'Filtres', 'Marque démo B'],
            ['003', 'Brouillon de démonstration', 'draft', 'Privé', 'Marque privée'],
            ['004', 'Archive de démonstration', 'archived', 'Privé', 'Marque privée'],
        ] as [$number, $designation, $publication, $category, $brand]) {
            $product = Product::query()->where('sku', 'FTP15-DEMO-'.$number)->first()
                ?? Product::factory()->create([
                    'sku' => 'FTP15-DEMO-'.$number, 'designation' => $designation,
                    'original_reference' => '000-DEMO-'.$number, 'publication' => $publication,
                    'category' => $category, 'part_brand' => $brand, 'images' => [],
                    'hs_code' => '8708', 'net_weight' => '2.5000', 'weight_unit' => 'kg',
                    'sale_price' => '123.45', 'net_purchase_price' => '67.89',
                ]);

            DB::table('product_compatibilities')->insertOrIgnore([
                'product_id' => $product->id, 'vehicle_make' => 'Camion démo', 'vehicle_model' => 'Modèle A',
            ]);
            if (! ProductCrossReference::query()->where('product_id', $product->id)
                ->where('brand', 'Marque démo')
                ->where('reference_search_key', ProductReferenceNormalizer::searchKey('CROSS-DEMO-'.$number))->exists()) {
                ProductCrossReference::factory()->for($product)->verified()->create([
                    'brand' => 'Marque démo', 'reference' => 'CROSS-DEMO-'.$number,
                ]);
            }
        }
    }
}
