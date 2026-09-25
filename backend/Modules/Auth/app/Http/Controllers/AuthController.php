<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\PinLoginRequest;
use Modules\Auth\Http\Resources\AuthUserResource;
use Modules\Auth\Models\User;
use Modules\Auth\Services\AuthService;
use Modules\Organisation\Models\Till;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

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
            new OA\Response(response: 200, description: 'Logged in; data = AuthUser'),
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

        return $this->startSession($request, $user, $request->boolean('remember'));
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

        return $this->startSession($request, $user, false);
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

    private function startSession(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return $this->success('Login successful.', new AuthUserResource($user));
    }
}
