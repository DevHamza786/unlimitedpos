<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            if (! Schema::hasColumn('business', 'woocommerce_import_orders_enabled')) {
                $table->boolean('woocommerce_import_orders_enabled')->default(false)->after('woocommerce_consumer_secret');
            }
            if (! Schema::hasColumn('business', 'woocommerce_webhook_token')) {
                $table->string('woocommerce_webhook_token', 80)->nullable()->unique()->after('woocommerce_import_orders_enabled');
            }
            if (! Schema::hasColumn('business', 'woocommerce_default_location_id')) {
                $table->unsignedInteger('woocommerce_default_location_id')->nullable()->after('woocommerce_webhook_token');
            }
            if (! Schema::hasColumn('business', 'woocommerce_webhook_secret')) {
                $table->text('woocommerce_webhook_secret')->nullable()->after('woocommerce_default_location_id');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'woocommerce_order_id')) {
                $table->unsignedBigInteger('woocommerce_order_id')->nullable()->after('source');
                $table->index(['business_id', 'woocommerce_order_id'], 'transactions_business_woocommerce_order_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'woocommerce_order_id')) {
                $table->dropIndex('transactions_business_woocommerce_order_idx');
                $table->dropColumn('woocommerce_order_id');
            }
        });

        Schema::table('business', function (Blueprint $table) {
            foreach (['woocommerce_webhook_secret', 'woocommerce_default_location_id', 'woocommerce_webhook_token', 'woocommerce_import_orders_enabled'] as $col) {
                if (Schema::hasColumn('business', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
