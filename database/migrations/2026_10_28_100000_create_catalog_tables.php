<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Un article n'est jamais supprimé : il est désactivé (active = false).
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('designation');
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('pièce');
            $table->decimal('purchase_price_reference', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->unsignedInteger('alert_threshold')->default(0);
            $table->boolean('serial_tracking')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('designation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
        Schema::dropIfExists('categories');
    }
};
