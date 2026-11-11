<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('customer_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 120)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->json('snapshot');
            $table->decimal('total', 24, 2);
            $table->longText('document_base64');
            $table->char('document_sha256', 64);
            $table->timestamp('issued_at');
            $table->index(['customer_id', 'issued_at']);
        });
        foreach (['customer_invoices', 'billing_quantity_allocations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_immutable_'.strtolower($event);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued billing records are immutable'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Issued billing records are immutable'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_invoices', 'billing_quantity_allocations'] as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$event);
            }
        }
        Schema::dropIfExists('customer_invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
