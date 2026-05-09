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

        // Replace details for this execution to keep persistence idempotent.
        ModelScanResultDetail::query()
            ->where('model_scan_result_id', $modelScanResult->id)
            ->delete();

        $analysisResults = is_array($supportingData['analysis_results'] ?? null)
            ? $supportingData['analysis_results']
            : [];

        foreach ($analysisResults as $analysisResult) {
            if (! is_array($analysisResult) || ! isset($analysisResult['coin_id'])) {
                continue;
            }

            $signal = is_array($analysisResult['signal'] ?? null)
                ? $analysisResult['signal']
                : [];
            $metadata = is_array($analysisResult['metadata'] ?? null)
                ? $analysisResult['metadata']
                : [];

            $stopLossValue = $signal['stop_loss']
                ?? $metadata['stop_loss']
                ?? null;

            $this->storeSupportingData(
                modelScanResultId: $modelScanResult->id,
                coinId: (int) $analysisResult['coin_id'],
                rank: (int) ($signal['rank'] ?? 0),
                isPassed: (string) ($analysisResult['analysis_status'] ?? '') === 'passed',
                price: is_numeric($analysisResult['price'] ?? null) ? (float) $analysisResult['price'] : null,
                stopLoss: is_numeric($stopLossValue) ? (float) $stopLossValue : null,
                score: is_numeric($analysisResult['score'] ?? null) ? (float) $analysisResult['score'] : null,
                data: $analysisResult,
            );
        }

        // Backward compatibility for legacy payloads without analysis_results.
        if ($analysisResults === []) {
            $failedCoins = is_array($supportingData['failed_coins'] ?? null)
                ? $supportingData['failed_coins']
                : [];

            foreach ($failedCoins as $coinData) {
                if (! is_array($coinData) || ! isset($coinData['id'])) {
                    continue;
                }

                $this->storeSupportingData(
                    modelScanResultId: $modelScanResult->id,
                    coinId: (int) $coinData['id'],
                    rank: (int) ($coinData['rank'] ?? 0),
                    isPassed: false,
                    price: is_numeric($coinData['price'] ?? null) ? (float) $coinData['price'] : null,
                    stopLoss: is_numeric($coinData['stop_loss'] ?? null) ? (float) $coinData['stop_loss'] : null,
                    score: is_numeric($coinData['score'] ?? null) ? (float) $coinData['score'] : null,
                    data: [
                        'reason' => $coinData['reason'] ?? null,
                        'context' => $coinData['context'] ?? null,
                    ],
                );
            }
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
