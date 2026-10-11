<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La BD valida el PASO, no solo el estado final (revisión de la Fase 3, A1).
 *
 * Hasta ahora los CHECK de transfers solo miraban la fila resultante: con un
 * UPDATE directo se podía pasar de BORRADOR a RECIBIDO (si se llenaban los
 * actores), o "reabrir" un traslado RECIBIDO. TransferStateMachine lo impide
 * en la aplicación; estos triggers son la segunda defensa.
 *
 * 1. transfers_check_transition: tabla de pares (from, to) permitidos, la
 *    MISMA que TransferStateMachine::TRANSITIONS. Si el estado no cambia se
 *    permite (actualizar notas o updated_at no es una transición).
 * 2. transfer_discrepancies_freeze_resolved: una discrepancia RESUELTA no
 *    vuelve a PENDIENTE ni cambia acción, texto, quién o cuándo.
 * 3. transfer_items_only_in_draft: las líneas (producto y cantidad pedida)
 *    solo se insertan, cambian o borran mientras el traslado está en
 *    BORRADOR. Lo que se aprueba es exactamente lo que se solicitó.
 *
 * Errores con ERRCODE check_violation (23514) para que la app los trate como
 * violación de integridad. TRUNCATE no dispara triggers de fila (pruebas).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfers_check_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

                -- Mismo mapa que App\Domain\Transfers\TransferStateMachine.
                IF (OLD.status, NEW.status) IN (
                    ('BORRADOR', 'SOLICITADO'),
                    ('BORRADOR', 'ANULADO'),
                    ('SOLICITADO', 'APROBADO'),
                    ('SOLICITADO', 'ANULADO'),
                    ('APROBADO', 'EN_TRANSITO'),
                    ('APROBADO', 'ANULADO'),
                    ('EN_TRANSITO', 'RECIBIDO'),
                    ('EN_TRANSITO', 'RECIBIDO_PARCIAL')
                ) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'transfers_check_transition: transición de traslado no permitida de % a % (traslado %)',
                    OLD.status, NEW.status, OLD.id
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER transfers_check_transition
            BEFORE UPDATE OF status ON transfers
            FOR EACH ROW EXECUTE FUNCTION transfers_check_transition();

            CREATE OR REPLACE FUNCTION transfer_discrepancies_freeze_resolved() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'RESUELTA' AND (
                    NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.resolution_action IS DISTINCT FROM OLD.resolution_action
                    OR NEW.resolution IS DISTINCT FROM OLD.resolution
                    OR NEW.resolved_by IS DISTINCT FROM OLD.resolved_by
                    OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
                    OR NEW.quantity_missing IS DISTINCT FROM OLD.quantity_missing
                    OR NEW.transfer_item_lot_id IS DISTINCT FROM OLD.transfer_item_lot_id
                ) THEN
                    RAISE EXCEPTION 'transfer_discrepancies_freeze_resolved: la discrepancia % ya está resuelta y no se puede modificar', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfer_discrepancies_freeze_resolved
            BEFORE UPDATE ON transfer_discrepancies
            FOR EACH ROW EXECUTE FUNCTION transfer_discrepancies_freeze_resolved();

            CREATE OR REPLACE FUNCTION transfer_items_only_in_draft() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_status text;
            BEGIN
                -- La fila "vieja" (UPDATE/DELETE) y la "nueva" (INSERT/UPDATE)
                -- deben pertenecer a un traslado en BORRADOR.
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT status INTO v_status FROM transfers WHERE id = OLD.transfer_id;
                    IF v_status IS DISTINCT FROM 'BORRADOR' THEN
                        RAISE EXCEPTION 'transfer_items_only_in_draft: las líneas del traslado % solo se modifican en BORRADOR (estado %)', OLD.transfer_id, v_status
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    SELECT status INTO v_status FROM transfers WHERE id = NEW.transfer_id;
                    -- Traslado inexistente: lo rechaza la FK.
                    IF FOUND AND v_status <> 'BORRADOR' THEN
                        RAISE EXCEPTION 'transfer_items_only_in_draft: las líneas del traslado % solo se modifican en BORRADOR (estado %)', NEW.transfer_id, v_status
                            USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER transfer_items_only_in_draft
            BEFORE INSERT OR UPDATE OR DELETE ON transfer_items
            FOR EACH ROW EXECUTE FUNCTION transfer_items_only_in_draft();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS transfer_items_only_in_draft ON transfer_items;
            DROP FUNCTION IF EXISTS transfer_items_only_in_draft();
            DROP TRIGGER IF EXISTS transfer_discrepancies_freeze_resolved ON transfer_discrepancies;
            DROP FUNCTION IF EXISTS transfer_discrepancies_freeze_resolved();
            DROP TRIGGER IF EXISTS transfers_check_transition ON transfers;
            DROP FUNCTION IF EXISTS transfers_check_transition();
            SQL);
    }
};
