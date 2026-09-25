<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Payments\Services\C2bService;
use Modules\Payments\Services\StkPushService;
use OpenApi\Attributes as OA;

/**
 * Safaricom → Tessera. Public (no session); authenticated by the secret path token.
 * Always answers in Daraja's format so Safaricom does not keep retrying.
 */
class MpesaCallbackController extends Controller
{
    public function __construct(
        private readonly StkPushService $stk,
        private readonly C2bService $c2b,
    ) {}

    #[OA\Post(path: '/api/v1/payments/mpesa/callbacks/{token}/stk', summary: 'Daraja STK Push result callback', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Accepted')])]
    public function stk(Request $request, string $token): JsonResponse
    {
        $this->authorizeToken($token);
        $callback = $request->input('Body.stkCallback');
        if (is_array($callback)) {
            $this->stk->handleCallback($callback);
        }

        return $this->accepted();
    }

    #[OA\Post(path: '/api/v1/payments/mpesa/callbacks/{token}/validation', summary: 'Daraja C2B validation (accept every payment)', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Accepted')])]
    public function validation(string $token): JsonResponse
    {
        $this->authorizeToken($token);

        return $this->accepted();
    }

    #[OA\Post(path: '/api/v1/payments/mpesa/callbacks/{token}/confirmation', summary: 'Daraja C2B confirmation: customer paid the till/paybill', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Recorded')])]
    public function confirmation(Request $request, string $token): JsonResponse
    {
        $this->authorizeToken($token);
        if (! $this->c2b->record($request->all())) {
            Log::warning('M-PESA C2B confirmation without TransID/TransAmount ignored');
        }

        return $this->accepted();
    }

    private function authorizeToken(string $token): void
    {
        $expected = (string) config('payments.mpesa.callback_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
