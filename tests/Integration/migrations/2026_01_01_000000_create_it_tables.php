<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A regular Laravel migration (schema builder), no raw SQL and no
// $withinTransaction: two Schema::create calls in the same up().
return new class extends Migration
{
    protected $connection = 'dsql';

    public function up(): void
    {
        Schema::create('it_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('it_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('it_categories')->cascadeOnDelete();
            $table->string('sku');
            $table->integer('price')->default(0);
            $table->timestamps();
            $table->index(['category_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_products');
        Schema::dropIfExists('it_categories');
    }
};
