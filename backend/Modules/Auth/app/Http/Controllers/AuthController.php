<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Http\Middleware\EnforceSessionSecurity;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\PinLoginRequest;
use Modules\Auth\Http\Resources\AuthUserResource;
use Modules\Auth\Models\User;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\MfaService;
use Modules\Organisation\Models\Till;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    /** Session key: a password was right, the two-step code is still to come. */
    private const PENDING = 'auth.mfa_pending';

    public function __construct(
        private readonly AuthService $auth,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Back-office sign-in with email or phone (call GET /sanctum/csrf-cookie first)',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['login', 'password'],
            properties: [
                new OA\Property(property: 'login', type: 'string', example: 'owner@tessera.test or 0712345678'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'remember', type: 'boolean', description: 'Keep me signed in on this computer'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in (data = AuthUser), or {mfaStep: verify|setup, setup} when a two-step code is needed'),
            new OA\Response(response: 401, description: 'Invalid credentials or disabled account'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return $this->error('Login must come from an allowed frontend origin.', 400);
        }

        try {
            $user = $this->auth->attempt($request->validated('login'), $request->validated('password'));
        } catch (AuthenticationException $e) {
            return $this->error($e->getMessage(), 401);
        }

        // Two-step login: the password was right; the session starts only after the code.
        $enabled = $this->mfa->enabled($user);
        if ($enabled || $this->mfa->required($user)) {
            $setup = $enabled ? null : $this->mfa->newSetup($user);
            $request->session()->put(self::PENDING, [
                'user' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires' => now()->addMinutes(10)->timestamp,
                'secret' => $setup['secret'] ?? null,
            ]);

            return $this->success(
                $enabled ? 'Enter the code from your authenticator app.' : 'Set up two-step login to continue.',
                ['mfaStep' => $enabled ? 'verify' : 'setup', 'setup' => $setup],
            );
        }

        $this->auth->recordLogin($user, 'auth.login');

        return $this->startSession($request, $user, $request->boolean('remember'), 'password');
    }

    #[OA\Post(path: '/api/v1/auth/mfa/verify', summary: 'Second step of sign-in: code from the authenticator app or a recovery code', tags: ['Auth'], responses: [
        new OA\Response(response: 200, description: 'Logged in; data = AuthUser'),
        new OA\Response(response: 401, description: 'No sign-in waiting (start again)'),
        new OA\Response(response: 422, description: 'Wrong code'),
    ])]
    public function verifyMfa(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        [$user, $pending] = $this->pending($request);
        if (! $user) {
            return $this->error('Your sign-in timed out. Enter your password again.', 401);
        }

        $key = "mfa:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Wait a minute and try again.']);
        }
        if (! $this->mfa->check($user, $code)) {
            RateLimiter::hit($key, 60);
            $this->audit->log('auth.mfa.failed', $user, reason: 'Wrong two-step code', userId: $user->id);
            throw ValidationException::withMessages(['code' => 'That code is not right. Use the newest code in your app.']);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING);
        $this->auth->recordLogin($user, 'auth.login', reason: 'Two-step code');

        return $this->startSession($request, $user, (bool) $pending['remember'], 'password');
    }

    #[OA\Post(path: '/api/v1/auth/mfa/setup', summary: 'Finish the first sign-in with two-step login: confirm the app code, get recovery codes', tags: ['Auth'], responses: [
        new OA\Response(response: 200, description: '{user, recoveryCodes}'),
        new OA\Response(response: 401, description: 'No sign-in waiting (start again)'),
        new OA\Response(response: 422, description: 'Wrong code'),
    ])]
    public function setupMfa(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        [$user, $pending] = $this->pending($request);
        if (! $user || ! $pending['secret']) {
            return $this->error('Your sign-in timed out. Enter your password again.', 401);
        }

        $codes = $this->mfa->enable($user, $pending['secret'], $code);
        $request->session()->forget(self::PENDING);
        $this->auth->recordLogin($user, 'auth.login', reason: 'Two-step login set up');
        $this->startSession($request, $user, (bool) $pending['remember'], 'password');

        return $this->success('Two-step login is on.', ['user' => new AuthUserResource($user), 'recoveryCodes' => $codes]);
    }

    /** @return array{0: User|null, 1: array<string, mixed>} */
    private function pending(Request $request): array
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || $pending['expires'] < now()->timestamp) {
            return [null, []];
        }
        $user = User::query()->find($pending['user']);

        return [$user?->is_active ? $user : null, $pending];
    }

    #[OA\Post(
        path: '/api/v1/auth/pin-login',
        summary: 'Cashier sign-in at a paired till (X-Till-Token header) with a 4–6 digit PIN',
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Logged in; data = AuthUser'),
            new OA\Response(response: 401, description: 'Wrong PIN or not allowed at this till'),
            new OA\Response(response: 403, description: 'Device is not a paired till'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function pinLogin(PinLoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return $this->error('Login must come from an allowed frontend origin.', 400);
        }

        /** @var Till $till */
        $till = $request->attributes->get('till');

        try {
            $user = $this->auth->attemptPin($till, $request->integer('userId'), $request->validated('pin'));
        } catch (AuthenticationException $e) {
            return $this->error($e->getMessage(), 401);
        }

        return $this->startSession($request, $user, false, 'pin');
    }

    #[OA\Post(path: '/api/v1/auth/logout', summary: 'End the current session', tags: ['Auth'], responses: [
        new OA\Response(response: 200, description: 'Logged out'),
    ])]
    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            $this->auth->recordLogout($user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success('Logged out.');
    }

    #[OA\Get(path: '/api/v1/auth/me', summary: 'Current user with roles and permissions', tags: ['Auth'], responses: [
        new OA\Response(response: 200, description: 'AuthUser'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ])]
    public function me(Request $request): JsonResponse
    {
        return $this->success('Authenticated user.', new AuthUserResource($request->user()));
    }

    /** @param 'password'|'pin' $via */
    private function startSession(Request $request, User $user, bool $remember, string $via): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            EnforceSessionSecurity::VIA => $via,
            EnforceSessionSecurity::REMEMBER => $remember,
            EnforceSessionSecurity::LAST_SEEN => now()->timestamp,
        ]);

        return $this->success('Login successful.', new AuthUserResource($user));
    }
}
