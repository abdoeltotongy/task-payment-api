# Extendable Order and Payment Management API

Laravel 11 REST API for orders and pluggable payment gateways. Order totals are calculated on the server. A new payment method is a gateway class plus configuration, with no changes to controllers, services, or validation.

## Setup

Requirements: PHP 8.2+, Composer, and MySQL. Tests use SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Create the MySQL database named in `DB_DATABASE` (default `extendable_order_payment`), then:

```bash
php artisan migrate --seed
php artisan serve
```

Demo accounts created by the seeder:

| Email | Password |
|---|---|
| demo@example.com | password |
| other@example.com | password |

`demo@example.com` owns pending, paid, declined, and cancelled orders. `other@example.com` owns a separate order.

Run the tests and formatter:

```bash
php vendor/bin/pest
php vendor/bin/pint
```

Import `postman/collection.json` into Postman. The collection variable `{{base_url}}` defaults to `http://localhost:8000/api`. The Login request stores `access_token` in `{{token}}`.

## Architecture

```
HTTP request
  -> auth:api middleware
  -> FormRequest (validation and policy)
  -> Controller
  -> Service
  -> Model
  -> API Resource
```

- Controllers stay thin. Business rules live in `OrderService` and `PaymentService`.
- `OrderStatus` and `PaymentStatus` are backed enums.
- Users can only reach their own orders and payments through `OrderPolicy` and `PaymentPolicy`.
- API errors use `{"message": "...", "errors": {...}}` with 401, 403, 404, 409, and 422.
- Payment methods are not hardcoded. `ProcessPaymentRequest` allows only the enabled keys in `config/payment.php`. `PaymentGatewayManager` resolves the matching class from the container.

### Payment flow

1. The order must belong to the user and be `confirmed`, otherwise `OrderNotConfirmedException` (422). The policy returns 403 before that when the order belongs to someone else.
2. A successful payment already on the order raises `PaymentAlreadyProcessedException` (409).
3. A database transaction creates a `pending` payment for the server-calculated order total.
4. The manager calls `PaymentGatewayInterface::process()`.
5. The payment becomes `successful` or `failed`, and stores `transaction_id` and `gateway_response`.

Simulated rules:

- Credit card: `data.card_number` ending in `0000` is declined.
- PayPal: `data.email` containing `fail` is declined.

## How to add a new payment gateway

1. Create a class that implements `PaymentGatewayInterface`.
2. Add one entry under `gateways` in `config/payment.php`.
3. Add the env keys that entry reads.

No controller, service, or validation change is required. `Rule::in(...)` reads the enabled gateway keys at request time.

```php
<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

final class BankTransferGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $reference = (string) ($data['reference'] ?? '');
        $declined = $reference === '';

        return new GatewayResult(
            success: ! $declined,
            transactionId: $declined ? null : 'bt_'.$payment->id,
            message: $declined ? 'Transfer reference is required.' : 'Transfer accepted.',
            raw: [
                'reference' => $reference,
                'bank_name' => $this->config['bank_name'] ?? null,
            ],
        );
    }
}
```

```php
'bank_transfer' => [
    'class' => \App\Payments\Gateways\BankTransferGateway::class,
    'enabled' => env('PAYMENT_BANK_TRANSFER_ENABLED', true),
    'config' => [
        'bank_name' => env('PAYMENT_BANK_TRANSFER_BANK_NAME'),
        'account_number' => env('PAYMENT_BANK_TRANSFER_ACCOUNT_NUMBER'),
    ],
],
```

```dotenv
PAYMENT_BANK_TRANSFER_ENABLED=true
PAYMENT_BANK_TRANSFER_BANK_NAME=
PAYMENT_BANK_TRANSFER_ACCOUNT_NUMBER=
```

`POST /api/orders/{order}/payments` then accepts `"method": "bank_transfer"`. A runtime test can also call `PaymentGatewayManager::extend()` without editing config files.

## Endpoints

All paths are under `/api`. Protected routes use `Authorization: Bearer {{token}}`.

| Method | Path | Auth | Success |
|---|---|---|---|
| POST | /auth/register | public | 201 |
| POST | /auth/login | public | 200 |
| POST | /auth/logout | bearer | 200 |
| POST | /auth/refresh | bearer | 200 |
| GET | /auth/me | bearer | 200 |
| GET | /orders | bearer | 200 |
| POST | /orders | bearer | 201 |
| GET | /orders/{order} | bearer | 200 |
| PUT | /orders/{order} | bearer | 200 |
| PATCH | /orders/{order}/status | bearer | 200 |
| DELETE | /orders/{order} | bearer | 204 |
| POST | /orders/{order}/payments | bearer | 201 |
| GET | /orders/{order}/payments | bearer | 200 |
| GET | /payments | bearer | 200 |
| GET | /payments/{payment} | bearer | 200 |

`GET /orders` accepts `status` (`pending`, `confirmed`, `cancelled`) and `per_page` (1–100). `GET /payments` accepts `status` (`pending`, `successful`, `failed`), `method`, and `per_page`.

Create order body:

```json
{
  "notes": "Leave at the door",
  "items": [
    {"product_name": "Cement", "quantity": 2, "price": "10.25"}
  ]
}
```

`total_amount` sent by the client is ignored. The stored total is the sum of `quantity * price`.

Payment body:

```json
{
  "method": "credit_card",
  "data": {"card_number": "4242424242424242"}
}
```

The charged amount is the order total.

## Assumptions

- One successful payment is allowed per order. A failed attempt can be retried.
- The charged amount is always the current order total, never a client-supplied amount.
- Orders are created as `pending`. `pending` can become `confirmed` or `cancelled`. `confirmed` can become `cancelled`. Other transitions return 409.
- Any payment, including a failed one, blocks deletion with 409.
- Item changes are blocked only after a successful payment. Notes can still be updated.
- Another user's order or payment returns 403. An unknown id returns 404.
- Gateway failures still create a payment row and return 201 with `status: failed`.
- Credit card and PayPal gateways are deterministic simulators. Env credentials are injected for a future live integration and are not sent to a third party.
- JWT blacklist uses the cache. Local `.env` uses the `file` cache so logout and refresh invalidate tokens without a cache table. Tests use the `array` cache.
- The application database is MySQL. PHPUnit forces SQLite `:memory:`.
