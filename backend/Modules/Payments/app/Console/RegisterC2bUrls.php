<?php

namespace Modules\Payments\Console;

use Illuminate\Console\Command;
use Modules\Payments\Contracts\MpesaGateway;
use Throwable;

/** One-off per till/paybill: tell Safaricom where to send customer-initiated payments. */
class RegisterC2bUrls extends Command
{
    protected $signature = 'mpesa:register-c2b';

    protected $description = 'Register the M-PESA C2B confirmation and validation URLs with Daraja';

    public function handle(MpesaGateway $gateway): int
    {
        $token = (string) config('payments.mpesa.callback_token');
        if ($token === '') {
            $this->error('Set MPESA_CALLBACK_TOKEN (a long random string) first.');

            return self::FAILURE;
        }

        $base = rtrim((string) config('payments.mpesa.callback_base_url'), '/')."/api/v1/payments/mpesa/callbacks/{$token}";
        if (! str_starts_with($base, 'https://')) {
            $this->warn('Safaricom only calls HTTPS URLs. Use your public URL (or an ngrok tunnel in development).');
        }

        try {
            $result = $gateway->registerC2bUrls("{$base}/confirmation", "{$base}/validation");
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Registered: '.json_encode($result));

        return self::SUCCESS;
    }
}
