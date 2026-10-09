<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migracion "normal" de Laravel (schema builder), sin SQL crudo ni
// $withinTransaction: dos Schema::create en el mismo up().
return new class extends Migration
{
    protected $connection = 'dsql';

    public function up(): void
    {
        Schema::create('it_categorias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('it_productos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('categoria_id')->constrained('it_categorias')->cascadeOnDelete();
            $table->string('sku');
            $table->integer('precio')->default(0);
            $table->timestamps();
            $table->index(['categoria_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_productos');
        Schema::dropIfExists('it_categorias');
    }
};
