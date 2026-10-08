<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo: bodegas, productos y lotes (RN-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('presentation', 150);
            $table->string('unit', 30);
            // RN-05: control especial => requiere autorización de un segundo regente.
            $table->boolean('is_controlled')->default(false);
            $table->timestamps();
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 50);
            // Fecha (sin hora): el lote es válido hasta el final de este día (S-14).
            $table->date('expires_at');
            $table->timestamps();

            $table->unique(['product_id', 'lot_number']);
            // Destino de las FK compuestas (lot_id, product_id) en stocks, kardex,
            // dispensaciones y traslados: garantiza que el product_id denormalizado
            // coincide con el producto real del lote.
            $table->unique(['id', 'product_id']);
            // FEFO y alertas de vencimiento.
            $table->index(['product_id', 'expires_at', 'id']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_code_not_blank CHECK (btrim(code) <> '')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_code_not_blank CHECK (btrim(code) <> '')");
        DB::statement("ALTER TABLE lots ADD CONSTRAINT lots_lot_number_not_blank CHECK (btrim(lot_number) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
        Schema::dropIfExists('products');
        Schema::dropIfExists('warehouses');
    }
};
