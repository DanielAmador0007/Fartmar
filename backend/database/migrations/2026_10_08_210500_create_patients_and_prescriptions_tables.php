<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pacientes (datos sensibles, RN-10) y prescripciones (RN-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 5);
            // Cifrado por Laravel (cast "encrypted"): no se puede buscar por aquí.
            $table->text('document_number');
            // HMAC-SHA256 de tipo + número: búsqueda exacta y unicidad sin descifrar.
            $table->char('document_hash', 64)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date');
            $table->text('phone')->nullable(); // cifrado
            $table->timestamps();
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_document_type_check CHECK (document_type IN ('CC', 'TI', 'CE', 'RC', 'PA'))");

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('prescriber_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->date('valid_until');
            $table->string('status', 20)->default('ACTIVA');
            $table->timestamps();

            // Destino de la FK compuesta de dispensations: la prescripción
            // debe ser del mismo paciente de la dispensación.
            $table->unique(['id', 'patient_id']);
            $table->index(['patient_id', 'status']);
        });

        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_status_check CHECK (status IN ('ACTIVA', 'COMPLETADA', 'ANULADA'))");
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_valid_range CHECK (valid_until >= issued_at::date)');

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_prescribed');
            // Acumulado de dispensaciones parciales (RN-04).
            $table->integer('quantity_dispensed')->default(0);
            $table->string('instructions', 255)->nullable();
            $table->timestamps();

            $table->unique(['prescription_id', 'product_id']);
            // Destino de la FK compuesta de dispensation_items.
            $table->unique(['id', 'product_id']);
        });

        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_prescribed_positive CHECK (quantity_prescribed > 0)');
        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_dispensed_non_negative CHECK (quantity_dispensed >= 0)');
        // RN-04: nunca dispensar más de lo prescrito, aunque haya parciales acumuladas.
        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_dispensed_le_prescribed CHECK (quantity_dispensed <= quantity_prescribed)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('patients');
    }
};
