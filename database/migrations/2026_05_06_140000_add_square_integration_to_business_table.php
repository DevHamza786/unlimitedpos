<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            if (! Schema::hasColumn('business', 'square_enabled')) {
                $table->boolean('square_enabled')->default(false)->after('woocommerce_webhook_secret');
            }
            if (! Schema::hasColumn('business', 'square_environment')) {
                $table->string('square_environment', 16)->default('production')->after('square_enabled');
            }
            if (! Schema::hasColumn('business', 'square_location_id')) {
                $table->string('square_location_id', 64)->nullable()->after('square_environment');
            }
            if (! Schema::hasColumn('business', 'square_access_token')) {
                $table->text('square_access_token')->nullable()->after('square_location_id');
            }
            if (! Schema::hasColumn('business', 'square_last_synced_at')) {
                $table->timestamp('square_last_synced_at')->nullable()->after('square_access_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            foreach (['square_last_synced_at', 'square_access_token', 'square_location_id', 'square_environment', 'square_enabled'] as $col) {
                if (Schema::hasColumn('business', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
