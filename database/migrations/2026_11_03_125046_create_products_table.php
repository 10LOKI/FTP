<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('sku')->unique();
                $table->string('designation');
                $table->string('original_reference');
                $table->string('part_brand')->nullable();
                $table->string('category')->nullable();
                $table->string('hs_code')->nullable();
                $table->decimal('net_weight', 12, 4)->nullable();
                $table->string('weight_unit', 12)->nullable();
                $table->json('images')->nullable();
                $table->decimal('sale_price', 12, 2)->nullable();
                $table->decimal('net_purchase_price', 12, 2)->nullable();
                $table->string('publication')->default('draft')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('barcode')->unique();
                $table->unsignedInteger('units_per_package');
            });
        }

        if (! Schema::hasTable('product_cross_references')) {
            Schema::create('product_cross_references', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('brand', 100);
                $table->string('reference', 120);
                $table->timestamp('verified_at')->nullable();
                $table->unique(['product_id', 'brand', 'reference']);
            });
        }

        if (! Schema::hasTable('product_compatibilities')) {
            Schema::create('product_compatibilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->string('vehicle_make', 100);
                $table->string('vehicle_model', 100);
            });
        }
        if (! Schema::hasIndex('product_compatibilities', 'product_vehicle_unique')) {
            Schema::table('product_compatibilities', function (Blueprint $table) {
                $table->unique(['product_id', 'vehicle_make', 'vehicle_model'], 'product_vehicle_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_compatibilities');
        Schema::dropIfExists('product_cross_references');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('products');
    }
};
