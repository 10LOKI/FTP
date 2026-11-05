<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_access_deliveries', function (Blueprint $table): void {
            $table->index('user_id');
        });
        Schema::table('employee_access_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->unsignedInteger('requested_access_version')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('employee_access_deliveries')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove delivery history while multiple deliveries exist for an account.');
        }
        Schema::table('employee_access_deliveries', function (Blueprint $table): void {
            $table->unique('user_id');
        });
        Schema::table('employee_access_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropColumn('requested_access_version');
        });
    }
};
