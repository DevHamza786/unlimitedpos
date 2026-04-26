<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('voucher_id')->index();
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('contact_id')->nullable()->index();

            // POS redemption
            $table->unsignedInteger('transaction_id')->nullable()->index();

            // Website redemption reference
            $table->string('order_ref')->nullable()->index();

            $table->decimal('discount_amount', 22, 4);
            $table->dateTime('redeemed_at')->index();
            $table->json('meta_json')->nullable();

            $table->timestamps();

            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};

