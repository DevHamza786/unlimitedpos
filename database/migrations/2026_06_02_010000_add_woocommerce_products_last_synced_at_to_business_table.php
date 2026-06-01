<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('business', function (Blueprint $table) {
            if (! Schema::hasColumn('business', 'woocommerce_products_last_synced_at')) {
                $table->timestamp('woocommerce_products_last_synced_at')->nullable()->comment('Last time products were auto-synced from WooCommerce');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('business', function (Blueprint $table) {
            if (Schema::hasColumn('business', 'woocommerce_products_last_synced_at')) {
                $table->dropColumn('woocommerce_products_last_synced_at');
            }
        });
    }
};
