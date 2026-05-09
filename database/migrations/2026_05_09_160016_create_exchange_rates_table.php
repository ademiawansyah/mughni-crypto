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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 10)->comment('Source currency (e.g., USD, USDT)');
            $table->string('to_currency', 10)->comment('Target currency (e.g., IDR)');
            $table->decimal('rate', 18, 8)->comment('Exchange rate');
            $table->string('source', 50)->comment('Rate source (indodax, coingecko, etc.)');
            $table->timestamp('refreshed_at')->nullable()->comment('When the rate was last fetched');
            $table->timestamps();

            // Unique index on currency pair to prevent duplicates
            $table->unique(['from_currency', 'to_currency'], 'unique_currency_pair');

            // Index for quick lookups by currency pair
            $table->index(['from_currency', 'to_currency'], 'idx_currency_pair');
            $table->index(['refreshed_at'], 'idx_refreshed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
