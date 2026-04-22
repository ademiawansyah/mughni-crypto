<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->decimal('position_size', 18, 8)->nullable()->after('price_at_decision');
            $table->decimal('risk_amount', 18, 8)->nullable()->after('position_size');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropColumn(['position_size', 'risk_amount']);
        });
    }
};
