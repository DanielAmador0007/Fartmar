<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Emite tokens personales de Sanctum. Solo usuarios activos.
 */
final class LoginService
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{user: User, token: string, expires_at: CarbonInterface|null}
     */
    public function login(string $email, string $password, string $deviceName): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        // Hash::check se ejecuta aunque el usuario no exista para que el tiempo
        // de respuesta no revele qué correos están registrados.
        $validPassword = Hash::check($password, $user?->getAuthPassword() ?? self::dummyHash());

        if ($user === null || ! $validPassword || ! $user->is_active) {
            throw InvalidCredentialsException::make();
        }

        // El Guard de Sanctum ya rechaza tokens más viejos que sanctum.expiration;
        // guardar expires_at además permite informarlo al cliente y purgarlos.
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;

        $token = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;
        $this->audit->record($user, 'auth.login');

        return ['user' => $user, 'token' => $token, 'expires_at' => $expiresAt];
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }

    public function logout(User $user): void
    {
        // Revoca solo el token con el que se hizo la petición.
        $user->currentAccessToken()->delete();
        $this->audit->record($user, 'auth.logout');
    }
}
