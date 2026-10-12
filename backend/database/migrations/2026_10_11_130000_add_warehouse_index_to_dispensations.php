<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listado GET /dispensations (L8): filtra por bodega y estado y ordena por
 * fecha de creación descendente. Ya existía (status, created_at), pero
 * ningún índice empezaba por warehouse_id (en PostgreSQL la FK no crea
 * índice). Este cubre "bodega", "bodega + estado" y el orden por fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensations', function (Blueprint $table) {
            $table->index(['warehouse_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dispensations', function (Blueprint $table) {
            $table->dropIndex(['warehouse_id', 'status', 'created_at']);
        });
    }
};
