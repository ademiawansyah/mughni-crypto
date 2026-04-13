<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_balance', 18, 8);
            $table->decimal('cash_balance', 18, 8);
            $table->decimal('asset_value', 18, 8);
            $table->decimal('unrealized_pnl', 18, 8);
            $table->decimal('realized_pnl', 18, 8);
            $table->decimal('drawdown', 18, 8)->nullable();
            $table->jsonb('positions');
            $table->timestamp('timestamp')->index()->comment('Market timestamp for the snapshot');
            $table->timestamp('created_at')->useCurrent()->comment('Record creation timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
    }
};
