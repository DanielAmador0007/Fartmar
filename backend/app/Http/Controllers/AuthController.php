<?php

namespace App\Http\Controllers;

use App\Domain\Auth\LoginService;
use App\Domain\Auth\UserManagementService;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly LoginService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->string('device_name', 'spa')->toString(),
        );

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']?->toIso8601String(),
            'user' => new UserResource($result['user']),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($this->currentUser($request));

        return response()->noContent();
    }

    /** S-77: cambia la propia contraseña y quita must_change_password. */
    public function changePassword(ChangePasswordRequest $request, UserManagementService $users): UserResource
    {
        return new UserResource($users->changeOwnPassword(
            $this->currentUser($request),
            $request->string('password')->toString(),
        ));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->currentUser($request));
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
