<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Services\NotificationDeliverySender;
use Illuminate\Console\Command;

class DeliverNotifications extends Command
{
    protected $signature = 'notifications:deliver {--limit=50}';

    protected $description = 'Deliver committed order notifications without repeating the order operation';

    public function handle(NotificationDeliverySender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $ids = NotificationDelivery::whereIn('status', ['PENDING', 'RETRY'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now('UTC')))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += (int) $sender->send($id);
        }
        $this->info('Processed '.$ids->count().' intent(s); '.$sent.' accepted by the delivery transport.');

        return self::SUCCESS;
    }
}
