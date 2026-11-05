<?php

namespace Database\Seeders;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\CustomerOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerOrderDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('local', 'testing'), 403, 'These fictional fixtures are limited to local and testing environments.');
        DB::transaction(function (): void {
            if (User::query()->where('email', 'ftp-order-demo@example.test')->exists()) {
                return;
            }
            $user = User::factory()->create(['name' => 'Client démonstration FTP', 'email' => 'ftp-order-demo@example.test',
                'password' => 'ftp-local-demo-only']);
            CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'CLI-FTP-DEMO']);
            $product = Product::factory()->create(['sku' => 'FTP-DEMO-ORDER-'.Str::upper(Str::random(6)),
                'designation' => 'Filtre de démonstration', 'original_reference' => 'DEMO-REF-001',
                'publication' => 'published', 'sale_price' => '12.50']);
            $cart = app(CartService::class)->putLine($user->id, $product->id, 2, 1, 0, (string) Str::uuid());
            $address = ['address' => 'Adresse fictive de démonstration', 'city' => 'Rabat', 'country' => 'Maroc'];
            app(CustomerOrderService::class)->submit($user->id, [
                'idempotency_key' => (string) Str::uuid(), 'expected_version' => $cart['version'],
                'pricing_token' => CartService::pricingToken($cart),
                'contact' => ['name' => 'Client démo', 'email' => 'ftp-order-demo@example.test', 'phone' => '0000000000'],
                'delivery_address' => $address, 'billing_address' => $address,
            ], (string) Str::uuid());
            app(CartService::class)->putLine($user->id, $product->id, 1, 1, 2, (string) Str::uuid());
        });
    }
}
