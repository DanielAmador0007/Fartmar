<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exceptions\InvalidCredentialsException;
use App\Domain\Patients\DocumentHasher;
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
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        // Hash::check se ejecuta aunque el usuario no exista para que el tiempo
        // de respuesta no revele qué correos están registrados.
        $validPassword = Hash::check($password, $user?->getAuthPassword() ?? self::dummyHash());

        if ($user === null || ! $validPassword || ! $user->is_active) {
            $this->recordFailure($email, $user, $validPassword);

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

    /**
     * auth.login_failed en audit_logs para detectar fuerza bruta (S-71).
     *
     * El correo NO se guarda en claro: se guarda HMAC-SHA256 del correo
     * normalizado con la clave del servidor. Así se pueden agrupar intentos
     * contra la misma cuenta (o contra correos que no existen) sin que la
     * bitácora sea una lista de correos. El motivo es interno: al cliente
     * siempre se le responde lo mismo.
     */
    private function recordFailure(string $email, ?User $user, bool $validPassword): void
    {
        $reason = match (true) {
            $user === null => 'USUARIO_DESCONOCIDO',
            ! $validPassword => 'CLAVE_INCORRECTA',
            default => 'USUARIO_INACTIVO',
        };

        // Sin actor (nadie inició sesión); si la cuenta existe, queda como sujeto.
        $this->audit->record(null, 'auth.login_failed', $user, [
            'email_hash' => self::emailHash($email),
            'reason' => $reason,
        ]);
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'login-email:'.mb_strtolower(trim($email)), DocumentHasher::key());
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
