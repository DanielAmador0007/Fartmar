<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RN-10 (revisión Fase 4, M3): ver una dispensación expone el paciente
 * (patient_id) y lo que se le entregó, así que también es un acceso que se
 * registra: acción patient.dispensation_view.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE patient_access_logs DROP CONSTRAINT patient_access_logs_action_check');
        DB::statement(
            'ALTER TABLE patient_access_logs ADD CONSTRAINT patient_access_logs_action_check '
            ."CHECK (action IN ('patient.search', 'patient.view', 'patient.prescriptions', 'patient.dispensation_view'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_access_logs DROP CONSTRAINT patient_access_logs_action_check');
        DB::statement(
            'ALTER TABLE patient_access_logs ADD CONSTRAINT patient_access_logs_action_check '
            ."CHECK (action IN ('patient.search', 'patient.view', 'patient.prescriptions'))"
        );
    }
};
