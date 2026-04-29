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
        Schema::table('market_indicators', function (Blueprint $table) {
            $table->decimal('open_interest', 30, 8)->nullable()->after('volatility');
            $table->decimal('funding_rate', 18, 10)->nullable()->after('open_interest');
            $table->decimal('cvd', 30, 8)->nullable()->after('funding_rate');
            $table->decimal('cvd_slope', 30, 8)->nullable()->after('cvd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_indicators', function (Blueprint $table) {
            $table->dropColumn(['open_interest', 'funding_rate', 'cvd', 'cvd_slope']);
        });
    }
};
