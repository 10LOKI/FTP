<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_events', 'schema_version')) {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->string('actor_kind', 16)->default('visitor');
                $table->json('role_context')->nullable();
                $table->json('context')->nullable();
                $table->index(['occurred_at', 'id']);
            });
        }
        if (! $this->hasTrigger('audit_events_no_update') && ! $this->hasTrigger('audit_events_no_delete')) {
            DB::table('audit_events')->whereNotNull('actor_id')->update(['actor_kind' => 'account']);
            DB::table('audit_events')->whereNull('actor_id')->whereIn('action', ['notification.delivery', 'employee.access.delivery'])
                ->update(['actor_kind' => 'system']);
        }
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'audit_events_no_'.strtolower($operation);
            if ($this->hasTrigger($name)) {
                continue;
            }
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are immutable'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON audit_events BEGIN SELECT RAISE(ABORT, 'Audit events are immutable'); END");
            } else {
                throw new RuntimeException('Audit immutability requires a supported storage engine.');
            }
        }
    }

    private function hasTrigger(string $name): bool
    {
        return DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->exists()
            : DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at', 'id']);
            $table->dropColumn(['schema_version', 'actor_kind', 'role_context', 'context']);
        });
    }
};
