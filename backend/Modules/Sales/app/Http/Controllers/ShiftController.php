<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organisation\Models\Till;
use Modules\Sales\Http\Resources\ShiftResource;
use Modules\Sales\Models\Shift;
use Modules\Sales\Services\ShiftService;
use OpenApi\Attributes as OA;

/** Till shift endpoints. All require a signed-in user on a paired till device. */
class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    #[OA\Get(path: '/api/v1/sales/shifts/current', summary: 'Open shift on this till, if any', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Shift or null')])]
    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->openShiftOn($this->till($request));

        return $this->success('Current shift.', $shift ? new ShiftResource($shift) : null);
    }

    #[OA\Post(
        path: '/api/v1/sales/shifts',
        summary: 'Start (or resume) the signed-in cashier\'s shift on this till',
        tags: ['Sales'],
        responses: [new OA\Response(response: 201, description: 'Opened'), new OA\Response(response: 200, description: 'Resumed'), new OA\Response(response: 422, description: 'Till busy')],
    )]
    public function start(Request $request): JsonResponse
    {
        $till = $this->till($request);
        $data = $request->validate(['openingFloatCents' => ['nullable', 'integer', 'min:0', 'max:100000000']]);

        ['shift' => $shift, 'resumed' => $resumed] = $this->shifts->start($till, $request->user(), $data['openingFloatCents'] ?? $till->default_float_cents);

        return $this->success($resumed ? 'Shift resumed.' : 'Shift started.', new ShiftResource($shift), $resumed ? 200 : 201);
    }

    #[OA\Post(path: '/api/v1/sales/shifts/{shift}/close', summary: 'End a shift with a blind cash count', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Closed; expected and variance revealed')])]
    public function close(Request $request, Shift $shift): JsonResponse
    {
        abort_unless($shift->till_id === $this->till($request)->id, 404);

        $data = $request->validate([
            'countedCashCents' => ['required_without:denominations', 'integer', 'min:0', 'max:100000000'],
            // Count by denomination: {"100000": 3, "50000": 1, …} (cents => pieces).
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = $this->shifts->close($shift, $request->user(), (int) ($data['countedCashCents'] ?? 0), $data['note'] ?? null, $data['denominations'] ?? null);

        return $this->success('Shift ended.', new ShiftResource($shift->load('user')));
    }

    #[OA\Post(path: '/api/v1/sales/shifts/{shift}/cash-drops', summary: 'Move excess cash to the safe (manager PIN witness)', tags: ['Sales'], responses: [new OA\Response(response: 201, description: 'Recorded')])]
    public function cashDrop(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate([
            'amountCents' => ['required', 'integer', 'min:100', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:300'],
            'approvalToken' => ['required', 'string', 'max:60'],
        ]);

        $drop = $this->shifts->cashDrop($shift, $this->till($request), $request->user(), $data['amountCents'], $data['note'] ?? null, $data['approvalToken']);

        return $this->success('Cash drop recorded.', ['id' => $drop->id, 'amountCents' => $drop->amount_cents, 'dropsCents' => $shift->fresh()->drops_cents], 201);
    }

    #[OA\Post(path: '/api/v1/sales/shifts/{shift}/variance-reason', summary: 'Cashier explains a cash-up difference after closing', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Saved')])]
    public function varianceReason(Request $request, Shift $shift): JsonResponse
    {
        abort_unless($shift->till_id === $this->till($request)->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->success('Reason saved.', new ShiftResource($this->shifts->explainVariance($shift, $request->user(), $reason)));
    }

    private function till(Request $request): Till
    {
        return $request->attributes->get('till');
    }
}
