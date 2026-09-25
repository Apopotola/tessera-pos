<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Gateways\DarajaGateway;
use Modules\Payments\Support\StkStatus;
use Tests\TestCase;

/** The live driver, against a faked Safaricom: checks what we send and how we read replies. */
class DarajaGatewayTest extends TestCase
{
    private function gateway(): DarajaGateway
    {
        return new DarajaGateway([
            'environment' => 'sandbox', 'consumer_key' => 'key', 'consumer_secret' => 'secret',
            'shortcode' => '174379', 'passkey' => 'pass', 'party_b' => '600000',
            'transaction_type' => 'CustomerBuyGoodsOnline', 'callback_base_url' => 'https://pos.example.co.ke', 'callback_token' => 'tok',
        ]);
    }

    public function test_stk_push_sends_whole_shillings_signed_password_and_secret_callback(): void
    {
        Cache::flush();
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'abc', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response(['ResponseCode' => '0', 'MerchantRequestID' => 'm1', 'CheckoutRequestID' => 'ws_CO_1']),
        ]);

        $ids = $this->gateway()->stkPush('254712345678', 480000, 'MAIN', 'Tessera sale');

        $this->assertSame('ws_CO_1', $ids['checkoutRequestId']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'stkpush')) {
                return false;
            }
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer abc')
                && $body['Amount'] === 4800
                && $body['PartyB'] === '600000'
                && $body['Password'] === base64_encode('174379pass'.$body['Timestamp'])
                && $body['CallBackURL'] === 'https://pos.example.co.ke/api/v1/payments/mpesa/callbacks/tok/stk';
        });
    }

    public function test_query_reports_cancelled_as_failed_and_processing_as_pending(): void
    {
        Cache::put('mpesa.daraja.token', 'abc', 60);
        Http::fake(['sandbox.safaricom.co.ke/mpesa/stkpushquery/*' => Http::sequence()
            ->push(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500)
            ->push(['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']),
        ]);

        $this->assertSame(StkStatus::PENDING, $this->gateway()->stkQuery('ws_CO_1')->state);
        $this->assertSame(StkStatus::FAILED, $this->gateway()->stkQuery('ws_CO_1')->state);
    }
}
