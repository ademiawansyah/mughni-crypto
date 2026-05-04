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
        Schema::create('model_scan_results', function (Blueprint $table) {
            $table->id();
            $table->string('model_name');
            $table->string('execution_id');
            $table->date('execution_date');
            $table->jsonb('result');
            $table->jsonb('supporting_data')->nullable();
            $table->timestamps();

            $table->unique(['model_name', 'execution_id']);
            $table->index(['model_name', 'execution_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_scan_results');
    }
};
