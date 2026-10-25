<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts prices to integer cents before multiplying, so order totals do not use floating-point arithmetic.
 */
final class OrderTotals
{
    /**
     * @param  list<array{quantity: int|string, price: int|float|string}>  $items
     */
    public function sum(array $items): string
    {
        $cents = 0;

        foreach ($items as $item) {
            $line = $this->toCents($item['price']) * (int) $item['quantity'];

            if (! is_int($line)) {
                throw new InvalidArgumentException('Order total is too large.');
            }

            $cents += $line;
        }

        return $this->fromCents($cents);
    }

    public function normalize(int|float|string $amount): string
    {
        return $this->fromCents($this->toCents($amount));
    }

    public function toCents(int|float|string $amount): int
    {
        return $this->parseDecimal($this->decimalString($amount));
    }

    public function fromCents(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function decimalString(int|float|string $amount): string
    {
        if (is_int($amount)) {
            return $amount.'.00';
        }

        if (is_string($amount)) {
            return trim($amount);
        }

        if (! is_finite($amount)) {
            throw new InvalidArgumentException('Amount must be a finite number.');
        }

        $encoded = json_encode($amount);

        if (! is_string($encoded)) {
            throw new InvalidArgumentException('Amount must be a finite number.');
        }

        return $encoded;
    }

    private function parseDecimal(string $amount): int
    {
        if (preg_match('/^(?<whole>\d{1,10})(?:\.(?<fraction>\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal with at most two places.');
        }

        $fraction = str_pad($matches['fraction'] ?? '', 2, '0');

        return ((int) $matches['whole'] * 100) + (int) $fraction;
    }
}
