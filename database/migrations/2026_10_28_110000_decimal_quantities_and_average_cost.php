<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantités décimales (câble au mètre…) et valorisation au coût moyen pondéré (CMP) par article.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('alert_threshold', 14, 3)->default(0)->change();
            // false : l'article se compte en unités entières (refus de 0,5 pièce).
            $table->boolean('allow_decimal_quantity')->default(false)->after('unit');
            // CMP courant, mis à jour par StockService à chaque entrée valorisée. Null tant qu'aucun coût n'est connu.
            $table->decimal('average_cost', 14, 4)->nullable()->after('purchase_price_reference');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->decimal('quantity_on_hand', 14, 3)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity', 14, 3)->change();
            $table->decimal('balance_after', 14, 3)->change();
            // Entrée : coût d'achat ; sortie : CMP au moment du mouvement.
            $table->decimal('unit_cost', 14, 4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('quantity')->change();
            $table->integer('balance_after')->change();
            $table->decimal('unit_cost', 14, 2)->nullable()->change();
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->integer('quantity_on_hand')->default(0)->change();
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['allow_decimal_quantity', 'average_cost']);
            $table->unsignedInteger('alert_threshold')->default(0)->change();
        });
    }
};
