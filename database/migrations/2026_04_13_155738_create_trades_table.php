<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->string('coin');
            $table->string('action');
            $table->decimal('price', 18, 8);
            $table->decimal('amount', 18, 8);
            $table->decimal('total_value', 18, 8);
            $table->decimal('fee', 18, 8)->nullable();
            $table->foreignId('ai_decision_id')->nullable()->constrained('ai_decisions')->nullOnDelete();
            $table->decimal('profit_loss', 18, 8)->nullable();
            $table->float('profit_loss_pct')->nullable();
            $table->unsignedInteger('holding_duration_sec')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('timestamp')->comment('Market timestamp when trade was executed');
            $table->timestamp('created_at')->useCurrent()->comment('Record creation timestamp');

            $table->index(['coin', 'timestamp']);
            $table->index('ai_decision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
