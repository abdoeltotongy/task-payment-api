<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Payments\Gateways\PayPalGateway;
use Tests\TestCase;

uses(TestCase::class);

it('approves a paypal account whose email does not contain fail', function () {
    $payment = new Payment;
    $payment->id = 7;

    $result = (new PayPalGateway(['client_id' => 'client_1']))->process($payment, [
        'email' => 'ada@example.com',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->transactionId)->toBe('pp_7')
        ->and($result->message)->toBe('PayPal payment approved.');
});

it('declines a paypal account whose email contains fail', function () {
    $payment = new Payment;
    $payment->id = 8;

    $result = (new PayPalGateway)->process($payment, [
        'email' => 'fail@example.com',
    ]);

    expect($result->success)->toBeFalse()
        ->and($result->transactionId)->toBeNull()
        ->and($result->message)->toBe('PayPal payment declined.');
});
