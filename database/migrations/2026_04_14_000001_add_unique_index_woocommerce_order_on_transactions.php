<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One POS sell per WooCommerce order id (stops duplicate imports on "Order updated").
     */
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'woocommerce_order_id')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            try {
                $table->dropIndex('transactions_business_woocommerce_order_idx');
            } catch (\Throwable $e) {
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(
                ['business_id', 'woocommerce_order_id'],
                'transactions_business_woocommerce_order_uid'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'woocommerce_order_id')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            try {
                $table->dropUnique('transactions_business_woocommerce_order_uid');
            } catch (\Throwable $e) {
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['business_id', 'woocommerce_order_id'], 'transactions_business_woocommerce_order_idx');
        });
    }
};
