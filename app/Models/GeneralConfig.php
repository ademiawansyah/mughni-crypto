<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a single key-value application configuration entry stored in the database.
 *
 * Used for dynamic settings that should be manageable at runtime without modifying
 * environment files or config files. Example keys: 'coins', 'timeframes'.
 *
 * @property int $id
 * @property string $key Unique setting key (e.g. 'coins').
 * @property string $value Raw stored value. Use getValue() for parsed output.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class GeneralConfig extends Model
{
    private const TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    /** @var array<int, string> */
    private const DEFAULT_COINS = ['bitcoin', 'ethereum', 'solana'];

    /** @var array<int, string> */
    private const DEFAULT_TIMEFRAMES = ['5m', '15m', '1h', '4h'];

    protected $table = 'general_config';

    protected $fillable = ['key', 'value'];

    /**
     * Retrieve a config value by key.
     *
     * @param  string  $key  The config key to look up.
     * @param  mixed  $default  Fallback value if the key does not exist.
     * @return mixed The stored value string, or $default if not found.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $record = static::where('key', $key)->first();

        return $record?->value ?? $default;
    }

    /**
     * Retrieve a comma-separated config value as an array.
     *
     * @param  string  $key  The config key to look up.
     * @param  array  $default  Fallback array if the key does not exist.
     * @return array Parsed array of trimmed values.
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Set or update a config value by key.
     *
     * @param  string  $key  The config key.
     * @param  string  $value  The value to store.
     */
    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Retrieve watchlist coins from general_configs (key: coins).
     *
     * @return array<string>
     */
    public static function getWatchlistCoins(): array
    {
        $coins = static::getArray('coins', self::DEFAULT_COINS);

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $coin): string => strtolower(trim($coin)),
            $coins,
        ))));

        return $normalized === [] ? self::DEFAULT_COINS : $normalized;
    }

    /**
     * Retrieve the configured coin list.
     *
     * @return array<string>
     */
    public static function getCoins(): array
    {
        return static::getWatchlistCoins();
    }

    /**
     * Retrieve the configured timeframe list.
     *
     * @return array<string>
     */
    public static function getTimeframes(): array
    {
        $timeframes = static::getArray('timeframes', self::DEFAULT_TIMEFRAMES);

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $timeframe): string => strtolower(trim($timeframe)),
            $timeframes,
        ), static fn (string $timeframe): bool => preg_match('/^\d+(m|h)$/', $timeframe) === 1)));

        return $normalized === [] ? self::DEFAULT_TIMEFRAMES : $normalized;
    }

    /**
     * Retrieve a config value by key and cast it to boolean.
     *
     * @param  string  $key  The config key to look up.
     * @param  bool  $default  Fallback value if the key does not exist.
     */
    public static function getBool(string $key, bool $default = true): bool
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), self::TRUE_VALUES, true);
    }

    /**
     * Determine whether scheduled cron jobs are globally enabled.
     */
    public static function isCronEnabled(): bool
    {
        try {
            return static::getBool('cron_enabled', (bool) config('market.cron_enabled_default', true));
        } catch (\Throwable) {
            return (bool) config('market.cron_enabled_default', true);
        }
    }

    /**
     * Determine whether a specific model pipeline is enabled.
     */
    public static function isModelEnabled(string $model): bool
    {
        try {
            return static::getBool("{$model}_enabled", true);
        } catch (\Throwable) {
            return true;
        }
    }
}
