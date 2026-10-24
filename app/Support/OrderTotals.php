<?php

declare(strict_types=1);

namespace App\Support;

final class OrderTotals
{
    /**
     * @param  list<array{quantity: int|string, price: int|float|string}>  $items
     */
    public function sum(array $items): string
    {
        $cents = 0;

        foreach ($items as $item) {
            $cents += $this->toCents($item['price']) * (int) $item['quantity'];
        }

        return $this->fromCents($cents);
    }

    public function normalize(int|float|string $amount): string
    {
        return $this->fromCents($this->toCents($amount));
    }

    public function toCents(int|float|string $amount): int
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = explode('.', $normalized);
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
