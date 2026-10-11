<?php

namespace App\Domain\Patients;

/**
 * Enmascarado de datos del paciente para el rol auditor (RN-10).
 *
 * Funciones puras (sin BD ni usuario): quién ve enmascarado lo decide
 * PatientPolicy::viewUnmasked; aquí solo se transforma el dato.
 *
 *   documento  "1032456789"  => "******6789"  (siempre 6 asteriscos: no revela el largo)
 *   nombre     "Juan Pablo"  => "J*** P***"
 *   teléfono y fecha de nacimiento => null (ocultos)
 */
final class PatientMasker
{
    private const DOCUMENT_PREFIX = '******';

    private const VISIBLE_DIGITS = 4;

    public static function document(string $documentNumber): string
    {
        $documentNumber = trim($documentNumber);

        // Con 4 caracteres o menos, mostrar "los últimos 4" sería mostrarlo todo.
        if (mb_strlen($documentNumber) <= self::VISIBLE_DIGITS) {
            return self::DOCUMENT_PREFIX;
        }

        return self::DOCUMENT_PREFIX.mb_substr($documentNumber, -self::VISIBLE_DIGITS);
    }

    /**
     * Primera letra de cada palabra + "***".
     */
    public static function name(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)).'***',
            $words,
        ));
    }
}
