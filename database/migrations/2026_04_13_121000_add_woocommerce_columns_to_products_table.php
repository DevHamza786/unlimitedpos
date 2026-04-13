<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Woocommerce module fields; core UI and DummyBusinessSeeder use these even without the module.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'woocommerce_disable_sync')) {
                $table->boolean('woocommerce_disable_sync')->default(false)->after(
                    Schema::hasColumn('products', 'repair_model_id') ? 'repair_model_id' : 'warranty_id'
                );
            }
            if (! Schema::hasColumn('products', 'woocommerce_media_id')) {
                $table->unsignedBigInteger('woocommerce_media_id')->nullable()->after('woocommerce_disable_sync');
            }
            if (! Schema::hasColumn('products', 'woocommerce_product_id')) {
                $table->unsignedBigInteger('woocommerce_product_id')->nullable()->after('woocommerce_media_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'woocommerce_product_id')) {
                $table->dropColumn('woocommerce_product_id');
            }
            if (Schema::hasColumn('products', 'woocommerce_media_id')) {
                $table->dropColumn('woocommerce_media_id');
            }
            if (Schema::hasColumn('products', 'woocommerce_disable_sync')) {
                $table->dropColumn('woocommerce_disable_sync');
            }
        });
    }
};
