<?php

namespace App\Http\Controllers;

use App\Domain\Auth\UserManagementService;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Usuarios (solo admin). Sin DELETE: se desactiva (S-72).
 */
class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $service) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return UserResource::collection(User::query()->orderBy('name')->orderBy('id')->paginate(50));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->create($this->currentUser($request), $request->userData());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->service->update($this->currentUser($request), $user, $request->changes()));
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
