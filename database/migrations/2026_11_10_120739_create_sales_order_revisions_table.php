<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->string('kind', 32);
            $table->json('before');
            $table->json('after');
            $table->string('reason', 1000);
            $table->unsignedInteger('order_version');
            $table->timestamp('created_at');
            $table->unique(['order_id', 'request_key']);
            $table->index(['order_id', 'order_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_revisions');
    }
};
