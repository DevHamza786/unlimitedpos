<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            if (! Schema::hasColumn('business', 'woocommerce_enabled')) {
                $table->boolean('woocommerce_enabled')->default(false);
            }
            if (! Schema::hasColumn('business', 'woocommerce_store_url')) {
                $table->string('woocommerce_store_url', 512)->nullable();
            }
            if (! Schema::hasColumn('business', 'woocommerce_consumer_key')) {
                $table->text('woocommerce_consumer_key')->nullable();
            }
            if (! Schema::hasColumn('business', 'woocommerce_consumer_secret')) {
                $table->text('woocommerce_consumer_secret')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            if (Schema::hasColumn('business', 'woocommerce_consumer_secret')) {
                $table->dropColumn('woocommerce_consumer_secret');
            }
            if (Schema::hasColumn('business', 'woocommerce_consumer_key')) {
                $table->dropColumn('woocommerce_consumer_key');
            }
            if (Schema::hasColumn('business', 'woocommerce_store_url')) {
                $table->dropColumn('woocommerce_store_url');
            }
            if (Schema::hasColumn('business', 'woocommerce_enabled')) {
                $table->dropColumn('woocommerce_enabled');
            }
        });
    }
};
