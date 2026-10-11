<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Más reglas de traslados defendidas en la BD (revisión de la Fase 3, M2).
 *
 * 1. transfer_item_lots_check_lot (BEFORE INSERT): RN-01 en BD, un lote
 *    vencido nunca se despacha. "Hoy" es la fecha de Bogotá (S-13), igual
 *    que BusinessDate en la aplicación: vencido = expires_at < hoy.
 * 2. transfer_item_lots_freeze (BEFORE UPDATE): quantity_received solo pasa
 *    de NULL a un valor (la recepción es una sola, S-55) y lo despachado
 *    (lote, producto, línea, cantidad) no cambia después de insertarse.
 * 3. transfer_discrepancies_check_missing (AFTER INSERT/UPDATE): lo faltante
 *    debe ser exactamente despachado - recibido del lote, y el lote debe
 *    estar recibido. AFTER para que los CHECK de la fila (missing > 0, etc.)
 *    respondan primero con su propio nombre.
 * 4. assert_user_role() exige además is_active: un usuario desactivado no
 *    aprueba, autoriza ni resuelve nada aunque la app tuviera un bug (S-37).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfer_item_lots_check_lot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_expires_at date;
                v_today date := (now() AT TIME ZONE 'America/Bogota')::date;
            BEGIN
                SELECT expires_at INTO v_expires_at FROM lots WHERE id = NEW.lot_id;

                -- Lote inexistente: lo rechaza la FK.
                IF FOUND AND v_expires_at < v_today THEN
                    RAISE EXCEPTION 'transfer_item_lots_check_lot: el lote % venció el % y no se puede trasladar (RN-01)', NEW.lot_id, v_expires_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfer_item_lots_check_lot
            BEFORE INSERT ON transfer_item_lots
            FOR EACH ROW EXECUTE FUNCTION transfer_item_lots_check_lot();

            CREATE OR REPLACE FUNCTION transfer_item_lots_freeze() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.transfer_item_id IS DISTINCT FROM OLD.transfer_item_id
                    OR NEW.lot_id IS DISTINCT FROM OLD.lot_id
                    OR NEW.product_id IS DISTINCT FROM OLD.product_id
                    OR NEW.quantity_dispatched IS DISTINCT FROM OLD.quantity_dispatched THEN
                    RAISE EXCEPTION 'transfer_item_lots_freeze: lo despachado en el lote de traslado % no se puede modificar', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.quantity_received IS NOT NULL
                    AND NEW.quantity_received IS DISTINCT FROM OLD.quantity_received THEN
                    RAISE EXCEPTION 'transfer_item_lots_freeze: la cantidad recibida del lote de traslado % ya se registró y no se puede cambiar', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfer_item_lots_freeze
            BEFORE UPDATE ON transfer_item_lots
            FOR EACH ROW EXECUTE FUNCTION transfer_item_lots_freeze();

            CREATE OR REPLACE FUNCTION transfer_discrepancies_check_missing() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_dispatched integer;
                v_received integer;
            BEGIN
                SELECT quantity_dispatched, quantity_received INTO v_dispatched, v_received
                FROM transfer_item_lots WHERE id = NEW.transfer_item_lot_id;

                IF NOT FOUND THEN
                    RETURN NULL; -- Lo rechaza la FK.
                END IF;

                IF v_received IS NULL THEN
                    RAISE EXCEPTION 'transfer_discrepancies_check_missing: el lote de traslado % aún no se ha recibido', NEW.transfer_item_lot_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.quantity_missing <> v_dispatched - v_received THEN
                    RAISE EXCEPTION 'transfer_discrepancies_check_missing: faltante % no coincide con despachado % - recibido % (lote de traslado %)',
                        NEW.quantity_missing, v_dispatched, v_received, NEW.transfer_item_lot_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER transfer_discrepancies_check_missing
            AFTER INSERT OR UPDATE OF quantity_missing, transfer_item_lot_id ON transfer_discrepancies
            FOR EACH ROW EXECUTE FUNCTION transfer_discrepancies_check_missing();

            CREATE OR REPLACE FUNCTION assert_user_role(p_user_id bigint, p_role text, p_label text) RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                IF p_user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM users WHERE id = p_user_id AND role = p_role AND is_active
                ) THEN
                    RAISE EXCEPTION '% (usuario %) debe tener rol % y estar activo', p_label, p_user_id, p_role
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_user_role(p_user_id bigint, p_role text, p_label text) RETURNS void
            LANGUAGE plpgsql AS $$
            BEGIN
                IF p_user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM users WHERE id = p_user_id AND role = p_role
                ) THEN
                    RAISE EXCEPTION '% (usuario %) debe tener rol %', p_label, p_user_id, p_role
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            DROP TRIGGER IF EXISTS transfer_discrepancies_check_missing ON transfer_discrepancies;
            DROP FUNCTION IF EXISTS transfer_discrepancies_check_missing();
            DROP TRIGGER IF EXISTS transfer_item_lots_freeze ON transfer_item_lots;
            DROP FUNCTION IF EXISTS transfer_item_lots_freeze();
            DROP TRIGGER IF EXISTS transfer_item_lots_check_lot ON transfer_item_lots;
            DROP FUNCTION IF EXISTS transfer_item_lots_check_lot();
            SQL);
    }
};
