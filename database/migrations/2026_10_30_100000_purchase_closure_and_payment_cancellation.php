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
        // Clôture d'une acquisition dont le reste ne sera jamais livré : la valeur non livrée n'est plus due.
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('undelivered_amount', 14, 2)->default(0)->after('total');
            $table->timestamp('closed_at')->nullable()->after('cancel_reason');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->restrictOnDelete();
            $table->text('close_reason')->nullable()->after('closed_by');
        });

        // Paiement saisi par erreur : annulé (jamais supprimé), puis ressaisi correctement.
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_paid_not_above_total');
            DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_paid_not_above_total CHECK (paid_amount <= total - undelivered_amount)');
            DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_undelivered_range CHECK (undelivered_amount >= 0 AND undelivered_amount <= total)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_undelivered_range');
            DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_paid_not_above_total');
            DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_paid_not_above_total CHECK (paid_amount <= total)');
        }

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['undelivered_amount', 'closed_at', 'close_reason']);
        });
    }
};
