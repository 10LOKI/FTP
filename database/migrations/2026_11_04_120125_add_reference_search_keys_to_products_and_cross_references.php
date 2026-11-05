<?php

use App\Services\ProductReferenceNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('original_reference_normalized')->nullable();
            $table->char('original_reference_search_key', 64)->nullable()->index('products_reference_search_idx');
            $table->char('original_reference_exact_key', 64)->nullable();
        });
        Schema::table('product_cross_references', function (Blueprint $table): void {
            $table->text('normalized_reference')->nullable();
            $table->char('reference_search_key', 64)->nullable();
            $table->char('reference_exact_key', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['reference_search_key', 'verified_at', 'product_id'], 'cross_references_search_idx');
        });

        DB::table('products')->orderBy('id')->chunkById(200, function (Collection $products): void {
            foreach ($products as $product) {
                $keys = ProductReferenceNormalizer::attributes($product->original_reference);
                DB::table('products')->where('id', $product->id)->update([
                    'original_reference_normalized' => $keys['normalized_reference'],
                    'original_reference_search_key' => $keys['reference_search_key'],
                    'original_reference_exact_key' => $keys['reference_exact_key'],
                ]);
            }
        });
        DB::table('product_cross_references')->orderBy('id')->chunkById(200, function (Collection $references): void {
            foreach ($references as $reference) {
                DB::table('product_cross_references')->where('id', $reference->id)
                    ->update(ProductReferenceNormalizer::attributes($reference->reference));
            }
        });

        Schema::table('product_cross_references', function (Blueprint $table): void {
            $table->unique(['product_id', 'brand', 'reference_exact_key'], 'cross_references_raw_unique');
            $table->dropUnique(['product_id', 'brand', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_cross_references', function (Blueprint $table): void {
            $table->unique(['product_id', 'brand', 'reference']);
        });
        Schema::table('product_cross_references', function (Blueprint $table): void {
            $table->dropUnique('cross_references_raw_unique');
            $table->dropIndex('cross_references_search_idx');
            $table->dropColumn(['normalized_reference', 'reference_search_key', 'reference_exact_key', 'version']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_reference_search_idx');
            $table->dropColumn(['original_reference_normalized', 'original_reference_search_key', 'original_reference_exact_key']);
        });
    }
};
