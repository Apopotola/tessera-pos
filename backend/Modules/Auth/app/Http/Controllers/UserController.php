<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\SetPinRequest;
use Modules\Auth\Http\Requests\UserRequest;
use Modules\Auth\Http\Resources\ManagedUserResource;
use Modules\Auth\Models\User;
use Modules\Auth\Services\UserService;
use Modules\Authorization\Support\Permissions;
use Modules\Authorization\Support\Roles;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    #[OA\Get(path: '/api/v1/auth/users', summary: 'Staff accounts with role, branches and PIN status', tags: ['Users'], responses: [new OA\Response(response: 200, description: 'Users')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);

        $users = User::query()->with(['roles', 'branches'])->orderBy('name')->get();

        return $this->success('Users retrieved.', [
            'items' => ManagedUserResource::collection($users),
            'roles' => array_keys(Roles::defaults()),
        ]);
    }

    #[OA\Post(path: '/api/v1/auth/users', summary: 'Create a staff account', tags: ['Users'], responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated(), $request->user());

        return $this->success('User created.', new ManagedUserResource($user->load(['roles', 'branches'])), 201);
    }

    #[OA\Put(path: '/api/v1/auth/users/{user}', summary: 'Update a staff account (role, branches, active)', tags: ['Users'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(UserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->validated(), $request->user());

        return $this->success('User updated.', new ManagedUserResource($user->load(['roles', 'branches'])));
    }

    #[OA\Post(path: '/api/v1/auth/users/{user}/pin', summary: 'Set a 4-digit till PIN (pin + pin_confirmation)', tags: ['Users'], responses: [new OA\Response(response: 200, description: 'PIN set')])]
    public function setPin(SetPinRequest $request, User $user): JsonResponse
    {
        $user = $this->users->setPin($user, $request->validated('pin'));

        return $this->success('PIN set.', new ManagedUserResource($user->load(['roles', 'branches'])));
    }
}
