<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('last_login_at')->nullable();
        });
        DB::table('users')->update(['last_login_at' => DB::raw(
            "(SELECT MAX(occurred_at) FROM audit_events WHERE audit_events.actor_id = users.id AND action IN ('customer.login', 'employee.login') AND result = 'SUCCESS')",
        )]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_login_at');
        });
    }
};
