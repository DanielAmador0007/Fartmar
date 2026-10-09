<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defensas en BD para la Fase 3 (traslados).
 *
 * Por qué hace falta esta migración (el esquema de la Fase 1 no lo cubría):
 *
 * 1. transfer_discrepancies.resolution_action: la discrepancia se resuelve
 *    con una ACCIÓN (REINTEGRAR_ORIGEN o PERDIDA) además del texto. Sin
 *    columna, la acción solo quedaría dentro del texto libre y no se podría
 *    filtrar ni exigir. RESUELTA exige acción; PENDIENTE no puede tenerla.
 * 2. Trigger: quien resuelve una discrepancia es regente_farmacia (riesgo
 *    abierto en PROGRESO.md: la BD no lo exigía).
 * 3. CHECK: un traslado ANULADO exige motivo no vacío (RN-07; hasta ahora
 *    solo exigía quién y cuándo).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transfer_discrepancies ADD COLUMN resolution_action varchar(20) NULL');

        DB::statement("ALTER TABLE transfer_discrepancies ADD CONSTRAINT transfer_discrepancies_resolution_action_check CHECK (resolution_action IS NULL OR resolution_action IN ('REINTEGRAR_ORIGEN', 'PERDIDA'))");
        DB::statement("ALTER TABLE transfer_discrepancies ADD CONSTRAINT transfer_discrepancies_action_matches_status CHECK ((status = 'RESUELTA') = (resolution_action IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfer_discrepancies_check_roles() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM assert_user_role(NEW.resolved_by, 'regente_farmacia', 'Quien resuelve la discrepancia');
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfer_discrepancies_check_roles
            BEFORE INSERT OR UPDATE OF resolved_by ON transfer_discrepancies
            FOR EACH ROW EXECUTE FUNCTION transfer_discrepancies_check_roles();
            SQL);

        DB::statement("ALTER TABLE transfers ADD CONSTRAINT transfers_cancel_reason_required CHECK (status <> 'ANULADO' OR (cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> ''))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfers DROP CONSTRAINT IF EXISTS transfers_cancel_reason_required');
        DB::unprepared('DROP TRIGGER IF EXISTS transfer_discrepancies_check_roles ON transfer_discrepancies');
        DB::unprepared('DROP FUNCTION IF EXISTS transfer_discrepancies_check_roles()');
        DB::statement('ALTER TABLE transfer_discrepancies DROP CONSTRAINT IF EXISTS transfer_discrepancies_action_matches_status');
        DB::statement('ALTER TABLE transfer_discrepancies DROP CONSTRAINT IF EXISTS transfer_discrepancies_resolution_action_check');
        DB::statement('ALTER TABLE transfer_discrepancies DROP COLUMN IF EXISTS resolution_action');
    }
};
