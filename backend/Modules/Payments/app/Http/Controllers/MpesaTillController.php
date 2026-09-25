<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use Modules\Payments\Http\Resources\ConfirmationPresenter;
use Modules\Payments\Models\MpesaConfirmation;
use Modules\Payments\Models\MpesaStkRequest;
use Modules\Payments\Services\C2bService;
use Modules\Payments\Services\StkPushService;
use OpenApi\Attributes as OA;

/** M-PESA at the till: send the prompt, watch it, or pick a customer-initiated payment. */
class MpesaTillController extends Controller
{
    public function __construct(private readonly StkPushService $stk) {}

    #[OA\Post(path: '/api/v1/payments/mpesa/stk', summary: 'Send an M-PESA Express (STK Push) prompt to the customer', tags: ['Payments'], responses: [new OA\Response(response: 201, description: 'Request'), new OA\Response(response: 422, description: 'Bad phone / amount / request already waiting')])]
    public function stk(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'amountCents' => ['required', 'integer', 'min:100', 'max:25000000'], // Daraja limit KES 250,000
        ]);

        $stk = $this->stk->initiate($this->till($request), $request->user(), $data['phone'], $data['amountCents']);

        return $this->success('Payment request sent to the customer\'s phone.', $this->stkArray($stk), 201);
    }

    #[OA\Get(path: '/api/v1/payments/mpesa/stk/{stkRequest}', summary: 'Status of an STK request (queries Daraja when the callback is late)', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Request')])]
    public function status(Request $request, MpesaStkRequest $stkRequest): JsonResponse
    {
        $this->requireSeller($request);
        abort_unless($stkRequest->till_id === $this->till($request)->id, 404);

        return $this->success('M-PESA request.', $this->stkArray($this->stk->refresh($stkRequest)->load('confirmation')));
    }

    #[OA\Get(path: '/api/v1/payments/mpesa/unallocated', summary: 'Recent customer-initiated M-PESA payments not yet on a sale', tags: ['Payments'], responses: [new OA\Response(response: 200, description: 'Confirmations')])]
    public function unallocated(Request $request): JsonResponse
    {
        $this->requireSeller($request);
        $till = $this->till($request);
        $hours = (int) config('payments.mpesa.unallocated_window_hours', 24);

        $items = MpesaConfirmation::query()
            ->unallocated()
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $till->branch_id))
            ->where('transacted_at', '>=', now()->subHours($hours))
            ->orderByDesc('transacted_at')
            ->limit(50)
            ->get()
            ->map(fn (MpesaConfirmation $c) => ConfirmationPresenter::till($c))
            ->values();

        return $this->success('Unallocated M-PESA payments.', $items);
    }

    #[OA\Post(path: '/api/v1/payments/mpesa/demo/till-payment', summary: 'Demo M-PESA only: simulate a customer paying the till', tags: ['Payments'], responses: [new OA\Response(response: 201, description: 'Confirmation'), new OA\Response(response: 404, description: 'Not in demo mode')])]
    public function demoTillPayment(Request $request, C2bService $c2b): JsonResponse
    {
        $this->requireSeller($request);
        abort_unless(config('payments.mpesa.driver') === 'fake', 404);
        $amount = $request->validate(['amountCents' => ['required', 'integer', 'min:100', 'max:25000000']])['amountCents'];

        $confirmation = $c2b->record([
            'TransID' => 'DM'.Str::upper(Str::random(8)),
            'TransAmount' => number_format($amount / 100, 2, '.', ''),
            'TransTime' => now('Africa/Nairobi')->format('YmdHis'),
            'MSISDN' => '254700000000',
            'FirstName' => 'DEMO',
            'LastName' => 'CUSTOMER',
            'BillRefNumber' => 'DEMO',
        ]);

        return $this->success('Demo payment received.', ConfirmationPresenter::till($confirmation), 201);
    }

    /** @return array<string, mixed> */
    private function stkArray(MpesaStkRequest $stk): array
    {
        return [
            'id' => $stk->id,
            'status' => $stk->status,
            'phoneMasked' => $stk->phone_masked,
            'amountCents' => $stk->amount_cents,
            'resultDescription' => $stk->result_description,
            'createdAt' => $stk->created_at?->toIso8601String(),
            'timeoutSeconds' => (int) config('payments.mpesa.stk_timeout_seconds', 60),
            'confirmation' => $stk->confirmation ? ConfirmationPresenter::till($stk->confirmation) : null,
        ];
    }

    private function till(Request $request): Till
    {
        return $request->attributes->get('till');
    }

    private function requireSeller(Request $request): void
    {
        abort_unless($request->user()->can(Permissions::SALES_SELL), 403);
    }
}
