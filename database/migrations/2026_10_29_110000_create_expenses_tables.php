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
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Dépenses d'exploitation, distinctes des achats de marchandises (RG-DEP-02).
        // Jamais supprimées : annulées avec motif (RG-DEP-04).
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->date('expense_date')->index();
            $table->string('label');
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete(); // RG-DEP-01
            $table->decimal('amount', 14, 2);
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('beneficiary')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            // Payée en espèces depuis le tiroir : rattachée à la caisse ouverte, déduite des espèces attendues.
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('recorded')->index(); // recorded, cancelled
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['expense_category_id', 'expense_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
