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
        // Transfert entre emplacements : validé en une seule opération (déplacement immédiat, cahier des charges §4.5).
        // Le statut permettra d'ajouter « en préparation / expédié » et un stock en transit si le client le demande.
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 30)->default('validated');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at');
            $table->foreignId('validated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('validated_at');
        });

        Schema::create('transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_distinct_locations CHECK (source_location_id <> destination_location_id)');
            DB::statement('ALTER TABLE transfer_lines ADD CONSTRAINT transfer_lines_quantity_positive CHECK (quantity > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_lines');
        Schema::dropIfExists('transfers');
    }
};
