<?php

namespace App\Console\Commands;

use App\Services\QuoteNotificationSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('quotes:deliver-notifications {--limit=50}')]
#[Description('Deliver committed quote notifications and record transport acceptance')]
class DeliverQuoteNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(QuoteNotificationSender $sender): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        if (config('quotes.notifications_enabled') !== true) {
            $this->info('Quote notifications are disabled; pending intents are preserved.');

            return self::SUCCESS;
        }
        $ids = DB::table('sales_quote_notifications')->whereIn('status', ['PENDING', 'RETRY'])
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
