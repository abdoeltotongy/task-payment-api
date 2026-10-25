<?php

declare(strict_types=1);

use App\Support\OrderTotals;

it('sums line totals using integer cents', function () {
    $total = (new OrderTotals)->sum([
        ['quantity' => 2, 'price' => '10.25'],
        ['quantity' => 1, 'price' => '3.00'],
    ]);

    expect($total)->toBe('23.50');
});

it('keeps two decimal places when a price repeats', function () {
    $total = (new OrderTotals)->sum([
        ['quantity' => 3, 'price' => '1.10'],
        ['quantity' => 2, 'price' => '19.99'],
    ]);

    expect($total)->toBe('43.28');
});

it('multiplies cents instead of binary fractions', function () {
    $total = (new OrderTotals)->sum([
        ['quantity' => 3, 'price' => '0.10'],
        ['quantity' => 1, 'price' => '19.99'],
    ]);

    expect($total)->toBe('20.29');
});

it('returns zero when there are no items', function () {
    expect((new OrderTotals)->sum([]))->toBe('0.00');
});

it('normalizes a price to two decimal places', function () {
    expect((new OrderTotals)->normalize('10.5'))->toBe('10.50')
        ->and((new OrderTotals)->normalize(10))->toBe('10.00')
        ->and((new OrderTotals)->normalize(19.99))->toBe('19.99');
});

it('rejects amounts with more than two decimal places', function () {
    expect(fn () => (new OrderTotals)->normalize('1.005'))
        ->toThrow(InvalidArgumentException::class);
});
