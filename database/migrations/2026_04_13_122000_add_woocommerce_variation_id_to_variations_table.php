<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WooCommerce module maps POS variations to WC variation IDs; seeder expects the column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('variations', 'woocommerce_variation_id')) {
            Schema::table('variations', function (Blueprint $table) {
                $after = Schema::hasColumn('variations', 'variation_value_id')
                    ? 'variation_value_id'
                    : 'product_variation_id';
                $table->unsignedBigInteger('woocommerce_variation_id')->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('variations', 'woocommerce_variation_id')) {
            Schema::table('variations', function (Blueprint $table) {
                $table->dropColumn('woocommerce_variation_id');
            });
        }
    }
};
