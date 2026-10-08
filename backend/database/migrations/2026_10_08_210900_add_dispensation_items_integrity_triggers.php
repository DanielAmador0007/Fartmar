<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierra dos huecos de integridad de las dispensaciones (hallados por QA):
 *
 * 1. RN-05: el CHECK dispensations_controlled_needs_authorization depende de
 *    `requires_authorization`, que fija la aplicación. Si el servicio lo dejara
 *    en false para un medicamento controlado, la dispensación quedaría
 *    COMPLETADA sin regente. Ahora la BD deriva la exigencia del catálogo: una
 *    línea de producto `is_controlled` solo puede ir en una dispensación con
 *    requires_authorization = true, y esa marca no se puede apagar después.
 *
 * 2. RN-04: dispensation_items apuntaba a una línea de prescripción
 *    cualquiera; nada exigía que fuera de la prescripción de la cabecera. Se
 *    podía descontar la prescripción de OTRO paciente. Ahora la línea debe
 *    pertenecer a dispensations.prescription_id (y la cabecera no puede
 *    cambiar de prescripción dejando líneas ajenas).
 *
 * Se usan triggers porque son reglas entre tablas distintas (un CHECK solo ve
 * la fila). Si la cabecera o la línea no existen, no se valida aquí: lo
 * rechaza la FK correspondiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dispensation_items_check_integrity() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_dispensation_prescription bigint;
                v_requires_authorization boolean;
                v_item_prescription bigint;
                v_is_controlled boolean;
            BEGIN
                SELECT prescription_id, requires_authorization
                  INTO v_dispensation_prescription, v_requires_authorization
                  FROM dispensations WHERE id = NEW.dispensation_id;

                SELECT prescription_id INTO v_item_prescription
                  FROM prescription_items WHERE id = NEW.prescription_item_id;

                IF v_dispensation_prescription IS NOT NULL AND v_item_prescription IS NOT NULL
                   AND v_item_prescription <> v_dispensation_prescription THEN
                    RAISE EXCEPTION 'La línea de prescripción % no pertenece a la prescripción % de la dispensación %',
                        NEW.prescription_item_id, v_dispensation_prescription, NEW.dispensation_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT is_controlled INTO v_is_controlled FROM products WHERE id = NEW.product_id;

                IF v_is_controlled AND v_requires_authorization IS FALSE THEN
                    RAISE EXCEPTION 'RN-05: la dispensación % incluye un medicamento de control especial (producto %) y debe exigir autorización',
                        NEW.dispensation_id, NEW.product_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER dispensation_items_check_integrity
            BEFORE INSERT OR UPDATE OF dispensation_id, prescription_item_id, product_id ON dispensation_items
            FOR EACH ROW EXECUTE FUNCTION dispensation_items_check_integrity();

            CREATE OR REPLACE FUNCTION dispensations_check_items_consistency() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT NEW.requires_authorization AND EXISTS (
                    SELECT 1 FROM dispensation_items di
                    JOIN products p ON p.id = di.product_id
                    WHERE di.dispensation_id = NEW.id AND p.is_controlled
                ) THEN
                    RAISE EXCEPTION 'RN-05: la dispensación % incluye un medicamento de control especial y debe exigir autorización', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM dispensation_items di
                    JOIN prescription_items pi ON pi.id = di.prescription_item_id
                    WHERE di.dispensation_id = NEW.id AND pi.prescription_id <> NEW.prescription_id
                ) THEN
                    RAISE EXCEPTION 'La dispensación % tiene líneas que no pertenecen a la prescripción %', NEW.id, NEW.prescription_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER dispensations_check_items_consistency
            BEFORE UPDATE OF requires_authorization, prescription_id ON dispensations
            FOR EACH ROW EXECUTE FUNCTION dispensations_check_items_consistency();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dispensations_check_items_consistency ON dispensations');
        DB::unprepared('DROP FUNCTION IF EXISTS dispensations_check_items_consistency()');
        DB::unprepared('DROP TRIGGER IF EXISTS dispensation_items_check_integrity ON dispensation_items');
        DB::unprepared('DROP FUNCTION IF EXISTS dispensation_items_check_integrity()');
    }
};
