<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Services\ProductReferenceNormalizer;
use Illuminate\Database\Seeder;

class ReferenceSearchDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hub = $this->product('HUB', 'MOYEU DE ROUE 0343012', 'CR F 200028');
        foreach (['0343012', '03 434 3010 00', '200028', '566830.H195', 'SET 1372', 'PARTAGE-FTP17'] as $reference) {
            $this->reference($hub, $reference);
        }
        $sleeve = $this->product('SLEEVE', 'MANCHON — démonstration FTP-17', 'H 208');
        $this->reference($sleeve, 'CR H 208');
        $other = $this->product('COLLISION', 'AUTRE MOYEU — démonstration FTP-17', 'OTHER-HUB', 'Marque démo B');
        $this->reference($other, 'PARTAGE-FTP17');
        $this->product('DRAFT', 'BROUILLON — démonstration FTP-17', 'PARTAGE-FTP17', publication: 'draft');
        $this->product('ARCHIVED', 'ARCHIVE — démonstration FTP-17', 'PARTAGE-FTP17', publication: 'archived');
    }

    private function product(string $code, string $designation, string $reference, string $brand = 'Marque démo A', string $publication = 'published'): Product
    {
        return Product::query()->where('sku', 'FTP17-DEMO-'.$code)->first()
            ?? Product::factory()->create([
                'sku' => 'FTP17-DEMO-'.$code, 'designation' => $designation, 'original_reference' => $reference,
                'part_brand' => $brand, 'publication' => $publication, 'category' => 'Démonstration FTP-17',
                'sale_price' => '123.45', 'net_purchase_price' => '67.89', 'images' => [],
            ]);
    }

    private function reference(Product $product, string $value): void
    {
        if (! ProductCrossReference::query()->where('product_id', $product->id)
            ->where('brand', 'Source démo')->where('reference_search_key', ProductReferenceNormalizer::searchKey($value))->exists()) {
            ProductCrossReference::factory()->for($product)->verified()->create(['reference' => $value, 'brand' => 'Source démo']);
        }
    }
}
