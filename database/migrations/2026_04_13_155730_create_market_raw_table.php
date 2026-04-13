<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_raw', function (Blueprint $table) {
            $table->id();
            $table->string('coin')->index();
            $table->string('endpoint')->index();
            $table->timestamp('timestamp')->index()->comment('Market Timestamp');
            $table->jsonb('request_params');
            $table->jsonb('response_json');
            $table->string('source')->default('coingecko');
            $table->timestamp('created_at')->useCurrent()->comment('Record Creation Timestamp');

            $table->index(['coin', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_raw');
    }
};
