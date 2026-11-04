<?php

namespace Database\Seeders;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || getenv('FTP_PRICING_DEMO') !== '1') {
            $this->command?->warn('Fixtures FTP-18 uniquement : environnement local/testing et FTP_PRICING_DEMO=1 requis.');

            return;
        }
        $actor = User::query()->where('email', 'ftp18-fixture-author@example.test')->first()
            ?? User::factory()->create(['name' => 'Auteur fictif FTP-18', 'email' => 'ftp18-fixture-author@example.test', 'password' => Str::random(64)]);
        foreach ([
            ['ACTIVE', now('UTC')->subMinute(), now('UTC')->addSeconds(max(30, min(86400, (int) (getenv('FTP_PRICING_DEMO_SECONDS') ?: 3600)))), 'enabled', 'published'],
            ['FUTURE', now('UTC')->addDay(), now('UTC')->addDays(2), 'enabled', 'published'],
            ['EXPIRED', now('UTC')->subDays(2), now('UTC')->subDay(), 'enabled', 'published'],
            ['DISABLED', now('UTC')->subHour(), now('UTC')->addHour(), 'disabled', 'published'],
            ['DRAFT', now('UTC')->subHour(), now('UTC')->addHour(), 'enabled', 'draft'],
        ] as [$code, $start, $end, $state, $publication]) {
            $product = Product::query()->where('sku', 'FTP18-DEMO-'.$code)->first()
                ?? Product::factory()->create(['sku' => 'FTP18-DEMO-'.$code, 'designation' => 'Produit fictif FTP-18 '.$code, 'original_reference' => 'FTP18-'.$code,
                    'publication' => $publication, 'sale_price' => '100.00', 'net_purchase_price' => '67.89', 'images' => []]);
            if (! Promotion::query()->where('product_id', $product->id)->exists()) {
                Promotion::factory()->for($product)->create(['title' => 'Offre fictive FTP-18 '.$code, 'kind' => 'fixed_price', 'value' => '80.00', 'timezone' => 'Africa/Casablanca',
                    'starts_at' => $start, 'ends_at' => $end, 'state' => $state, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            }
        }
        if (getenv('FTP_PRICING_DEMO_CUSTOMER') === '1') {
            $code = 'FTP18-DEMO-CUSTOMER';
            if (! CustomerProfile::query()->where('customer_code', $code)->exists()) {
                $password = Str::random(32);
                $user = User::factory()->create(['name' => 'Client fictif FTP-18', 'email' => 'ftp18-fixture-customer@example.test', 'password' => $password]);
                CustomerProfile::factory()->for($user)->create(['customer_code' => $code, 'approval' => 'APPROUVE']);
                Storage::disk('local')->put('ftp18-demo-access.json', json_encode(['identifier' => $code, 'password' => $password], JSON_THROW_ON_ERROR));
                chmod(Storage::disk('local')->path('ftp18-demo-access.json'), 0600);
            }
            $this->call(CustomerDiscountSeeder::class);
            $this->command?->info('Accès de test : storage/app/private/ftp18-demo-access.json. Aucun rôle interne attribué.');
        }
        $this->command?->info('Fixtures fictives FTP18-DEMO-* préparées. Elles ne valident pas les droits de création ni le processus réel d’approbation.');
    }
}
