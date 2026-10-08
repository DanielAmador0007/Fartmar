<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existencias por bodega + lote (RN-01, RN-03) y stock mínimo por bodega (RN-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lot_id');
            // Denormalizado desde lots para consultar/bloquear por bodega+producto
            // sin join; la FK compuesta impide que sea inconsistente.
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'lot_id']);
            $table->foreign(['lot_id', 'product_id'])->references(['id', 'product_id'])->on('lots')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->index('lot_id');
        });

        // RN-03: última línea de defensa contra stock negativo.
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantity_non_negative CHECK (quantity >= 0)');
        // Índice parcial para FEFO: solo filas con existencias.
        DB::statement('CREATE INDEX stocks_fefo_available_idx ON stocks (warehouse_id, product_id) WHERE quantity > 0');

        Schema::create('stock_minimums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('min_quantity');
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
        });

        DB::statement('ALTER TABLE stock_minimums ADD CONSTRAINT stock_minimums_min_quantity_non_negative CHECK (min_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_minimums');
        Schema::dropIfExists('stocks');
    }
};
