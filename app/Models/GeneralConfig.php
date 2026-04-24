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
        return static::getArray('coins', []);
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
        return static::getArray('timeframes', []);
    }
}
