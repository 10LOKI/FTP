<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained();
            $table->enum('type', ['PARTICULIER', 'ENTREPRISE']);
            $table->string('phone', 50);
            $table->string('address', 1000);
            $table->string('city');
            $table->string('country');
            $table->string('company_name')->nullable();
            $table->string('responsible_name')->nullable();
            $table->enum('approval', ['EN_ATTENTE', 'APPROUVE', 'REFUSE'])->default('EN_ATTENTE');
            $table->string('customer_code')->nullable()->unique();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
