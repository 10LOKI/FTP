<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identity_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_profile_id')->constrained('customer_profiles');
            $table->foreignUuid('active_customer_profile_id')->nullable()->unique()->constrained('customer_profiles');
            $table->unsignedInteger('profile_version');
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->string('email');
            $table->string('type');
            $table->string('company_name')->nullable();
            $table->string('responsible_name')->nullable();
            $table->unsignedInteger('verification_revision')->default(1);
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('verification_expires_at')->nullable();
            $table->string('status')->default('PENDING')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identity_changes');
    }
};
