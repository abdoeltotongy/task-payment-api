<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function sampleOrderPayload(): array
{
    return [
        'notes' => 'Leave at the door',
        'total_amount' => '1.00',
        'items' => [
            ['product_name' => 'Cement', 'quantity' => 2, 'price' => '10.25'],
            ['product_name' => 'Sand', 'quantity' => 1, 'price' => '3.00'],
        ],
    ];
}

it('creates an order and calculates the total on the server', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/orders', sampleOrderPayload(), asUser($user));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total_amount', '23.50')
        ->assertJsonPath('data.notes', 'Leave at the door')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonCount(2, 'data.items');

    $this->assertDatabaseHas('orders', [
        'user_id' => $user->id,
        'total_amount' => '23.50',
    ]);
});

it('returns 422 when an order has no items', function () {
    $user = User::factory()->create();

    $this->postJson('/api/orders', ['items' => []], asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['items']]);
});

it('lists only the authenticated user orders and filters by status', function () {
    $user = User::factory()->create();
    Order::factory()->count(2)->create(['user_id' => $user->id, 'status' => 'pending']);
    Order::factory()->confirmed()->create(['user_id' => $user->id]);
    Order::factory()->confirmed()->create();

    $this->getJson('/api/orders?per_page=2', asUser($user))
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/orders?status=confirmed', asUser($user))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'confirmed');
});

it('returns 422 when the order status filter is invalid', function () {
    $user = User::factory()->create();

    $this->getJson('/api/orders?status=shipped', asUser($user))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['status']]);
});

it('shows an order to its owner', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'notes' => 'Owner order']);

    $this->getJson('/api/orders/'.$order->id, asUser($user))
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.notes', 'Owner order');
});

it('rejects access to another user order', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->getJson('/api/orders/'.$order->id, asUser($intruder))
        ->assertForbidden()
        ->assertJsonStructure(['message', 'errors']);

    $this->putJson('/api/orders/'.$order->id, ['notes' => 'nope'], asUser($intruder))
        ->assertForbidden();

    $this->deleteJson('/api/orders/'.$order->id, [], asUser($intruder))
        ->assertForbidden();
});

it('returns 404 for a missing order and 401 without a token', function () {
    $user = User::factory()->create();

    $this->getJson('/api/orders/999999', asUser($user))
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonStructure(['message', 'errors']);

    resetAuthState();

    $this->getJson('/api/orders')->assertUnauthorized();
});

it('updates notes and replaces items with a recalculated total', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'total_amount' => '5.00', 'notes' => 'old']);

    $response = $this->putJson('/api/orders/'.$order->id, [
        'notes' => 'updated',
        'items' => [
            ['product_name' => 'Gravel', 'quantity' => 4, 'price' => '2.50'],
        ],
    ], asUser($user));

    $response->assertOk()
        ->assertJsonPath('data.notes', 'updated')
        ->assertJsonPath('data.total_amount', '10.00')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.product_name', 'Gravel');
});

it('blocks item edits after a successful payment and still allows notes', function () {
    $user = User::factory()->create();
    $order = Order::factory()->confirmed()->create(['user_id' => $user->id, 'total_amount' => '20.00']);
    Payment::factory()->successful()->create(['order_id' => $order->id, 'amount' => '20.00']);

    $this->putJson('/api/orders/'.$order->id, [
        'items' => [
            ['product_name' => 'Cement', 'quantity' => 1, 'price' => '5.00'],
        ],
    ], asUser($user))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Order items cannot be changed after a successful payment.')
        ->assertJsonStructure(['message', 'errors']);

    $this->putJson('/api/orders/'.$order->id, ['notes' => 'call before delivery'], asUser($user))
        ->assertOk()
        ->assertJsonPath('data.notes', 'call before delivery')
        ->assertJsonPath('data.total_amount', '20.00');
});

it('confirms and cancels an order through the allowed transitions', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'confirmed'], asUser($user))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'confirmed'], asUser($user))
        ->assertStatus(409)
        ->assertJsonStructure(['message', 'errors']);

    $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'pending'], asUser($user))
        ->assertUnprocessable();

    $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'cancelled'], asUser($user))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('deletes an order without payments', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $this->deleteJson('/api/orders/'.$order->id, [], asUser($user))
        ->assertNoContent();

    $this->assertDatabaseMissing('orders', ['id' => $order->id]);
});

it('returns 409 when deleting an order that has payments', function () {
    $user = User::factory()->create();
    $order = Order::factory()->confirmed()->create(['user_id' => $user->id]);
    Payment::factory()->failed()->create(['order_id' => $order->id]);

    $this->deleteJson('/api/orders/'.$order->id, [], asUser($user))
        ->assertStatus(409)
        ->assertJsonPath('message', 'Orders with payments cannot be deleted.')
        ->assertJsonStructure(['message', 'errors']);

    $this->assertDatabaseHas('orders', ['id' => $order->id]);
});
