<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige transfers_check_transition (A1): escribir el MISMO estado ya no se
 * acepta como "sin cambio".
 *
 * Problema (lo detectó la prueba de concurrencia "control: SIN FOR UPDATE la
 * doble recepción..."): la función dejaba pasar NEW.status = OLD.status. Si
 * dos recepciones del mismo traslado corrieran sin el FOR UPDATE de
 * TransferService (un bug futuro o un cambio en el código), ambas leían
 * EN_TRANSITO y, con las mismas cantidades, ninguna defensa de la BD las
 * frenaba: quantity_received se reescribía con el mismo valor (permitido),
 * no había discrepancias que chocaran con su UNIQUE y la segunda escribía
 * RECIBIDO sobre RECIBIDO. Resultado: ENTRADA_TRASLADO duplicada y el
 * destino con el doble de unidades.
 *
 * Ahora el trigger acepta exactamente la tabla de TransferStateMachine, que
 * ya trataba "quedarse en el mismo estado" como transición inválida (p. ej.
 * aprobar dos veces). El trigger es BEFORE UPDATE OF status: solo se dispara
 * si el UPDATE incluye la columna status. Eloquent solo envía columnas que
 * cambiaron, así que actualizar notas o updated_at sigue funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfers_check_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                -- Mismo mapa que App\Domain\Transfers\TransferStateMachine.
                -- Escribir el mismo estado (RECIBIDO -> RECIBIDO) NO es válido.
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
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfers_check_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

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
            SQL);
    }
};
