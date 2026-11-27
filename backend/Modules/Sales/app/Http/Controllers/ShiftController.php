<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organisation\Models\Till;
use Modules\Sales\Http\Requests\CashCheckRequest;
use Modules\Sales\Http\Resources\CashCheckResource;
use Modules\Sales\Http\Resources\ShiftResource;
use Modules\Sales\Models\Shift;
use Modules\Sales\Services\ShiftService;
use Modules\Sales\Services\TillPolicy;
use OpenApi\Attributes as OA;

/** Till shift endpoints. All require a signed-in user on a paired till device. */
class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly TillPolicy $policy,
    ) {}

    #[OA\Get(path: '/api/v1/sales/shifts/current', summary: 'Open shift on this till, if any', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Shift or null')])]
    public function current(Request $request): JsonResponse
    {
        $till = $this->till($request);
        $shift = $this->shifts->openShiftOn($till);
        if (! $shift) {
            return $this->success('Current shift.', null);
        }

        // Blind cash-up off (Settings → Staff): the cashier sees what the drawer should hold.
        return $this->success('Current shift.', [
            ...(new ShiftResource($shift))->toArray($request),
            'expectedCashCents' => $this->policy->blindCashUp($till) ? null : $this->shifts->cashInDrawer($shift),
        ]);
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

    #[OA\Get(
        path: '/api/v1/sales/shifts/{shift}/summary',
        summary: 'Till summary since the last cash check, without closing (manager): by waiter, by payment method, drops, pay-outs, expected cash unless blind',
        tags: ['Sales'],
        responses: [new OA\Response(response: 200, description: 'Summary'), new OA\Response(response: 403, description: 'Not a manager')],
    )]
    public function summary(Request $request, Shift $shift): JsonResponse
    {
        abort_unless($shift->till_id === $this->till($request)->id, 404);

        return $this->success('Till summary.', $this->shifts->summary($shift, $this->till($request), $request->user()));
    }

    #[OA\Post(
        path: '/api/v1/sales/shifts/{shift}/cash-checks',
        summary: 'Cash check on a shared till (manager): count the drawer without closing; the next count starts from the cash found',
        tags: ['Sales'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'countedCashCents', type: 'integer'),
            new OA\Property(property: 'denominations', type: 'object', description: 'cents => pieces'),
            new OA\Property(property: 'note', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: 'Recorded; expected and variance revealed'), new OA\Response(response: 403, description: 'Not a manager'), new OA\Response(response: 422, description: 'Not a shared till')],
    )]
    public function cashCheck(CashCheckRequest $request, Shift $shift): JsonResponse
    {
        abort_unless($shift->till_id === $this->till($request)->id, 404);
        $data = $request->validated();

        $check = $this->shifts->cashCheck($shift, $this->till($request), $request->user(), (int) ($data['countedCashCents'] ?? 0), $data['note'] ?? null, $data['denominations'] ?? null);

        return $this->success('Cash check recorded.', new CashCheckResource($check->load(['user', 'till'])), 201);
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
