# Extendable Order and Payment Management API

Laravel 11 REST API for orders and pluggable payment gateways. Order totals are calculated on the server in integer cents. A new payment method is a gateway class plus configuration. Controllers and `PaymentService` do not need gateway-specific conditions.

## Setup

Requirements: PHP 8.2+, Composer, and MySQL. Tests use SQLite in memory.

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

`php artisan jwt:secret` writes `JWT_SECRET`. Leave the payment credential values empty. Credit card and PayPal are simulators and do not call an external provider.

Demo accounts created by the seeder:

| Email | Password |
|---|---|
| demo@example.com | password |
| other@example.com | password |

`demo@example.com` owns a pending order, a confirmed order with a successful card payment, confirmed orders with declined card and PayPal payments, and a cancelled order. `other@example.com` owns a separate pending order.

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
- API errors use `{"message": "...", "errors": {...}}` with 401, 403, 404, 409, 422, and 500.
- Unexpected server errors are logged by exception class and returned as `Server error.` The response does not include a stack trace or the original exception text.
- Payment methods are not hardcoded. `ProcessPaymentRequest` allows enabled keys from `config/payment.php` and merges that gateway's `rules()`. `PaymentGatewayManager` resolves the class from the container.

### Payment flow

1. The order must belong to the user. Someone else's order returns 403.
2. Gateway `rules()` validate `data`. Missing or invalid provider fields return Laravel's 422 response and no payment row is created.
3. A short database transaction locks the order. The order must be `confirmed`, otherwise `OrderNotConfirmedException` (422). An existing successful payment raises `PaymentAlreadyProcessedException` (409). The transaction then stores a `pending` payment for the server-calculated total and commits.
4. The manager calls `PaymentGatewayInterface::process()` after the lock is released.
5. A second short transaction stores the outcome:
   - Provider approval becomes `successful` and returns 201.
   - Provider decline becomes `failed` and returns 201. A failed attempt can be retried.
   - If the order was cancelled, or another payment succeeded, this attempt is stored as `failed` and the API returns 422 or 409. It is not reported as successful.
6. If the gateway throws, the attempt is stored as `failed`, the exception class is logged without the provider payload, and the API returns 500 with `The payment provider could not complete the payment.`

`pending` means the provider has not answered yet. A finished request does not return `pending`.

Simulated rules:

- Credit card: `data.card_number` is 13 to 19 digits. A number ending in `0000` is declined. Only the last four digits are stored.
- PayPal: `data.email` must be an email address. An address containing `fail` is declined.

These gateways do not send the configured merchant id, API key, client id, or client secret anywhere.

## How to add a new payment gateway

1. Create a class that implements `PaymentGatewayInterface`.
2. Add one entry under `gateways` in `config/payment.php`.
3. Add the env keys that entry reads.

No controller, `PaymentService`, or shared validation class change is required. The form request reads `rules()` from the resolved gateway. A test can also call `PaymentGatewayManager::extend()` without editing config files.

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

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:3', 'max:40'],
        ];
    }

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $reference = (string) ($data['reference'] ?? '');

        return new GatewayResult(
            success: true,
            transactionId: 'bt_'.$payment->id,
            message: 'Transfer accepted.',
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

`POST /api/orders/{order}/payments` then accepts `"method": "bank_transfer"` with `data.reference`. An empty reference returns 422 before a payment row is created.

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

`total_amount` sent by the client is ignored. The stored total is the sum of `quantity * price`, calculated in cents.

Payment body:

```json
{
  "method": "credit_card",
  "data": {"card_number": "4242424242424242"}
}
```

The charged amount is the order total at the moment the attempt starts.

## Assumptions

- One successful payment is allowed per order. The payments table enforces this with a unique `(order_id, is_successful)` index. `is_successful` is true only for an approved payment and null otherwise, so failed attempts do not collide. The column is not part of the API response.
- The charged amount is always the order total captured when the attempt starts, never a client-supplied amount.
- Orders are created as `pending`. `pending` can become `confirmed` or `cancelled`. `confirmed` can become `cancelled` only when the order has no successful payment. Other transitions return 409.
- Cancelling a paid order returns 409 with `A paid order cannot be cancelled.` There is no refund flow, so a successful payment is not reversed by a status change.
- Any payment, including a failed or pending one, blocks deletion with 409. The foreign key also restricts deleting an order that still has payments. Deletion locks the order row so it cannot race with payment creation.
- Item changes are blocked after a successful payment and while a payment is still `pending`, because that attempt already snapshotted the total. Notes can still be updated.
- Another user's order or payment returns 403. An unknown id returns 404.
- A gateway decline still creates a payment row and returns 201 with `status: failed`.
- A gateway exception does not return 201. The row is stored as `failed` and the response is 500.
- Credit card and PayPal gateways are deterministic simulators. Env credentials are injected for a future live integration and are not sent to a third party.
- JWT blacklist uses the cache. Local `.env` uses the `file` cache so logout and refresh invalidate tokens without a cache table. Tests use the `array` cache.
- The application database is MySQL. PHPUnit forces SQLite `:memory:`.
- Status values are database enums. Non-negative prices and totals are enforced by validation and integer-cent arithmetic. This Laravel schema builder has no portable `CHECK` helper, so amount floors are not separate database checks.
