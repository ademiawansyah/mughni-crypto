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
        Schema::create('model_scan_result_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_scan_result_id')->constrained('model_scan_results')->onDelete('cascade');
            $table->integer('rank');
            $table->foreignId('coin_id')->constrained('coins')->onDelete('cascade');
            $table->boolean('is_passed');
            $table->float('price')->nullable();
            $table->float('stop_loss')->nullable();
            $table->float('score')->nullable();
            $table->jsonb('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_scan_result_details');
    }
};
