<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Segregación de funciones en discrepancias (revisión de la Fase 3, M5).
 *
 * Quien RECIBIÓ el traslado (y declaró cuánto llegó) no puede resolver la
 * discrepancia que su propia recepción generó: si pudiera, un mismo regente
 * declararía un faltante y luego lo "reintegraría" o lo daría por perdido
 * sin que nadie más lo revise. Es la misma idea de RN-08 (solicitar ≠ aprobar).
 *
 * Un CHECK no puede mirar otra tabla (received_by está en transfers), por eso
 * es un trigger. TransferService lo valida antes con un 403
 * SEGREGACION_FUNCIONES; esto es la segunda defensa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfer_discrepancies_check_resolver() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_received_by bigint;
            BEGIN
                IF NEW.resolved_by IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT t.received_by INTO v_received_by
                FROM transfer_item_lots til
                JOIN transfer_items ti ON ti.id = til.transfer_item_id
                JOIN transfers t ON t.id = ti.transfer_id
                WHERE til.id = NEW.transfer_item_lot_id;

                IF v_received_by IS NOT NULL AND v_received_by = NEW.resolved_by THEN
                    RAISE EXCEPTION 'transfer_discrepancies_check_resolver: quien recibió el traslado (usuario %) no puede resolver su discrepancia', NEW.resolved_by
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfer_discrepancies_check_resolver
            BEFORE INSERT OR UPDATE OF resolved_by ON transfer_discrepancies
            FOR EACH ROW EXECUTE FUNCTION transfer_discrepancies_check_resolver();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS transfer_discrepancies_check_resolver ON transfer_discrepancies;
            DROP FUNCTION IF EXISTS transfer_discrepancies_check_resolver();
            SQL);
    }
};
