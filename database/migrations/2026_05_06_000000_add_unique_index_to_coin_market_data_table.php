<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicates = DB::table('coin_market_data')
            ->select([
                'coin_id',
                'data_type',
                'source',
                'interval',
                DB::raw('MAX(id) as keep_id'),
                DB::raw('COUNT(*) as duplicate_count'),
            ])
            ->groupBy('coin_id', 'data_type', 'source', 'interval')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('coin_market_data')
                ->where('coin_id', $duplicate->coin_id)
                ->where('data_type', $duplicate->data_type)
                ->where('source', $duplicate->source)
                ->when(
                    $duplicate->interval === null,
                    static fn ($query) => $query->whereNull('interval'),
                    static fn ($query) => $query->where('interval', $duplicate->interval),
                )
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('coin_market_data', function (Blueprint $table) {
            $table->unique(
                ['coin_id', 'data_type', 'source', 'interval'],
                'coin_market_data_coin_data_source_interval_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coin_market_data', function (Blueprint $table) {
            $table->dropUnique('coin_market_data_coin_data_source_interval_unique');
        });
    }
};
