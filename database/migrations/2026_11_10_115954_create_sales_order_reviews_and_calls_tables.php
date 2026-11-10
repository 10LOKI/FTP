<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('customer_orders')->restrictOnDelete();
            $table->foreignId('claimed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('claimed_at');
        });
        Schema::create('sales_order_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->string('outcome', 32);
            $table->timestamp('happened_at');
            $table->text('note')->nullable();
            $table->unsignedInteger('order_version');
            $table->timestamp('created_at');
            $table->unique(['order_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_calls');
        Schema::dropIfExists('sales_order_reviews');
    }
};
