<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_profile_id')->unique()->constrained('customer_profiles');
            $table->foreignUuid('approval_event_id')->unique()->constrained('audit_events');
            $table->string('recipient_email');
            $table->string('customer_code');
            $table->text('portal_url');
            $table->enum('status', ['PENDING', 'RETRY', 'SENT'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_notifications');
    }
};
