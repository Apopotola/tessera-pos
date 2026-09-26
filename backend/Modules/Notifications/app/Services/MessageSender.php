<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Models\OutboundMessage;
use Throwable;

/**
 * Sends the outbox. Email goes through Laravel mail (MAIL_MAILER; "log" in development).
 * SMS and WhatsApp use the "log" driver until a provider (e.g. Africa's Talking) and a
 * registered sender ID are chosen: the message is written to the log and nothing leaves
 * the server — the outbox shows driver "log" so no one mistakes it for a real delivery.
 */
class MessageSender
{
    /** @return array{sent: int, failed: int} */
    public function sendPending(int $limit = 100): array
    {
        $sent = $failed = 0;
        $messages = OutboundMessage::query()->where('status', OutboundMessage::PENDING)->orderBy('id')->limit($limit)->get();

        foreach ($messages as $message) {
            $this->send($message) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function send(OutboundMessage $message): bool
    {
        try {
            $driver = match ($message->channel) {
                'email' => $this->email($message),
                'sms' => $this->logged($message, (string) config('notifications.sms_driver')),
                'whatsapp' => $this->logged($message, (string) config('notifications.whatsapp_driver')),
                default => throw new \InvalidArgumentException("Unknown channel {$message->channel}"),
            };
            $message->forceFill(['status' => OutboundMessage::SENT, 'driver' => $driver, 'attempts' => $message->attempts + 1, 'sent_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            $attempts = $message->attempts + 1;
            $message->forceFill([
                'attempts' => $attempts,
                'status' => $attempts >= OutboundMessage::MAX_ATTEMPTS ? OutboundMessage::FAILED : OutboundMessage::PENDING,
                'last_error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            return false;
        }
    }

    private function email(OutboundMessage $message): string
    {
        Mail::raw($message->body, fn ($mail) => $mail->to($message->recipient)->subject($message->subject ?? 'Tessera POS'));

        return 'mail:'.config('mail.default');
    }

    /** Demo / development: write the message to the log instead of sending it. */
    private function logged(OutboundMessage $message, string $driver): string
    {
        if ($driver !== 'log') {
            throw new \RuntimeException("No {$message->channel} provider is set up (driver \"{$driver}\").");
        }
        Log::info("[{$message->channel} not sent — demo] to {$message->recipient}: {$message->body}");

        return 'log';
    }
}
