<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Http\Middleware\EnforceSessionSecurity;
use Modules\Auth\Http\Resources\AuthUserResource;
use Modules\Auth\Http\Resources\ManagedUserResource;
use Modules\Auth\Models\User;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\MfaService;
use Modules\Auth\Services\PasswordService;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Till;
use OpenApi\Attributes as OA;

/** Your own password and two-step login, an admin's two-step reset, and locking the till screen. */
class AccountSecurityController extends Controller
{
    private const SETUP_SECRET = 'auth.mfa_setup_secret';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly PasswordService $passwords,
        private readonly AuthService $auth,
    ) {}

    #[OA\Post(path: '/api/v1/auth/password', summary: 'Change your own password', tags: ['Auth'], responses: [new OA\Response(response: 200, description: 'AuthUser'), new OA\Response(response: 422, description: 'Wrong current password / too short')])]
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min($this->passwords->minLength())],
        ]);
        $this->passwords->change($request->user(), $data['currentPassword'], $data['password']);

        return $this->success('Password changed.', new AuthUserResource($request->user()->fresh()));
    }

    #[OA\Get(path: '/api/v1/auth/mfa', summary: 'Your two-step login status', tags: ['Auth'], responses: [new OA\Response(response: 200, description: '{enabled, required, recoveryCodesLeft}')])]
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success('Two-step login.', [
            'enabled' => $this->mfa->enabled($user),
            'required' => $this->mfa->required($user),
            'recoveryCodesLeft' => count((array) $user->mfa_recovery_codes),
        ]);
    }

    #[OA\Post(path: '/api/v1/auth/mfa/start', summary: 'Start setting up two-step login: secret and QR link for the app', tags: ['Auth'], responses: [new OA\Response(response: 200, description: '{secret, uri}')])]
    public function start(Request $request): JsonResponse
    {
        $setup = $this->mfa->newSetup($request->user());
        $request->session()->put(self::SETUP_SECRET, $setup['secret']);

        return $this->success('Scan the code with your authenticator app.', $setup);
    }

    #[OA\Post(path: '/api/v1/auth/mfa/enable', summary: 'Confirm the app code; two-step login is on; recovery codes returned once', tags: ['Auth'], responses: [new OA\Response(response: 200, description: '{recoveryCodes}')])]
    public function enable(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $secret = $request->session()->get(self::SETUP_SECRET) ?? throw ValidationException::withMessages(['code' => 'Start the set-up again.']);

        $codes = $this->mfa->enable($request->user(), $secret, $code);
        $request->session()->forget(self::SETUP_SECRET);

        return $this->success('Two-step login is on.', ['recoveryCodes' => $codes]);
    }

    #[OA\Post(path: '/api/v1/auth/mfa/disable', summary: 'Turn off your two-step login (not when your role requires it)', tags: ['Auth'], responses: [new OA\Response(response: 200, description: 'Off')])]
    public function disable(Request $request): JsonResponse
    {
        $this->mfa->disable($request->user(), $request->validate(['code' => ['required', 'string', 'max:20']])['code']);

        return $this->success('Two-step login is off.');
    }

    #[OA\Post(path: '/api/v1/auth/users/{user}/mfa/reset', summary: 'Reset a user\'s two-step login (lost phone); they set it up at next sign-in', tags: ['Auth'], responses: [new OA\Response(response: 200, description: 'ManagedUser')])]
    public function reset(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $this->mfa->reset($request->user(), $user);

        return $this->success("Two-step login reset for {$user->name}.", new ManagedUserResource($user->fresh()->load(['roles', 'branches'])));
    }

    #[OA\Post(path: '/api/v1/auth/till/lock', summary: 'Lock the till screen (idle or by the cashier); the sale in progress is kept', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'Locked')])]
    public function lockTill(Request $request): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'in:idle,manual']])['reason'] ?? 'manual';
        $request->session()->put(EnforceSessionSecurity::TILL_LOCKED, true);
        $this->auth->lockTill($this->till($request), $request->user(), $reason);

        return $this->success('Till locked.');
    }

    #[OA\Post(path: '/api/v1/auth/till/unlock', summary: 'Unlock the till screen with the cashier\'s PIN or a branch manager\'s', tags: ['Till'], responses: [new OA\Response(response: 200, description: 'AuthUser'), new OA\Response(response: 422, description: 'Wrong PIN')])]
    public function unlockTill(Request $request): JsonResponse
    {
        $data = $request->validate(['userId' => ['required', 'integer'], 'pin' => ['required', 'string', 'regex:/^\d{4,6}$/']]);
        $managerId = $this->auth->unlockTill($this->till($request), $request->user(), (int) $data['userId'], $data['pin']);
        $request->session()->forget(EnforceSessionSecurity::TILL_LOCKED);

        return $this->success($managerId ? 'Unlocked by a manager.' : 'Welcome back.', new AuthUserResource($request->user()));
    }

    private function till(Request $request): Till
    {
        return $request->attributes->get('till');
    }
}
