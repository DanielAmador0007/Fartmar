<?php

namespace App\Domain\Patients;

use App\Models\Patient;

/**
 * Búsqueda EXACTA de pacientes por documento (S-17).
 *
 * El número está cifrado en la BD, así que nunca se busca con LIKE ni se
 * descifran todas las filas: se calcula el mismo HMAC que se guardó en
 * document_hash (índice UNIQUE) y se compara. Devuelve 0 o 1 paciente.
 */
final class PatientFinder
{
    public function findByDocument(string $documentType, string $documentNumber): ?Patient
    {
        return Patient::query()
            ->where('document_hash', DocumentHasher::hash($documentType, $documentNumber))
            ->first();
    }
}
