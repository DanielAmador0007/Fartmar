<?php

namespace App\Domain\Assistant;

/**
 * Filtro de SALIDA: última defensa si el modelo obedeció una inyección
 * (por ejemplo, desde las observaciones de un traslado). Si la respuesta
 * contiene algo que el asistente nunca debería decir, el servidor la
 * reemplaza por AssistantMessages::OUTPUT_BLOCKED.
 *
 * Bloquea:
 * - menciones de documentos de identidad o historia clínica;
 * - secuencias de 7 o más dígitos (parecen documentos o teléfonos; las
 *   cantidades, fechas y lotes del inventario no las tienen);
 * - texto con forma de instrucción de sistema ("SYSTEM:") o las etiquetas
 *   <tool_result>;
 * - frases del system prompt (intento de revelarlo).
 */
final class OutputGuard
{
    private const PATTERNS = [
        '/cedula|documento de identidad|numero de documento|historia clinica/',
        '/\d{7,}/',
        '/\bsystem\s*:|<\/?\s*tool_result/',
    ];

    public static function isSafe(string $answer): bool
    {
        $text = Text::normalize($answer);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        foreach (SystemPrompt::CANARIES as $canary) {
            if (str_contains($text, Text::normalize($canary))) {
                return false;
            }
        }

        return true;
    }
}
