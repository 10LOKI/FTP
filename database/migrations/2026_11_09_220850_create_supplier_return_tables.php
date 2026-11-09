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
        Schema::create('suppliers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 160);
            $t->decimal('opening_balance', 24, 2)->default(0);
            $t->decimal('current_balance', 24, 2)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('supplier_purchases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('reference', 120);
            $t->date('received_on');
            $t->timestamps();
            $t->unique(['supplier_id', 'reference']);
        });
        Schema::create('supplier_purchase_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_id')->constrained('supplier_purchases')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('returned_quantity')->default(0);
            $t->decimal('net_unit_cost', 24, 2);
            $t->string('lot_reference', 120)->nullable();
            $t->timestamps();
        });
        Schema::create('supplier_returns', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('number', 48)->unique();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_id')->constrained('supplier_purchases')->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('status', 24)->default('REQUESTED');
            $t->text('reason');
            $t->decimal('requested_amount', 24, 2);
            $t->decimal('accepted_credit', 24, 2)->nullable();
            $t->text('credit_evidence')->nullable();
            $t->uuid('stock_request_key')->unique();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('dispatched_at')->nullable();
            $t->foreignId('credit_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('credit_decided_at')->nullable();
            $t->char('document_sha256', 64)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('supplier_return_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('return_id')->constrained('supplier_returns')->restrictOnDelete();
            $t->foreignUuid('purchase_line_id')->constrained('supplier_purchase_lines')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->decimal('net_unit_cost', 24, 2);
            $t->timestamps();
            $t->unique(['return_id', 'purchase_line_id']);
        });
        Schema::create('supplier_balance_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('return_id')->nullable()->constrained('supplier_returns')->restrictOnDelete();
            $t->string('kind', 24);
            $t->decimal('amount_delta', 24, 2);
            $t->string('justification', 255);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key')->unique();
            $t->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['supplier_balance_entries', 'supplier_return_lines', 'supplier_returns', 'supplier_purchase_lines', 'supplier_purchases', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
