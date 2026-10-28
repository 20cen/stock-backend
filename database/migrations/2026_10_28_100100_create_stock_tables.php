<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Solde courant par article et emplacement. Écrit uniquement par App\Services\Stock\StockService.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->integer('quantity_on_hand')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'location_id']);
            $table->index('location_id');
        });

        // Journal en ajout seul : quantité signée (+ entrée, - sortie) et solde obtenu après le mouvement.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->nullableMorphs('source');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['article_id', 'location_id', 'id']);
            $table->index('type');
        });

        // Dernier rempart si une écriture contournait le service (TST-009, TST-011).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantity_non_negative CHECK (quantity_on_hand >= 0)');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_not_zero CHECK (quantity <> 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};
