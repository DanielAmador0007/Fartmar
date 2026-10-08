<?php

namespace App\Domain\Patients;

use RuntimeException;

/**
 * Calcula el hash de búsqueda del documento de un paciente.
 *
 * El número se guarda cifrado (no se puede buscar ni indexar), así que se
 * guarda además HMAC-SHA256(tipo:número) con una clave secreta del servidor.
 * Se usa HMAC y no un SHA-256 simple porque los documentos tienen poco
 * espacio de búsqueda: sin la clave, un atacante con la BD podría probar
 * todos los números posibles.
 */
final class DocumentHasher
{
    public static function hash(string $documentType, string $documentNumber): string
    {
        return hash_hmac('sha256', self::normalize($documentType, $documentNumber), self::key());
    }

    /**
     * "cc", " 1.000.123 " => "CC:1000123"
     */
    public static function normalize(string $documentType, string $documentNumber): string
    {
        $type = strtoupper(trim($documentType));
        $number = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $documentNumber));

        return $type.':'.$number;
    }

    private static function key(): string
    {
        $key = config('fartmar.patient_hash_key') ?: config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Falta PATIENT_HASH_KEY o APP_KEY para calcular el hash de documentos.');
        }

        return $key;
    }
}
