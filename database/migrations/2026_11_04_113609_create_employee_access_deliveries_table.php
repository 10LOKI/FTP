<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_access_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->enum('status', ['PENDING', 'RETRY', 'SENT', 'EXPIRED'])->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_access_deliveries');
    }
};
