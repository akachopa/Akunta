<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * plan.md §29.1: POST /auth/login, POST /auth/logout, GET /me.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak cocok.']);
        }

        /** @var User $user */
        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        $token = $user->createToken($credentials['device_name'] ?? 'api');

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logout berhasil.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->userPayload($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin,
            'businesses' => $user->businesses()->orderBy('name')->get()->map(
                fn (Business $business): array => [
                    'id' => $business->getKey(),
                    'name' => $business->name,
                    'role' => $user->roleIn($business)?->slug->value,
                ]
            ),
        ];
    }
}
