<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RN-10 (Fase 4): la bitácora de accesos a pacientes registra además si el
 * usuario vio los datos enmascarados (auditor) o completos.
 *
 * La tabla es append-only (trigger): agregar una columna con valor por
 * defecto no reescribe filas vía UPDATE, así que el trigger no interfiere.
 * La acción queda limitada a las lecturas que la API registra hoy; una
 * acción nueva exige una migración (y que alguien piense si es una lectura).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE patient_access_logs ADD COLUMN masked boolean NOT NULL DEFAULT false');
        DB::statement(
            'ALTER TABLE patient_access_logs ADD CONSTRAINT patient_access_logs_action_check '
            ."CHECK (action IN ('patient.search', 'patient.view', 'patient.prescriptions'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_access_logs DROP CONSTRAINT IF EXISTS patient_access_logs_action_check');
        DB::statement('ALTER TABLE patient_access_logs DROP COLUMN IF EXISTS masked');
    }
};
