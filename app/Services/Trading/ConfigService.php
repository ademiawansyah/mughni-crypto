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
}
