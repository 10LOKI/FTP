<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_finance_accounts', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained('users')->restrictOnDelete();
            $table->boolean('blocked')->default(false);
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('customer_payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('customer_finance_accounts', 'customer_id')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('mode', 30);
            $table->string('label', 255);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['customer_id', 'version']);
        });
        Schema::create('customer_receivables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('customer_finance_accounts', 'customer_id')->restrictOnDelete();
            $table->foreignUuid('delivery_line_id')->unique()->constrained('delivery_batch_lines')->restrictOnDelete();
            $table->string('source_number', 120);
            // Canonical decimal strings retain every cent on both SQLite and MySQL.
            $table->string('amount', 25);
            $table->date('due_date');
            $table->string('due_origin', 80);
            $table->string('mode', 30);
            $table->json('terms');
            $table->timestamp('created_at');
            $table->index(['customer_id', 'due_date']);
        });
        Schema::create('customer_credit_rights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('customer_finance_accounts', 'customer_id')->restrictOnDelete();
            $table->string('source_key', 120)->unique();
            // Canonical decimal strings retain every cent on both SQLite and MySQL.
            $table->string('amount', 25);
            $table->timestamp('created_at');
        });
        Schema::create('customer_finance_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->foreignId('customer_id')->constrained('customer_finance_accounts', 'customer_id')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->json('payload');
            $table->timestamp('created_at');
            $table->index(['customer_id', 'created_at']);
        });
        Schema::create('customer_finance_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('receivable_id')->unique()->constrained('customer_receivables')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        foreach (['customer_payment_terms', 'customer_receivables', 'customer_credit_rights', 'customer_finance_operations', 'customer_finance_reminders'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_immutable_'.strtolower($event);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial history is immutable'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Financial history is immutable'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_finance_reminders', 'customer_finance_operations', 'customer_credit_rights', 'customer_receivables', 'customer_payment_terms'] as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$event);
            }
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('customer_finance_accounts');
    }
};
