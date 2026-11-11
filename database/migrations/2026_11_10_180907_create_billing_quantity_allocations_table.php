<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_quantity_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_line_id')->constrained('delivery_batch_lines')->restrictOnDelete();
            $table->enum('kind', ['invoice', 'return_before_invoice']);
            $table->uuid('source_id');
            $table->unsignedInteger('quantity');
            $table->timestamp('created_at');
            $table->unique(['delivery_line_id', 'kind', 'source_id'], 'billing_allocation_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_quantity_allocations');
    }
};
