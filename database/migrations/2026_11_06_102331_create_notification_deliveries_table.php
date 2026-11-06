<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('trigger_event_id')->constrained('audit_events');
            $table->foreignId('recipient_id')->constrained('users');
            $table->string('type', 64);
            $table->string('channel', 16)->default('email');
            $table->json('payload');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['trigger_event_id', 'recipient_id', 'type', 'channel'], 'notification_delivery_event_recipient_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
