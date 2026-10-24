<?php

declare(strict_types=1);

use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PayPalGateway;

return [
    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | Each enabled gateway is available to validation and PaymentGatewayManager.
    | Adding a gateway requires a class, an entry here, and the matching env keys.
    |
    */
    'gateways' => [
        'credit_card' => [
            'class' => CreditCardGateway::class,
            'enabled' => env('PAYMENT_CREDIT_CARD_ENABLED', true),
            'config' => [
                'merchant_id' => env('PAYMENT_CREDIT_CARD_MERCHANT_ID'),
                'api_key' => env('PAYMENT_CREDIT_CARD_API_KEY'),
            ],
        ],
        'paypal' => [
            'class' => PayPalGateway::class,
            'enabled' => env('PAYMENT_PAYPAL_ENABLED', true),
            'config' => [
                'client_id' => env('PAYMENT_PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYMENT_PAYPAL_CLIENT_SECRET'),
            ],
        ],
    ],
];
