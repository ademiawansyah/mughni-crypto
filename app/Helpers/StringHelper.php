<?php

namespace App\Helpers;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;

class StringHelper
{
    /**
     * Convert a raw text value into a readable title-style string.
     *
     * Input:
     * - $text: raw text such as snake_case, kebab-case, or camelCase.
     *
     * Output:
     * - Humanized text in title case.
     */
    public static function humanizeText(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '-';
        }

        return Str::of($text)
            ->replace(['_', '-'], ' ')
            ->snake()
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    /**
     * Format numbers with configurable decimal and thousands separators.
     *
     * Input:
     * - $number: numeric value to format.
     * - $decimals: number of decimal places.
     * - $decimalSeparator: separator for decimal part.
     * - $thousandsSeparator: separator for thousands part.
     *
     * Output:
     * - Formatted number string.
     */
    public static function numberFormat(
        int|float|string|null $number,
        int $decimals = 0,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ','
    ): string {
        if ($number === null || $number === '') {
            return '-';
        }

        if (! is_numeric($number)) {
            return '-';
        }

        return number_format((float) $number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Convert a date/time value into a readable date-time string.
     *
     * Input:
     * - $value: date-time string, Unix timestamp, or DateTimeInterface.
     * - $format: output format compatible with PHP date formatting.
     * - $timezone: optional timezone identifier.
     *
     * Output:
     * - Readable formatted date-time string.
     */
    public static function readableDateTime(
        DateTimeInterface|string|int|null $value,
        string $format = 'd M Y H:i',
        ?string $timezone = null
    ): string {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            if ($value instanceof DateTimeInterface) {
                $dateTime = Carbon::instance($value);
            } elseif (is_int($value)) {
                $dateTime = Carbon::createFromTimestamp($value);
            } else {
                $dateTime = Carbon::parse($value);
            }

            if ($timezone !== null) {
                $dateTime = $dateTime->setTimezone($timezone);
            }

            return $dateTime->format($format);
        } catch (\Throwable) {
            return '-';
        }
    }
}
