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

    /**
     * Para buscar documentos y teléfonos escritos con separadores: quita las
     * fechas (2026-11-01, 01/11/2026) y une los grupos de dígitos separados
     * por espacio, punto o guion ("300 123 4567" -> "3001234567",
     * "1.020.304.050" -> "1020304050"). Recibe texto ya normalizado.
     */
    public static function joinDigitGroups(string $normalized): string
    {
        $withoutDates = (string) preg_replace('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', ' ', $normalized);

        return (string) preg_replace('/(?<=\d)[ .\-](?=\d)/', '', $withoutDates);
    }
}
