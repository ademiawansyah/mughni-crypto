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
        Schema::create('coin_market_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coin_id')->constrained()->onDelete('cascade');
            $table->string('data_type');
            $table->string('source');
            $table->string('interval')->nullable();
            $table->jsonb('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coin_market_data');
    }
};
