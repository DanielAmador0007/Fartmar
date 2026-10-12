<?php

namespace App\Http\Middleware;

use App\Domain\Exceptions\PasswordChangeRequiredException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S-77: con una contraseña temporal (must_change_password) solo se puede
 * cambiarla, ver el propio usuario y cerrar sesión. Todo lo demás responde
 * 403 CAMBIO_CLAVE_REQUERIDO. Va después de auth:sanctum.
 *
 * Así quien conoce la clave temporal (el admin que la asignó) no puede
 * operar con ella como médico o regente sin dejar un user.password_changed
 * en la bitácora.
 */
final class EnsurePasswordIsChanged
{
    /** Rutas permitidas con contraseña temporal. */
    private const ALLOWED_ROUTES = ['auth.password', 'auth.me', 'auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            throw PasswordChangeRequiredException::make();
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
