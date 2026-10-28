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
        // Retour rattaché à une vente (RG-RET-01). Demande « pending » sans effet sur le stock ;
        // la validation décide de la destination de chaque ligne (RG-RET-03/04).
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->index(); // pending, validated, rejected
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->decimal('total_value', 14, 2); // valeur des articles retournés (prix net payé)
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('condition', 20); // good, damaged
            $table->decimal('value', 14, 2);
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->restrictOnDelete(); // fixée à la validation
            $table->timestamps();
        });

        // Remboursement : opération financière distincte (RG-RET-05), rattachée à la caisse de celui qui rembourse.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete(); // null : annulation de vente
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reference', 100)->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->index(['cash_session_id', 'payment_method_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('refunded_amount', 14, 2)->default(0)->after('change_given');
            $table->timestamp('cancelled_at')->nullable()->after('notes');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sale_return_lines ADD CONSTRAINT sale_return_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_refunded_not_above_paid CHECK (refunded_amount <= amount_paid)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_refunded_not_above_paid');
        }
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['refunded_amount', 'cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
