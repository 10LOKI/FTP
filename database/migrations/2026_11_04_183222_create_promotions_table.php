<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('currency', 3)->default('MAD');
            $table->string('tax_basis', 3)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('pricing_settings')->insert(['id' => 1, 'currency' => 'MAD', 'tax_basis' => null, 'version' => 1]);
        Schema::create('customer_discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_profile_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->string('kind', 20);
            $table->decimal('value', 12, 2);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->string('state', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['product_id', 'state', 'starts_at', 'ends_at'], 'promotions_product_window_idx');
            $table->index(['state', 'starts_at', 'ends_at'], 'promotions_active_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('customer_discounts');
        Schema::dropIfExists('pricing_settings');
    }
};
