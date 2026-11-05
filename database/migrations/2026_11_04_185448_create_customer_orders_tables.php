<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->string('status', 24)->default('SOUMISE');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->json('customer_snapshot');
            $table->json('contact');
            $table->json('delivery_address');
            $table->json('billing_address');
            $table->json('lines');
            $table->decimal('catalogue_subtotal', 24, 2);
            $table->string('pricing_status', 40)->default('catalogue_estimate');
            $table->text('customer_note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_orders');
    }
};
