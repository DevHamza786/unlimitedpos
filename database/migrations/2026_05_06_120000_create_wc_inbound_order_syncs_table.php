<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores WooCommerce / Square (or other gateway) paid orders pushed from WP.
     */
    public function up(): void
    {
        Schema::create('wc_inbound_order_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('business_id');
            $table->string('wc_order_id', 64);
            $table->string('wc_order_key', 64)->nullable();
            $table->string('transaction_id', 191)->nullable()->index();
            $table->string('payment_status', 64);
            $table->char('currency', 3)->default('USD');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->decimal('tax_total', 22, 4)->nullable();
            $table->string('customer_name', 512)->nullable();
            $table->string('customer_email', 191)->nullable()->index();
            $table->string('customer_phone', 64)->nullable();
            $table->json('items')->nullable();
            $table->json('payload')->nullable();
            $table->string('source', 32)->default('woocommerce');
            $table->timestamp('wc_created_at')->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'wc_order_id'], 'wc_inbound_business_order_unique');
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_inbound_order_syncs');
    }
};
