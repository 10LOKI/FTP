<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_id')->unique();
            $table->foreign('draft_id')->references('id')->on('sales_quote_drafts')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('customer_orders')->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->string('number', 120)->unique();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('order_version');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->json('snapshot');
            $table->longText('document_base64');
            $table->char('document_sha256', 64);
            $table->date('valid_until');
            $table->timestamp('issued_at');
            $table->unique(['order_id', 'revision']);
            $table->unique(['order_id', 'request_key']);
        });
        Schema::create('sales_quote_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('quote_id')->unique();
            $table->foreign('quote_id')->references('id')->on('sales_quotes')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->string('outcome', 16);
            $table->string('contact_method', 32);
            $table->string('contact_name', 160);
            $table->string('note', 2000)->nullable();
            $table->timestamp('contacted_at');
            $table->timestamp('created_at');
        });
        foreach (['sales_quotes', 'sales_quote_decisions'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_immutable_'.strtolower($event);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Issued quote records are immutable'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Issued quote records are immutable'); END");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['sales_quotes', 'sales_quote_decisions'] as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$event);
            }
        }
        Schema::dropIfExists('sales_quote_decisions');
        Schema::dropIfExists('sales_quotes');
    }
};
