<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RN-01 en BD también para dispensaciones (revisión final, M1).
 *
 * Hasta aquí solo transfer_item_lots rechazaba lotes vencidos en la BD; una
 * dispensación dependía únicamente de FefoAllocator. Ahora:
 *
 * 1. business_today(): "hoy" de negocio = fecha de Bogotá (S-13, S-65). Es el
 *    único lugar del SQL con la zona horaria; los triggers la llaman en vez de
 *    repetir `now() AT TIME ZONE 'America/Bogota'`. STABLE: dentro de una
 *    sentencia devuelve siempre lo mismo (now() es el inicio de la transacción).
 * 2. dispensation_item_lots_check_lot (BEFORE INSERT): un lote con
 *    expires_at < hoy no se dispensa. Mismo criterio que BusinessDate en la app
 *    y que el trigger de traslados: el lote que vence HOY todavía es válido.
 * 3. transfer_item_lots_check_lot se redefine para usar business_today() (mismo
 *    comportamiento, sin duplicar la zona horaria).
 *
 * Solo INSERT: las filas de dispensation_item_lots no se actualizan (la
 * dispensación es un hecho consumado) y un lote que vence después de
 * dispensado no invalida la entrega pasada.
 *
 * Permisos: el rol fartmar_app no necesita nada nuevo. Las funciones se
 * ejecutan con los permisos del invocador; EXECUTE está concedido a PUBLIC por
 * defecto y db:provision-app-role no lo revoca, y la función solo lee `lots`,
 * sobre la que fartmar_app ya tiene SELECT.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION business_today() RETURNS date
            LANGUAGE sql STABLE AS $$
                SELECT (now() AT TIME ZONE 'America/Bogota')::date
            $$;

            CREATE OR REPLACE FUNCTION dispensation_item_lots_check_lot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_expires_at date;
            BEGIN
                SELECT expires_at INTO v_expires_at FROM lots WHERE id = NEW.lot_id;

                -- Lote inexistente: lo rechaza la FK.
                IF FOUND AND v_expires_at < business_today() THEN
                    RAISE EXCEPTION 'dispensation_item_lots_check_lot: el lote % venció el % y no se puede dispensar (RN-01)', NEW.lot_id, v_expires_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER dispensation_item_lots_check_lot
            BEFORE INSERT ON dispensation_item_lots
            FOR EACH ROW EXECUTE FUNCTION dispensation_item_lots_check_lot();

            CREATE OR REPLACE FUNCTION transfer_item_lots_check_lot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_expires_at date;
            BEGIN
                SELECT expires_at INTO v_expires_at FROM lots WHERE id = NEW.lot_id;

                -- Lote inexistente: lo rechaza la FK.
                IF FOUND AND v_expires_at < business_today() THEN
                    RAISE EXCEPTION 'transfer_item_lots_check_lot: el lote % venció el % y no se puede trasladar (RN-01)', NEW.lot_id, v_expires_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);
    }

    public function down(): void
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

            DROP TRIGGER IF EXISTS dispensation_item_lots_check_lot ON dispensation_item_lots;
            DROP FUNCTION IF EXISTS dispensation_item_lots_check_lot();
            DROP FUNCTION IF EXISTS business_today();
            SQL);
    }
};
