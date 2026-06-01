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
            if (! Schema::hasColumn('business', 'enable_auto_loyalty_discount')) {
                $table->boolean('enable_auto_loyalty_discount')->default(0)->comment('Auto apply a discount once a customer crosses a lifetime sales threshold');
            }
            if (! Schema::hasColumn('business', 'auto_discount_min_sales')) {
                $table->decimal('auto_discount_min_sales', 22, 4)->default(3000)->comment('Lifetime finalized sales amount required to unlock the auto discount');
            }
            if (! Schema::hasColumn('business', 'auto_discount_percent')) {
                $table->decimal('auto_discount_percent', 5, 2)->default(10)->comment('Percentage discount applied once the threshold is reached');
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
            $table->dropColumn(['enable_auto_loyalty_discount', 'auto_discount_min_sales', 'auto_discount_percent']);
        });
    }
};
