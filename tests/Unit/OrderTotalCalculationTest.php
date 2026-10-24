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

it('returns zero when there are no items', function () {
    expect((new OrderTotals)->sum([]))->toBe('0.00');
});

it('normalizes a price to two decimal places', function () {
    expect((new OrderTotals)->normalize('10.5'))->toBe('10.50');
});
