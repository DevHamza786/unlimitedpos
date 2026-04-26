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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('contact_id')->index();

            $table->string('code', 64)->unique();

            $table->decimal('discount_percent', 5, 2);
            $table->decimal('max_discount_amount', 22, 4)->nullable();
            $table->decimal('min_purchase_amount', 22, 4)->nullable();

            $table->string('status', 20)->default('active')->index(); // active|redeemed|expired|cancelled
            $table->dateTime('expires_at')->nullable()->index();

            $table->unsignedInteger('issued_by')->nullable()->index();
            $table->string('issued_via', 20)->default('manual')->index(); // website|boss|manual

            $table->string('sent_to_email')->nullable();
            $table->dateTime('sent_at')->nullable();

            $table->dateTime('redeemed_at')->nullable();
            $table->unsignedInteger('redeemed_transaction_id')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

