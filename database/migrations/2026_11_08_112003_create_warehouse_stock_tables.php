<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('warehouse_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('aisle', 60)->nullable();
            $table->string('shelf', 60)->nullable();
            $table->string('level', 60)->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            foreach (['physical', 'reserved', 'control', 'rejected'] as $quantity) {
                $table->unsignedInteger($quantity)->default(0);
            }
            $table->unsignedInteger('threshold_quantity')->nullable();
            $table->string('threshold_basis', 16)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
        });
        Schema::create('stock_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->char('request_hash', 64);
            $table->json('result')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_id')->constrained('stock_operations')->restrictOnDelete();
            $table->foreignUuid('balance_id')->constrained('stock_balances')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('reason', 255);
            $table->string('source_reference', 120);
            foreach (['physical', 'reserved', 'control', 'rejected'] as $quantity) {
                $table->integer($quantity.'_delta');
            }
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['balance_id', 'created_at']);
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->uuid('line_id');
            $table->foreignUuid('balance_id')->constrained('stock_balances')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('released')->default(0);
            $table->unsignedInteger('consumed')->default(0);
            $table->unsignedInteger('order_version');
            $table->foreignUuid('operation_id')->constrained('stock_operations')->restrictOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'line_id']);
        });
        Schema::create('stock_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('balance_id')->constrained('stock_balances')->restrictOnDelete();
            $table->foreignUuid('active_balance_id')->nullable()->unique()->constrained('stock_balances')->restrictOnDelete();
            $table->string('basis', 16);
            $table->unsignedInteger('threshold');
            $table->unsignedInteger('observed_quantity');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_quantities_valid CHECK (physical >= reserved AND physical <= 2147483647 AND reserved <= 2147483647 AND control <= 2147483647 AND rejected <= 2147483647)');
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER stock_movements_no_{$event} BEFORE {$event} ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared("CREATE TRIGGER stock_balances_check_{$event} BEFORE {$event} ON stock_balances WHEN NEW.reserved < 0 OR NEW.control < 0 OR NEW.rejected < 0 OR NEW.physical < NEW.reserved OR NEW.physical > 2147483647 OR NEW.reserved > 2147483647 OR NEW.control > 2147483647 OR NEW.rejected > 2147483647 BEGIN SELECT RAISE(ABORT, 'Invalid stock quantities'); END");
            }
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER stock_movements_no_{$event} BEFORE {$event} ON stock_movements BEGIN SELECT RAISE(ABORT, 'Stock movements are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['stock_alerts', 'stock_reservations', 'stock_movements', 'stock_operations', 'stock_balances', 'warehouse_locations', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
