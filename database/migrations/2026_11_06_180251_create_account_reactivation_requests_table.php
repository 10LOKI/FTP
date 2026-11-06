<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('inactivity_suspended_at')->nullable();
            $table->unsignedInteger('inactivity_version')->default(0);
        });
        Schema::create('account_reactivation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users');
            $table->unsignedInteger('suspension_version');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('PENDING')->index();
            $table->text('request_reason')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reactivation_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['inactivity_suspended_at', 'inactivity_version']);
        });
    }
};
