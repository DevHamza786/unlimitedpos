<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair module links products to a device model; core app references this column.
     * DummyBusinessSeeder and ProductController expect it even when Repair is not installed.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'repair_model_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('repair_model_id')->nullable()->after('warranty_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'repair_model_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('repair_model_id');
            });
        }
    }
};
