<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_code_sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('next_number');
        });
        DB::table('customer_code_sequences')->insert(['name' => 'customer', 'next_number' => 1]);

        Schema::create('issued_customer_codes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->foreignUuid('customer_profile_id')->unique()->constrained('customer_profiles');
            $table->timestampTz('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_customer_codes');
        Schema::dropIfExists('customer_code_sequences');
    }
};
