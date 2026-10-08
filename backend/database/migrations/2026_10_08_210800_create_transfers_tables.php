<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traslados entre bodegas (RN-07, RN-08).
 *
 * - transfers: cabecera con estado y quién/cuándo de cada transición.
 * - transfer_items: producto y cantidad solicitada.
 * - transfer_item_lots: lotes despachados (FEFO, puede ser multi-lote) y
 *   cantidad recibida de cada uno. Se crean al despachar.
 * - transfer_discrepancies: lo despachado y no recibido, pendiente de resolver.
 *
 * Las transiciones válidas viven en TransferStateMachine (Fase 2); aquí la BD
 * garantiza estados válidos y que cada estado tenga sus actores registrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('BORRADOR');
            $table->text('notes')->nullable();
            // Quien crea y solicita el traslado.
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('origin_warehouse_id');
            $table->index('destination_warehouse_id');
        });

        $checks = [
            'transfers_status_check' => "status IN ('BORRADOR', 'SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'ANULADO')",
            'transfers_distinct_warehouses' => 'origin_warehouse_id <> destination_warehouse_id',
            // RN-08: segregación de funciones.
            'transfers_approver_differs' => 'approved_by IS NULL OR approved_by <> requested_by',
            // Coherencia estado <-> actores (si el estado lo implica, quién y cuándo existen).
            'transfers_requested_complete' => "status NOT IN ('SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL') OR requested_at IS NOT NULL",
            'transfers_approved_complete' => "status NOT IN ('APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)",
            'transfers_dispatched_complete' => "status NOT IN ('EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL') OR (dispatched_by IS NOT NULL AND dispatched_at IS NOT NULL)",
            'transfers_received_complete' => "status NOT IN ('RECIBIDO', 'RECIBIDO_PARCIAL') OR (received_by IS NOT NULL AND received_at IS NOT NULL)",
            // RN-07: ANULADO solo antes de despachar (lo despachado ya movió stock).
            'transfers_cancel_before_dispatch' => "status <> 'ANULADO' OR (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND dispatched_at IS NULL)",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE transfers ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // Solo un regente aprueba traslados.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION transfers_check_roles() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM assert_user_role(NEW.approved_by, 'regente_farmacia', 'Quien aprueba el traslado');
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER transfers_check_roles
            BEFORE INSERT OR UPDATE OF approved_by ON transfers
            FOR EACH ROW EXECUTE FUNCTION transfers_check_roles();
            SQL);

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_requested');
            $table->timestamps();

            $table->unique(['transfer_id', 'product_id']);
            $table->unique(['id', 'product_id']);
        });

        DB::statement('ALTER TABLE transfer_items ADD CONSTRAINT transfer_items_quantity_requested_positive CHECK (quantity_requested > 0)');

        Schema::create('transfer_item_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_item_id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity_dispatched');
            // NULL mientras está en tránsito; al recibir se registra (0..despachado).
            $table->integer('quantity_received')->nullable();
            $table->timestamps();

            $table->foreign(['transfer_item_id', 'product_id'])->references(['id', 'product_id'])->on('transfer_items')->restrictOnDelete();
            $table->foreign(['lot_id', 'product_id'])->references(['id', 'product_id'])->on('lots')->restrictOnDelete();
            $table->unique(['transfer_item_id', 'lot_id']);
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE transfer_item_lots ADD CONSTRAINT transfer_item_lots_dispatched_positive CHECK (quantity_dispatched > 0)');
        DB::statement('ALTER TABLE transfer_item_lots ADD CONSTRAINT transfer_item_lots_received_range CHECK (quantity_received IS NULL OR (quantity_received >= 0 AND quantity_received <= quantity_dispatched))');

        Schema::create('transfer_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_item_lot_id')->unique()->constrained()->restrictOnDelete();
            $table->integer('quantity_missing');
            $table->string('status', 20)->default('PENDIENTE');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        DB::statement('ALTER TABLE transfer_discrepancies ADD CONSTRAINT transfer_discrepancies_missing_positive CHECK (quantity_missing > 0)');
        DB::statement("ALTER TABLE transfer_discrepancies ADD CONSTRAINT transfer_discrepancies_status_check CHECK (status IN ('PENDIENTE', 'RESUELTA'))");
        DB::statement("ALTER TABLE transfer_discrepancies ADD CONSTRAINT transfer_discrepancies_resolution_complete CHECK (status <> 'RESUELTA' OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution IS NOT NULL AND btrim(resolution) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_discrepancies');
        Schema::dropIfExists('transfer_item_lots');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
        DB::unprepared('DROP FUNCTION IF EXISTS transfers_check_roles()');
    }
};
