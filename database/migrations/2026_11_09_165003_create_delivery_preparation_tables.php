<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 48)->unique();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('claimed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('claimed_at');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('status', 24)->default('IN_PREPARATION');
            $table->boolean('partial')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('stock_request_key')->unique();
            $table->foreignId('active_order_id')->nullable()->unique()->constrained('customer_orders')->restrictOnDelete();
            $table->char('document_sha256', 64)->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });
        Schema::create('delivery_batch_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('delivery_batches')->restrictOnDelete();
            $table->uuid('order_line_id');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('prepared_quantity')->default(0);
            $table->unsignedInteger('units_per_package')->default(1);
            $table->string('sku', 120);
            $table->string('designation');
            $table->string('original_reference', 120);
            $table->timestamps();
            $table->unique(['batch_id', 'order_line_id']);
        });
        Schema::create('delivery_scan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_key')->unique();
            $table->foreignUuid('batch_id')->constrained('delivery_batches')->restrictOnDelete();
            $table->foreignUuid('batch_line_id')->constrained('delivery_batch_lines')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('mode', 24);
            $table->string('code', 160);
            $table->unsignedInteger('quantity');
            $table->char('request_hash', 64);
            $table->timestamp('created_at');
            $table->index(['batch_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER delivery_scan_events_no_{$event} BEFORE {$event} ON delivery_scan_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery scan events are immutable'");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER delivery_scan_events_no_{$event} BEFORE {$event} ON delivery_scan_events BEGIN SELECT RAISE(ABORT, 'Delivery scan events are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_scan_events');
        Schema::dropIfExists('delivery_batch_lines');
        Schema::dropIfExists('delivery_batches');
    }
};
