<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manufacturing module normally adds these; DummyBusinessSeeder expects them on fresh core installs.
     */
    public function up(): void
    {
        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'mfg_is_final')) {
            Schema::table('transactions', function (Blueprint $table) {
                $after = Schema::hasColumn('transactions', 'opening_stock_product_id')
                    ? 'opening_stock_product_id'
                    : 'transfer_parent_id';
                $table->boolean('mfg_is_final')->default(false)->after($after);
                $table->unsignedInteger('mfg_parent_production_purchase_id')->nullable()->after('mfg_is_final');
                $table->decimal('mfg_production_cost', 22, 4)->default(0)->after('mfg_parent_production_purchase_id');
                $table->decimal('mfg_wasted_units', 22, 4)->nullable()->after('mfg_production_cost');
            });
        }

        if (Schema::hasTable('transaction_sell_lines')) {
            if (! Schema::hasColumn('transaction_sell_lines', 'mfg_waste_percent')) {
                Schema::table('transaction_sell_lines', function (Blueprint $table) {
                    $after = Schema::hasColumn('transaction_sell_lines', 'quantity_returned')
                        ? 'quantity_returned'
                        : 'quantity';
                    $table->decimal('mfg_waste_percent', 5, 2)->default(0)->after($after);
                });
            }
            if (! Schema::hasColumn('transaction_sell_lines', 'woocommerce_line_items_id')) {
                $afterWoo = Schema::hasColumn('transaction_sell_lines', 'res_line_order_status')
                    ? 'res_line_order_status'
                    : null;
                Schema::table('transaction_sell_lines', function (Blueprint $table) use ($afterWoo) {
                    $c = $table->unsignedBigInteger('woocommerce_line_items_id')->nullable();
                    if ($afterWoo !== null) {
                        $c->after($afterWoo);
                    }
                });
            }
        }

        if (! Schema::hasTable('mfg_recipes')) {
            Schema::create('mfg_recipes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variation_id');
                $table->text('instructions')->nullable();
                $table->decimal('waste_percent', 5, 2)->default(0);
                $table->decimal('ingredients_cost', 22, 4)->default(0);
                $table->decimal('extra_cost', 22, 4)->default(0);
                $table->decimal('total_quantity', 22, 4)->default(0);
                $table->decimal('final_price', 22, 4)->default(0);
                $table->unsignedInteger('sub_unit_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mfg_recipe_ingredients')) {
            Schema::create('mfg_recipe_ingredients', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('mfg_recipe_id');
                $table->unsignedInteger('variation_id');
                $table->decimal('quantity', 22, 4)->default(0);
                $table->decimal('waste_percent', 5, 2)->default(0);
                $table->unsignedInteger('sub_unit_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'mfg_is_final')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn([
                    'mfg_is_final',
                    'mfg_parent_production_purchase_id',
                    'mfg_production_cost',
                    'mfg_wasted_units',
                ]);
            });
        }

        if (Schema::hasTable('transaction_sell_lines')) {
            $drop = [];
            if (Schema::hasColumn('transaction_sell_lines', 'mfg_waste_percent')) {
                $drop[] = 'mfg_waste_percent';
            }
            if (Schema::hasColumn('transaction_sell_lines', 'woocommerce_line_items_id')) {
                $drop[] = 'woocommerce_line_items_id';
            }
            if ($drop !== []) {
                Schema::table('transaction_sell_lines', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }

        Schema::dropIfExists('mfg_recipe_ingredients');
        Schema::dropIfExists('mfg_recipes');
    }
};
