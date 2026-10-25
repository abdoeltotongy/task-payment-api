<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Payments\Gateways\CreditCardGateway;
use Tests\TestCase;

uses(TestCase::class);

it('approves a card that does not end in 0000', function () {
    $payment = new Payment;
    $payment->id = 10;

    $result = (new CreditCardGateway(['merchant_id' => 'merchant_1']))->process($payment, [
        'card_number' => '4242424242424242',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->transactionId)->toBe('cc_10')
        ->and($result->message)->toBe('Payment approved.')
        ->and($result->raw['card_last4'])->toBe('4242');
});

it('declines a card that ends in 0000', function () {
    $payment = new Payment;
    $payment->id = 11;

    $result = (new CreditCardGateway)->process($payment, [
        'card_number' => '4242424242420000',
    ]);

    expect($result->success)->toBeFalse()
        ->and($result->transactionId)->toBeNull()
        ->and($result->message)->toBe('Card declined.');
});

it('does not approve a missing card number', function () {
    $payment = new Payment;
    $payment->id = 12;

    $result = (new CreditCardGateway)->process($payment, []);

    expect($result->success)->toBeFalse()
        ->and($result->transactionId)->toBeNull()
        ->and($result->message)->toBe('Card details are invalid.')
        ->and($result->raw)->toBe([]);
});
