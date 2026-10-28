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
        // Session de caisse d'un caissier : obligatoire pour vendre (TST-025), clôturée avec écart justifié (TST-026).
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('open'); // open, closed
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 14, 2); // fonds de caisse déclaré
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('declared_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable(); // déclaré − théorique
            $table->text('variance_reason')->nullable();
            $table->jsonb('totals_by_method')->nullable(); // solde théorique par mode, figé à la clôture
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'status']);
        });

        // Une seule session ouverte par caissier, garantie par la base.
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_cashier ON cash_sessions (cashier_id) WHERE status = 'open'");

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->timestamp('sold_at')->index();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('validated');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('change_given', 14, 2)->default(0); // monnaie rendue (espèces)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'sold_at']);
            $table->index(['customer_id', 'sold_at']);
        });

        // Prix et coût figés au moment de la vente : les rapports passés ne changent pas avec le catalogue.
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('unit_cost_snapshot', 14, 4)->nullable(); // CMP au moment de la vente
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['cash_session_id', 'payment_method_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_paid_not_above_total CHECK (amount_paid <= total)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_sessions');
    }
};
