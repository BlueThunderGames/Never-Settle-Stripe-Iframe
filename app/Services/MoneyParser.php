<?php

namespace App\Services;

use InvalidArgumentException;

class MoneyParser
{
    /**
     * Convert a user-entered dollar amount ("1,000.50", "$12", 19.99) to integer cents.
     *
     * Parsing is done on the decimal string so no float rounding is involved.
     *
     * @throws InvalidArgumentException
     */
    public function toCents(string|int|float|null $input): int
    {
        $value = is_float($input) ? $this->floatToString($input) : trim((string) $input);
        $value = ltrim($value, '$');
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Enter an amount.');
        }

        // 1,234 / 1234 / 1,234.5 / 1234.50 / .50
        if (! preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)?(?:\.\d{1,2})?$/', $value) || ! preg_match('/\d/', $value)) {
            throw new InvalidArgumentException('Enter a valid dollar amount, e.g. 125.50');
        }

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $value), 2), 2, '');

        // Keeps the cents value well inside int range.
        if (strlen($whole) > 12) {
            throw new InvalidArgumentException('That amount is too large.');
        }

        return ((int) ($whole === '' ? '0' : $whole)) * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function floatToString(float $value): string
    {
        if (! is_finite($value) || $value < 0) {
            throw new InvalidArgumentException('Enter a valid dollar amount, e.g. 125.50');
        }

        // Shortest round-trip representation; avoids "1.0E+25" style output.
        $string = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $string === '' ? '0' : $string;
    }
}
