<?php

namespace App\Console\Commands;

use App\Models\EmployeeAccessDelivery;
use App\Services\EmployeeAccessSender;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendEmployeeAccess extends Command
{
    protected $signature = 'employees:send-access {--limit=50}';

    protected $description = 'Send committed employee temporary access without storing or logging plaintext secrets';

    public function handle(EmployeeAccessSender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $ids = EmployeeAccessDelivery::whereIn('status', ['PENDING', 'RETRY'])->where(function (Builder $query): void {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now('UTC'));
        })->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            if ($sender->send($id)) {
                $sent++;
            }
        }
        $this->info('Access messages accepted by transport: '.$sent);

        return self::SUCCESS;
    }
}
