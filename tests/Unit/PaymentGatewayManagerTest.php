<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Payment;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\PaymentGatewayManager;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

uses(TestCase::class);

it('resolves enabled gateways from config', function () {
    $manager = app(PaymentGatewayManager::class);

    expect($manager->driver('credit_card'))->toBeInstanceOf(CreditCardGateway::class)
        ->and($manager->driver('paypal'))->toBeInstanceOf(PayPalGateway::class)
        ->and($manager->enabledMethods())->toContain('credit_card', 'paypal');
});

it('rejects an unknown payment method', function () {
    app(PaymentGatewayManager::class)->driver('wire');
})->throws(UnsupportedPaymentMethodException::class);

it('rejects a disabled payment method', function () {
    config(['payment.gateways.paypal.enabled' => false]);

    $manager = app(PaymentGatewayManager::class);

    expect($manager->enabledMethods())->not->toContain('paypal')
        ->and(fn () => $manager->driver('paypal'))->toThrow(UnsupportedPaymentMethodException::class);
});

it('resolves a gateway registered at runtime', function () {
    $manager = app(PaymentGatewayManager::class);
    $manager->extend('fake', FakePaymentGateway::class, ['mode' => 'test']);

    $gateway = $manager->driver('fake');
    $payment = new Payment;
    $payment->id = 5;
    $result = $gateway->process($payment);

    expect($gateway)->toBeInstanceOf(FakePaymentGateway::class)
        ->and($manager->enabledMethods())->toContain('fake')
        ->and($result->success)->toBeTrue()
        ->and($result->transactionId)->toBe('fake_5')
        ->and($result->raw['mode'])->toBe('test');
});
