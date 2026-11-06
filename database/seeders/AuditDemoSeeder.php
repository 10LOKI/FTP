<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AuditDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || getenv('FTP_AUDIT_DEMO') !== '1') {
            return;
        }
        for ($index = 1; $index <= 25; $index++) {
            AuditEvent::create([
                'actor_id' => null, 'request_id' => (string) Str::uuid(), 'action' => 'audit.demo',
                'object_type' => 'fixture', 'object_id' => (string) $index,
                'result' => $index % 3 === 0 ? 'REFUSED' : 'SUCCESS', 'reason' => 'local_fixture',
                'changes' => ['after' => ['count' => $index]], 'occurred_at' => now('UTC')->subSeconds($index),
            ]);
        }
        $this->command?->info('25 événements fictifs créés ; aucun compte, droit ou envoi ajouté.');
    }
}
