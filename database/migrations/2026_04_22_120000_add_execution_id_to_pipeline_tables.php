<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_raw', function (Blueprint $table) {
            $table->uuid('execution_id')->nullable()->after('id');
            $table->index('execution_id');
        });

        Schema::table('market_indicators', function (Blueprint $table) {
            $table->uuid('execution_id')->nullable()->after('id');
            $table->index('execution_id');
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->uuid('execution_id')->nullable()->after('id');
            $table->index('execution_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropIndex(['execution_id']);
            $table->dropColumn('execution_id');
        });

        Schema::table('market_indicators', function (Blueprint $table) {
            $table->dropIndex(['execution_id']);
            $table->dropColumn('execution_id');
        });

        Schema::table('market_raw', function (Blueprint $table) {
            $table->dropIndex(['execution_id']);
            $table->dropColumn('execution_id');
        });
    }
};
