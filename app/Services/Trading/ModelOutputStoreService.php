<?php

namespace App\Services\Trading;

use App\Models\ModelScanResult;

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
        return ModelScanResult::query()->updateOrCreate(
            [
                'model_name' => $modelName,
                'execution_id' => $executionId,
            ],
            [
                'execution_date' => $executionDate,
                'result' => $result,
                'supporting_data' => $supportingData,
            ],
        );
    }
}
