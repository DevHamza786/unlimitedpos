<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'square' to transaction_payments.method enum.
     *
     * Note: This project historically uses ENUM for payment methods.
     * Keep this list in sync with app\Utils\Util::payment_types().
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE transaction_payments MODIFY COLUMN method
            ENUM(
                'cash',
                'card',
                'square',
                'cheque',
                'bank_transfer',
                'advance',
                'custom_pay_1',
                'custom_pay_2',
                'custom_pay_3',
                'custom_pay_4',
                'custom_pay_5',
                'custom_pay_6',
                'custom_pay_7',
                'other'
            )"
        );
    }

    public function down(): void
    {
        // Non-destructive down: keep square to avoid data loss.
    }
};

