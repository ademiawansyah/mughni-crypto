<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the general_config table for storing application-wide key-value settings.
 * This replaces environment/config-file based settings for dynamic configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_config', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_config');
    }
};
