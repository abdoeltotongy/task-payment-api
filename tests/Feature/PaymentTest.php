<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentGatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExplodingPaymentGateway;
use Tests\Support\ReferencePaymentGateway;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function confirmedOrder(User $user, array $overrides = []): Order
{
    return Order::factory()->confirmed()->create(array_merge([
        'user_id' => $user->id,
        'total_amount' => '25.50',
    ], $overrides));
}

it('rejects a payment when the order is not confirmed', function () {
    $user = User::factory()->create();
    $pending = Order::factory()->create(['user_id' => $user->id, 'total_amount' => '10.00']);
    $cancelled = Order::factory()->cancelled()->create(['user_id' => $user->id, 'total_amount' => '10.00']);

    foreach ([$pending, $cancelled] as $order) {
        $this->postJson('/api/orders/'.$order->id.'/payments', [
            'method' => 'credit_card',
            'data' => ['card_number' => '4242424242424242'],
        ], asUser($user))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payments can only be processed for confirmed orders.')
            ->assertJsonStructure(['message', 'errors']);
    }
});

it('records a successful credit card payment for the server total', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $response = $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'amount' => '1.00',
        'data' => ['card_number' => '4242424242424242'],
    ], asUser($user));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'successful')
        ->assertJsonPath('data.amount', '25.50')
        ->assertJsonPath('data.method', 'credit_card')
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.gateway_response.message', 'Payment approved.')
        ->assertJsonMissingPath('data.is_successful');

    expect($response->json('data.transaction_id'))->toBe('cc_'.$response->json('data.id'));
});

it('records a failed credit card payment and allows a later success', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $failed = $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242424242420000'],
    ], asUser($user));

    $failed->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.transaction_id', null);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242424242424242'],
    ], asUser($user))
        ->assertCreated()
        ->assertJsonPath('data.status', 'successful');
});

it('records successful and failed paypal payments', function () {
    $user = User::factory()->create();
    $paid = confirmedOrder($user);
    $declined = confirmedOrder($user);

    $success = $this->postJson('/api/orders/'.$paid->id.'/payments', [
        'method' => 'paypal',
        'data' => ['email' => 'ada@example.com'],
    ], asUser($user));

    $success->assertCreated()->assertJsonPath('data.status', 'successful');
    expect($success->json('data.transaction_id'))->toBe('pp_'.$success->json('data.id'));

    $this->postJson('/api/orders/'.$declined->id.'/payments', [
        'method' => 'paypal',
        'data' => ['email' => 'fail@example.com'],
    ], asUser($user))
        ->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.gateway_response.message', 'PayPal payment declined.');
});

it('rejects a second successful payment', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242424242424242'],
    ], asUser($user))->assertCreated();

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'paypal',
        'data' => ['email' => 'ada@example.com'],
    ], asUser($user))
        ->assertStatus(409)
        ->assertJsonPath('message', 'A successful payment already exists for this order.')
        ->assertJsonStructure(['message', 'errors']);
});

it('returns 422 for an unknown or disabled payment method', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'wire',
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['method']]);

    config(['payment.gateways.paypal.enabled' => false]);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'paypal',
        'data' => ['email' => 'ada@example.com'],
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['method']]);
});

it('stops another user from paying or reading a payment', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $order = confirmedOrder($owner);
    $payment = Payment::factory()->successful()->create([
        'order_id' => $order->id,
        'amount' => '25.50',
    ]);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242424242424242'],
    ], asUser($intruder))->assertForbidden();

    $this->getJson('/api/orders/'.$order->id.'/payments', asUser($intruder))->assertForbidden();
    $this->getJson('/api/payments/'.$payment->id, asUser($intruder))
        ->assertForbidden()
        ->assertJsonStructure(['message', 'errors']);

    $this->getJson('/api/payments/'.$payment->id, asUser($owner))
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id);
});

it('lists payments with status and method filters', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);
    Payment::factory()->successful()->create(['order_id' => $order->id, 'method' => 'credit_card']);
    Payment::factory()->failed()->create(['order_id' => $order->id, 'method' => 'paypal']);
    Payment::factory()->successful()->create();

    $this->getJson('/api/payments?per_page=1', asUser($user))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/payments?status=failed&method=paypal', asUser($user))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.method', 'paypal');

    $this->getJson('/api/orders/'.$order->id.'/payments?per_page=1', asUser($user))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(1, 'data');
});

it('returns 401 and 404 for payment routes', function () {
    $user = User::factory()->create();

    $this->getJson('/api/payments')->assertUnauthorized()->assertJsonStructure(['message', 'errors']);
    $this->getJson('/api/payments/999999', asUser($user))
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

it('returns 422 when gateway data is missing or invalid and stores no payment', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => [],
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The data.card number field is required.')
        ->assertJsonStructure(['message', 'errors' => ['data.card_number']]);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242'],
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['data.card_number']]);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'paypal',
        'data' => ['email' => 'not-an-email'],
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['data.email']]);

    $this->assertDatabaseCount('payments', 0);
});

it('does not store the full card number', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);

    $response = $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'credit_card',
        'data' => ['card_number' => '4242424242424242'],
    ], asUser($user));

    $response->assertCreated()
        ->assertJsonPath('data.gateway_response.raw.card_last4', '4242');

    expect($response->getContent())->not->toContain('4242424242424242');
});

it('stores a failed payment and returns 500 when the gateway throws', function () {
    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });

    $user = User::factory()->create();
    $order = confirmedOrder($user);
    app(PaymentGatewayManager::class)->extend('exploding', ExplodingPaymentGateway::class);

    $response = $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'exploding',
    ], asUser($user));

    $response->assertStatus(500)
        ->assertJsonPath('message', 'The payment provider could not complete the payment.')
        ->assertJsonMissingPath('data.status');

    expect($response->getContent())->not->toContain('exploded');

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->is_successful)->toBeNull()
        ->and($payment->gateway_response['message'] ?? null)->not->toContain('exploded');

    $encoded = json_encode($logged);
    expect($encoded)->toBeString()
        ->and($encoded)->toContain(RuntimeException::class)
        ->and($encoded)->not->toContain('exploded');
});

it('accepts a gateway registered at runtime without a payment service change', function () {
    $user = User::factory()->create();
    $order = confirmedOrder($user);
    app(PaymentGatewayManager::class)->extend('reference', ReferencePaymentGateway::class, [
        'bank_name' => 'Demo Bank',
    ]);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'reference',
    ], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['data.reference']]);

    $this->assertDatabaseCount('payments', 0);

    $this->postJson('/api/orders/'.$order->id.'/payments', [
        'method' => 'reference',
        'data' => ['reference' => 'ABC123'],
    ], asUser($user))
        ->assertCreated()
        ->assertJsonPath('data.method', 'reference')
        ->assertJsonPath('data.status', 'successful')
        ->assertJsonPath('data.amount', '25.50')
        ->assertJsonPath('data.gateway_response.message', 'Transfer accepted.')
        ->assertJsonPath('data.gateway_response.raw.bank_name', 'Demo Bank')
        ->assertJsonPath('data.gateway_response.raw.reference', 'ABC123');
});

it('allows many failed payments but only one successful payment row', function () {
    $order = Order::factory()->confirmed()->create();
    Payment::factory()->failed()->create(['order_id' => $order->id]);
    Payment::factory()->failed()->create(['order_id' => $order->id]);
    Payment::factory()->successful()->create(['order_id' => $order->id]);

    expect(fn () => Payment::factory()->successful()->create(['order_id' => $order->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});
