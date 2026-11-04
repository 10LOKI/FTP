<?php

namespace App\Console\Commands;

use App\Models\ApprovalNotification;
use App\Services\ApprovalNotificationSender;
use Illuminate\Console\Command;

class SendApprovalNotifications extends Command
{
    protected $signature = 'customers:send-approval-notifications {--limit=50 : Maximum notifications to process (1-1000)}';

    protected $description = 'Send committed customer approval notifications and retry due delivery failures';

    public function handle(ApprovalNotificationSender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        $ids = ApprovalNotification::readyForDelivery()->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $sent = 0;

        foreach ($ids as $id) {
            if ($sender->send($id)) {
                $sent++;
            }
        }

        $this->info('Processed '.$ids->count().' notification(s); '.$sent.' accepted by the configured mail transport.');

        return self::SUCCESS;
    }
}
