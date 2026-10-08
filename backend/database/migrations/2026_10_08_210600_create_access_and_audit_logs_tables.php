<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácoras append-only (RN-10): accesos a pacientes y operaciones sensibles.
 * Ninguna guarda datos personales en claro: solo IDs y metadatos técnicos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('action', 50);
            $table->ipAddress('ip')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            // Solo IDs, estados y cantidades: SIN datos personales.
            $table->jsonb('metadata')->default('{}');
            $table->ipAddress('ip')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE patient_access_logs ADD CONSTRAINT patient_access_logs_action_not_blank CHECK (btrim(action) <> '')");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_not_blank CHECK (btrim(action) <> '')");

        foreach (['patient_access_logs', 'audit_logs'] as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation()'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('patient_access_logs');
    }
};
