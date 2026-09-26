<?php

namespace Modules\Notifications\Console;

use Illuminate\Console\Command;
use Modules\Notifications\Services\MessageSender;
use Modules\Notifications\Services\ScheduledAlerts;

/** Scheduled every minute: time-based alerts, then the SMS / WhatsApp / email outbox. */
class RunNotifications extends Command
{
    protected $signature = 'notifications:run {--summary-now : Send today\'s end-of-day summary now}';

    protected $description = 'Raise scheduled alerts (daily summary, eTIMS waiting) and send the message outbox';

    public function handle(ScheduledAlerts $scheduled, MessageSender $sender): int
    {
        $scheduled->dailySummary((bool) $this->option('summary-now'));
        $scheduled->etimsOverdue();
        ['sent' => $sent, 'failed' => $failed] = $sender->sendPending();
        $this->info("Messages sent: {$sent}, failed: {$failed}.");

        return self::SUCCESS;
    }
}
