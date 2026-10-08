<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispensaciones (RN-02, RN-04, RN-05, RN-09).
 *
 * - dispensations: cabecera; idempotency_key UNIQUE + request_hash (RN-09).
 * - dispensation_items: qué línea de la prescripción se dispensa y cuánto.
 * - dispensation_item_lots: de qué lotes salió (FEFO, puede ser multi-lote).
 *   Una dispensación de control especial queda PENDIENTE_AUTORIZACION con sus
 *   items pero sin lotes; los lotes se asignan al autorizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('prescription_id');
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            // true si algún producto es de control especial (RN-05).
            $table->boolean('requires_authorization')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('idempotency_key', 100)->unique();
            // SHA-256 del payload normalizado: misma clave + otro payload => 409.
            $table->char('request_hash', 64);
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            // La prescripción debe pertenecer al mismo paciente.
            $table->foreign(['prescription_id', 'patient_id'])->references(['id', 'patient_id'])->on('prescriptions')->restrictOnDelete();
            $table->index(['status', 'created_at']);
            $table->index('prescription_id');
        });

        DB::statement("ALTER TABLE dispensations ADD CONSTRAINT dispensations_status_check CHECK (status IN ('PENDIENTE_AUTORIZACION', 'COMPLETADA', 'RECHAZADA'))");
        // RN-05: quien autoriza (o rechaza) no puede ser quien creó la dispensación.
        DB::statement('ALTER TABLE dispensations ADD CONSTRAINT dispensations_authorizer_differs CHECK (authorized_by IS NULL OR authorized_by <> created_by)');
        DB::statement('ALTER TABLE dispensations ADD CONSTRAINT dispensations_rejecter_differs CHECK (rejected_by IS NULL OR rejected_by <> created_by)');
        // RN-05: una dispensación de control especial solo puede quedar COMPLETADA si alguien la autorizó.
        DB::statement("ALTER TABLE dispensations ADD CONSTRAINT dispensations_controlled_needs_authorization CHECK (NOT requires_authorization OR status <> 'COMPLETADA' OR (authorized_by IS NOT NULL AND authorized_at IS NOT NULL))");
        // Un rechazo siempre deja quién, cuándo y por qué.
        DB::statement("ALTER TABLE dispensations ADD CONSTRAINT dispensations_rejection_complete CHECK (status <> 'RECHAZADA' OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND rejection_reason IS NOT NULL AND btrim(rejection_reason) <> ''))");
        DB::statement("ALTER TABLE dispensations ADD CONSTRAINT dispensations_idempotency_key_not_blank CHECK (btrim(idempotency_key) <> '')");

        // RN-05: quien autoriza o rechaza debe ser regente_farmacia.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dispensations_check_roles() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM assert_user_role(NEW.authorized_by, 'regente_farmacia', 'Quien autoriza la dispensación');
                PERFORM assert_user_role(NEW.rejected_by, 'regente_farmacia', 'Quien rechaza la dispensación');
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER dispensations_check_roles
            BEFORE INSERT OR UPDATE OF authorized_by, rejected_by ON dispensations
            FOR EACH ROW EXECUTE FUNCTION dispensations_check_roles();
            SQL);

        Schema::create('dispensation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispensation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_item_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity');
            $table->timestamps();

            // El producto debe ser el de la línea de prescripción.
            $table->foreign(['prescription_item_id', 'product_id'])->references(['id', 'product_id'])->on('prescription_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['dispensation_id', 'prescription_item_id']);
            $table->unique(['id', 'product_id']);
            $table->index('prescription_item_id');
        });

        DB::statement('ALTER TABLE dispensation_items ADD CONSTRAINT dispensation_items_quantity_positive CHECK (quantity > 0)');

        Schema::create('dispensation_item_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispensation_item_id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity');
            $table->timestamps();

            // Lote del mismo producto que la línea dispensada.
            $table->foreign(['dispensation_item_id', 'product_id'])->references(['id', 'product_id'])->on('dispensation_items')->restrictOnDelete();
            $table->foreign(['lot_id', 'product_id'])->references(['id', 'product_id'])->on('lots')->restrictOnDelete();
            $table->unique(['dispensation_item_id', 'lot_id']);
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE dispensation_item_lots ADD CONSTRAINT dispensation_item_lots_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensation_item_lots');
        Schema::dropIfExists('dispensation_items');
        Schema::dropIfExists('dispensations');
        DB::unprepared('DROP FUNCTION IF EXISTS dispensations_check_roles()');
    }
};
