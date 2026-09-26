<?php

namespace Modules\Organisation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Http\Requests\PairTillRequest;
use Modules\Organisation\Http\Resources\TillResource;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\TillDeviceService;
use Modules\Sales\Services\TillPolicy;
use OpenApi\Attributes as OA;

class TillController extends Controller
{
    public function __construct(
        private readonly TillDeviceService $tills,
        private readonly TillPolicy $policy,
    ) {}

    #[OA\Get(path: '/api/v1/organisation/tills', summary: 'All tills with pairing status', tags: ['Organisation'], responses: [new OA\Response(response: 200, description: 'Tills')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ORGANISATION_MANAGE), 403);

        return $this->success('Tills retrieved.', TillResource::collection(Till::query()->orderBy('branch_id')->orderBy('name')->get()));
    }

    #[OA\Post(
        path: '/api/v1/organisation/tills/pair',
        summary: 'Register this device as a till; returns the device token once',
        tags: ['Organisation'],
        responses: [new OA\Response(response: 201, description: '{till, deviceToken}'), new OA\Response(response: 403, description: 'Needs organisation.manage')],
    )]
    public function pair(PairTillRequest $request): JsonResponse
    {
        ['till' => $till, 'token' => $token] = $this->tills->pair($request->validated(), $request->user());

        return $this->success('Device set up as a till.', ['till' => new TillResource($till), 'deviceToken' => $token], 201);
    }

    #[OA\Post(path: '/api/v1/organisation/tills/{till}/unpair', summary: 'Disconnect the device paired to this till', tags: ['Organisation'], responses: [new OA\Response(response: 200, description: 'Unpaired')])]
    public function unpair(Request $request, Till $till): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ORGANISATION_MANAGE), 403);

        return $this->success('Till device disconnected.', new TillResource($this->tills->unpair($till, $request->user())));
    }

    #[OA\Get(
        path: '/api/v1/organisation/till-context',
        summary: 'Till screen bootstrap (device token required, no login): business, branch, till and who can sign in',
        tags: ['Organisation'],
        responses: [new OA\Response(response: 200, description: 'Context'), new OA\Response(response: 403, description: 'Device not paired')],
    )]
    public function context(Request $request): JsonResponse
    {
        /** @var Till $till */
        $till = $request->attributes->get('till');

        return $this->success('Till context.', [
            'business' => ['name' => $till->branch->business->name],
            'branch' => ['id' => $till->branch->id, 'code' => $till->branch->code, 'name' => $till->branch->name],
            'till' => new TillResource($till),
            // Limits the till enforces up front (the API re-checks them on every sale).
            'policy' => [
                // Settings for this till (till → branch → business → default).
                ...$this->policy->forTill($till),
                'returnWindowDays' => (int) config('sales.return_window_days', 7),
                // "stk": prompt the customer's phone / pick their payment; "manual": type the code (unverified).
                'mpesaMode' => config('payments.mpesa.driver') === 'manual' ? 'manual' : 'stk',
                'mpesaDemo' => config('payments.mpesa.driver') === 'fake',
            ],
            // Display names only — no emails or phone numbers on a shared screen.
            'cashiers' => $this->tills->cashiersFor($till)->map(fn (User $user) => [
                'id' => $user->id,
                'displayName' => $this->displayName($user->name),
                'initials' => $this->initials($user->name),
                'role' => $user->roles->first()?->name,
            ])->values(),
        ]);
    }

    /** "Otieno Kamau" → "Otieno K." */
    private function displayName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return count($parts) > 1 ? $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $parts[0];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }
}
