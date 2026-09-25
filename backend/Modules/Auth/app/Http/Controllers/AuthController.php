<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Resources\AuthUserResource;
use Modules\Auth\Services\AuthService;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Start a Sanctum SPA session (call GET /sanctum/csrf-cookie first)',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Logged in; data = AuthUser'),
            new OA\Response(response: 401, description: 'Invalid credentials or disabled account'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        // Sanctum only starts a session for origins listed in SANCTUM_STATEFUL_DOMAINS.
        if (! $request->hasSession()) {
            return $this->error('Login must come from an allowed frontend origin.', 400);
        }

        try {
            $user = $this->auth->attempt($request->validated('email'), $request->validated('password'));
        } catch (AuthenticationException $e) {
            return $this->error($e->getMessage(), 401);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->success('Login successful.', new AuthUserResource($user));
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
}
