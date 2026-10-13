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
 * - números que parecen documento o teléfono: 9+ dígitos (también
 *   "300 123 4567" o "1.020.304.050"), o 6+ dígitos después de CC, NIT,
 *   teléfono, celular... Un lote o una fecha de 8 dígitos (20241231) y las
 *   cantidades con puntos de miles no se bloquean;
 *
 * Es higiene, no la defensa principal: la seguridad real está en las
 * capacidades (herramientas de solo lectura, Policies y ningún dato de
 * pacientes alcanzable desde las herramientas).
 * - texto con forma de instrucción de sistema ("SYSTEM:") o las etiquetas
 *   <tool_result>;
 * - frases del system prompt (intento de revelarlo).
 */
final class OutputGuard
{
    private const PATTERNS = [
        '/cedula|documento de identidad|numero de documento|historia clinica/',
        '/(?<![a-z0-9-])\d{9,}/',
        '/\b(cc|ce|ti|nit|cedula|documento|doc|telefono|tel|celular|cel|movil)\b\D{0,15}\d{6,}/',
        '/\bsystem\s*:|<\/?\s*tool_result/',
    ];

    public static function isSafe(string $answer): bool
    {
        $text = Text::normalize($answer);
        $digits = Text::joinDigitGroups($text);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1 || preg_match($pattern, $digits) === 1) {
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
