<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Funciones PL/pgSQL reutilizadas por los triggers de otras tablas.
 *
 * 1. forbid_append_only_mutation(): para tablas de solo inserción
 *    (kardex_movements RN-06, patient_access_logs y audit_logs RN-10).
 *    Cualquier UPDATE o DELETE lanza excepción, venga de Eloquent, de SQL
 *    manual o de otra aplicación conectada a la BD.
 *    No bloquea TRUNCATE ni DROP (requieren ser dueño de la tabla); en
 *    producción la app debe conectarse con un usuario que no sea el dueño.
 *
 * 2. assert_user_role(): verifica que un usuario tenga cierto rol
 *    (p. ej. quien autoriza un controlado o aprueba un traslado es regente).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_append_only_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La tabla % es de solo inserción: % no permitido', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

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
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS assert_user_role(bigint, text, text)');
        DB::unprepared('DROP FUNCTION IF EXISTS forbid_append_only_mutation()');
    }
};
