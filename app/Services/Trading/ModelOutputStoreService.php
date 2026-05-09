<?php

namespace App\Services\Trading;

use App\Models\ModelScanResult;
use App\Models\ModelScanResultDetail;

class ModelOutputStoreService
{
    /**
     * Persist one model execution output and its supporting analysis payload.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $supportingData
     */
    public function store(
        string $modelName,
        string $executionId,
        string $executionDate,
        array $result,
        array $supportingData,
    ): ModelScanResult {
        $modelScanResult = ModelScanResult::query()
            ->where('model_name', $modelName)
            ->where('execution_id', $executionId)
            ->first();
        if ($modelScanResult) {
            $modelScanResult->update([
                'execution_date' => $executionDate,
                'result' => $result,
                'supporting_data' => $supportingData,
            ]);
        } else {
            $modelScanResult = ModelScanResult::query()->create([
                'model_name' => $modelName,
                'execution_id' => $executionId,
                'execution_date' => $executionDate,
                'result' => $result,
                'supporting_data' => $supportingData,
            ]);
        }

        // store failed coins as supporting data details for easier querying and analysis
        $failedCoins = is_array($supportingData['failed_coins'] ?? null)
            ? $supportingData['failed_coins']
            : [];

        foreach ($failedCoins as $coinData) {
            if (! is_array($coinData) || ! isset($coinData['id'])) {
                continue;
            }

            $this->storeSupportingData(
                modelScanResultId: $modelScanResult->id,
                coinId: $coinData['id'],
                rank: $coinData['rank'] ?? 0,
                isPassed: false,
                price: $coinData['price'] ?? null,
                stopLoss: $coinData['stop_loss'] ?? null,
                score: $coinData['score'] ?? null,
                data: [
                    'reason' => $coinData['reason'] ?? null,
                    'context' => $coinData['context'] ?? null,
                ],
            );
        }

        return $modelScanResult;
    }

    private function storeSupportingData(
        int $modelScanResultId,
        int $coinId,
        int $rank,
        bool $isPassed,
        ?float $price,
        ?float $stopLoss,
        ?float $score,
        array $data
    ): void {
        ModelScanResultDetail::query()->create([
            'model_scan_result_id' => $modelScanResultId,
            'coin_id' => $coinId,
            'is_passed' => $isPassed,
            'rank' => $rank,
            'price' => $price,
            'stop_loss' => $stopLoss,
            'score' => $score,
            'data' => $data,
        ]);
    }
}
