<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex (RN-06): un movimiento por cada cambio de stock, con saldo resultante.
 * Inmutable: trigger BEFORE UPDATE OR DELETE. Correcciones = movimiento AJUSTE
 * con motivo obligatorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kardex_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id');
            $table->string('type', 30);
            $table->integer('quantity');
            $table->smallInteger('direction');
            $table->integer('balance_after');
            $table->text('reason')->nullable();
            // Documento origen: dispensación, traslado, ajuste... (polimórfico).
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // El movimiento debe corresponder a una existencia real (bodega + lote)...
            $table->foreign(['warehouse_id', 'lot_id'])->references(['warehouse_id', 'lot_id'])->on('stocks')->restrictOnDelete();
            // ...y el producto debe ser el del lote.
            $table->foreign(['lot_id', 'product_id'])->references(['id', 'product_id'])->on('lots')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();

            // Filtros del kardex (producto, lote, bodega) ordenados por fecha.
            $table->index(['product_id', 'created_at']);
            $table->index(['lot_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index('created_at');
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_type_check CHECK (type IN ('ENTRADA', 'SALIDA_DISPENSACION', 'SALIDA_TRASLADO', 'ENTRADA_TRASLADO', 'AJUSTE'))");
        DB::statement('ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_direction_check CHECK (direction IN (-1, 1))');
        DB::statement('ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_balance_non_negative CHECK (balance_after >= 0)');
        // Las entradas suman y las salidas restan; solo AJUSTE puede ir en ambos sentidos.
        DB::statement(<<<'SQL'
            ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_direction_matches_type CHECK (
                (type NOT IN ('ENTRADA', 'ENTRADA_TRASLADO') OR direction = 1)
                AND (type NOT IN ('SALIDA_DISPENSACION', 'SALIDA_TRASLADO') OR direction = -1)
            )
            SQL);
        // RN-06: un AJUSTE siempre lleva motivo no vacío.
        DB::statement("ALTER TABLE kardex_movements ADD CONSTRAINT kardex_movements_adjustment_reason_required CHECK (type <> 'AJUSTE' OR (reason IS NOT NULL AND btrim(reason) <> ''))");

        // RN-06: inmutabilidad.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kardex_movements_append_only
            BEFORE UPDATE OR DELETE ON kardex_movements
            FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('kardex_movements');
    }
};
