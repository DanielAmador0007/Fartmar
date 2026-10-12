<?php

namespace App\Domain\Assistant;

/**
 * Filtro de ENTRADA (antes de llamar al modelo). Reglas simples sobre el
 * texto normalizado; si una coincide, el servidor responde con un rechazo
 * fijo y la pregunta NUNCA se envía al modelo. Eso también evita mandar a
 * un proveedor externo datos de pacientes que el usuario haya escrito.
 *
 * - sql:       pide SQL libre.
 * - injection: intenta cambiar las instrucciones o ver el system prompt.
 * - patient:   pregunta por pacientes, documentos, prescripciones o dispensaciones.
 * - write:     pide modificar algo (el asistente es de solo lectura).
 *
 * Es una primera barrera, no la única: el system prompt, la lista blanca de
 * herramientas de lectura y el filtro de salida siguen activos.
 */
final class QuestionGuard
{
    /** @var array<string, string> motivo => expresión sobre el texto normalizado */
    private const RULES = [
        'sql' => '/\bsql\b|\bselect\b.+\bfrom\b|\b(drop|truncate|alter)\s+table\b|\bdelete\s+from\b|\binsert\s+into\b|\bupdate\s+\w+\s+set\b/',
        'injection' => '/\b(ignora|ignorar|ignore|olvida|olvidar|omite|omitir)\b.{0,40}\b(instrucciones|reglas|indicaciones|restricciones)\b'
            .'|system prompt|prompt del sistema|instrucciones del sistema|tus instrucciones|\bjailbreak\b|modo (desarrollador|dios|administrador)/',
        'patient' => '/paciente|cedula|documento de identidad|numero de documento|historia clinica|diagnostic|prescripci|formula medica|\breceta|dispensacion|a quien se (le )?(dispenso|entrego)|telefono|direccion|fecha de nacimiento/',
        'write' => '/\b(borra|borrar|borren|elimina|eliminar|modifica|modificar|actualiza|actualizar|crea|crear|inserta|insertar|ajusta|ajustar|anula|anular|despacha|despachar|aprueba|vacia|vaciar|cambia|cambiar)\b|da(r)? de baja/',
    ];

    /**
     * Motivo del rechazo o null si la pregunta puede ir al modelo.
     */
    public static function reasonFor(string $question): ?string
    {
        $text = Text::normalize($question);

        foreach (self::RULES as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $reason;
            }
        }

        return null;
    }

    public static function refusalFor(string $reason): string
    {
        return $reason === 'patient' ? AssistantMessages::PATIENT_REFUSAL : AssistantMessages::ACTION_REFUSAL;
    }
}
