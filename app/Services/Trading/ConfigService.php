<?php

namespace App\Services\Trading;

use App\Models\GeneralConfig;

/**
 * ConfigService
 *
 * Loads runtime trading configuration from general_config table.
 */
class ConfigService
{
    /**
     * @return array<string>
     */
    public function getTimeframes(): array
    {
        $record = GeneralConfig::query()
            ->where('key', 'timeframes')
            ->value('value');

        if (! is_string($record) || trim($record) === '') {
            return [];
        }

        $values = array_map('trim', explode(',', $record));
        $values = array_filter($values, static fn (string $value): bool => $value !== '');

        return array_values(array_unique($values));
    }

    /**
     * @return array<string>
     */
    public function getCoins(): array
    {
        $record = GeneralConfig::query()
            ->where('key', 'coins')
            ->value('value');

        if (! is_string($record) || trim($record) === '') {
            return [];
        }

        $values = array_map('trim', explode(',', $record));
        $values = array_filter($values, static fn (string $value): bool => $value !== '');

        return array_values(array_unique($values));
    }

    public function getSignalActivationMode(): string
    {
        $record = GeneralConfig::query()
            ->where('key', 'signal_activation')
            ->value('value');

        if (! is_string($record)) {
            return 'balanced';
        }

        $mode = strtolower(trim($record));

        return in_array($mode, ['conservative', 'balanced', 'aggressive'], true)
            ? $mode
            : 'balanced';
    }

    /**
     * @return array<string, float>
     */
    public function getTimeframeWeights(): array
    {
        $record = GeneralConfig::query()
            ->where('key', 'timeframe_weights')
            ->value('value');

        if (! is_string($record) || trim($record) === '') {
            return [];
        }

        $decoded = json_decode($record, true);

        if (! is_array($decoded)) {
            return [];
        }

        $weights = [];

        foreach ($decoded as $timeframe => $weight) {
            if (! is_string($timeframe) || ! is_numeric($weight)) {
                continue;
            }

            $normalized = trim($timeframe);
            $value = (float) $weight;

            if ($normalized === '' || $value <= 0.0) {
                continue;
            }

            $weights[$normalized] = $value;
        }

        return $weights;
    }
}
