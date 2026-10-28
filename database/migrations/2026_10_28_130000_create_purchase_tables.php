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
        // Numérotation annuelle des documents (ACH-2026-00001…), attribuée sous verrou : ni doublon ni trou.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });

        // Modes de paiement (espèces, Mobile Money…), partagés par les achats et les ventes.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Acquisition : n'a aucun effet sur le stock (RG-ACH-01). Montants calculés par le serveur.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->string('supplier_reference', 100)->nullable(); // n° de facture ou de bon du fournisseur
            $table->string('status', 30)->index(); // draft, ordered, partially_received, received, cancelled
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('extra_costs', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'purchase_date']);
            $table->index('purchase_date');
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0); // remise en montant sur la ligne
            $table->decimal('line_total', 14, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Réception validée : chaque réception précise son emplacement (RG-ACH-04) et alimente le stock.
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            // Coût de revient unitaire : prix net de remise + part des frais supplémentaires.
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_lines ADD CONSTRAINT purchase_lines_quantities CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND quantity_received <= quantity_ordered)');
            DB::statement('ALTER TABLE receipt_lines ADD CONSTRAINT receipt_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE purchase_payments ADD CONSTRAINT purchase_payments_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_paid_not_above_total CHECK (paid_amount <= total)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('document_sequences');
    }
};
