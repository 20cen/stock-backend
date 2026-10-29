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
        // Perte déclarée : le stock ne change qu'à la validation (RG-PER-03, TST-016). Jamais supprimée (RG-PER-04).
        Schema::create('losses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('reason', 30); // casse, détérioration, panne, disparition, vol, inutilisable, autre
            $table->text('comment')->nullable();
            $table->decimal('estimated_value', 14, 2)->nullable(); // quantité × CMP à la déclaration
            $table->string('status', 20)->index(); // pending, validated, rejected
            $table->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('declared_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        // Inventaire d'un emplacement : quantités théoriques figées à l'ouverture (RG-INV-02),
        // écarts appliqués seulement à la validation (RG-INV-03/04).
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->index(); // in_progress, validated, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('theoretical_quantity', 14, 3); // figée à l'ouverture (ou à l'ajout de l'article)
            $table->decimal('counted_quantity', 14, 3)->nullable(); // null : pas encore compté
            $table->text('reason')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'article_id']);
        });

        // Un seul inventaire en cours par emplacement.
        DB::statement("CREATE UNIQUE INDEX inventories_one_in_progress_per_location ON inventories (location_id) WHERE status = 'in_progress'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE losses ADD CONSTRAINT losses_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE inventory_lines ADD CONSTRAINT inventory_lines_counted_non_negative CHECK (counted_quantity IS NULL OR counted_quantity >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_lines');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('losses');
    }
};
