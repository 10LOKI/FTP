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
        Schema::create('sales_quote_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('order_version');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['order_id', 'revision']);
            $table->unique(['order_id', 'request_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_quote_drafts');
    }
};
