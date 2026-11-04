<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->uuid('request_id')->index();
            $table->string('action')->index();
            $table->string('object_type');
            $table->string('object_id')->nullable()->index();
            $table->enum('result', ['SUCCESS', 'REFUSED', 'FAILED']);
            $table->string('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestampTz('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
