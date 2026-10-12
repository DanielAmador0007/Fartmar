<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Str;

/**
 * Normaliza texto en español para comparar con reglas: minúsculas, sin
 * tildes ni ñ ("¿Cuántas unidades?" -> "cuantas unidades?") y espacios
 * simples.
 */
final class Text
{
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }
}
