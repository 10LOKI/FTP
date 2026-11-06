<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_address_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_profile_id')->constrained('customer_profiles');
            $table->foreignUuid('active_customer_profile_id')->nullable()->unique()->constrained('customer_profiles');
            $table->unsignedInteger('profile_version');
            $table->unsignedInteger('version')->default(1);
            $table->string('address', 1000);
            $table->string('city');
            $table->string('country');
            $table->string('status')->default('PENDING')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_address_changes');
    }
};
