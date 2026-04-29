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
        Schema::create('coins', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('name')->nullable();
            $table->string('image')->nullable();
            $table->string('coin_gecko_id')->nullable();
            $table->dateTime('coin_data_last_updated')->nullable();
            $table->dateTime('last_fetched_at')->nullable();
            $table->float('market_cap')->nullable();
            $table->float('volume_24h')->nullable();
            $table->float('current_price')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coins');
    }
};
