<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El saldo del kardex es el saldo real (revisión de la Fase 3, M3).
 *
 * StockService actualiza stocks.quantity (con la fila bloqueada FOR UPDATE)
 * y JUSTO DESPUÉS inserta el movimiento con balance_after = saldo nuevo,
 * en la misma transacción. Este trigger lo exige en la BD: un movimiento
 * cuyo balance_after no coincide con la existencia actual de
 * (warehouse_id, lot_id) se rechaza. Así un INSERT manual o un bug que
 * escriba el kardex sin mover el stock (o con un saldo inventado) falla.
 *
 * AFTER INSERT para que los CHECK y las FK del movimiento respondan primero
 * con su propio nombre (las FK también son triggers AFTER y se ejecutan
 * antes: PostgreSQL ordena por nombre y "RI_..." va antes que "kardex_...").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kardex_movements_check_balance() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_quantity integer;
            BEGIN
                SELECT quantity INTO v_quantity
                FROM stocks
                WHERE warehouse_id = NEW.warehouse_id AND lot_id = NEW.lot_id;

                IF NOT FOUND THEN
                    RETURN NULL; -- Lo rechaza la FK a stocks.
                END IF;

                IF NEW.balance_after <> v_quantity THEN
                    RAISE EXCEPTION 'kardex_movements_check_balance: balance_after % no coincide con la existencia actual % (bodega %, lote %)',
                        NEW.balance_after, v_quantity, NEW.warehouse_id, NEW.lot_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER kardex_movements_check_balance
            AFTER INSERT ON kardex_movements
            FOR EACH ROW EXECUTE FUNCTION kardex_movements_check_balance();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS kardex_movements_check_balance ON kardex_movements;
            DROP FUNCTION IF EXISTS kardex_movements_check_balance();
            SQL);
    }
};
