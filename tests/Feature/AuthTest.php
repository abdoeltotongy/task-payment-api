<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a user and returns a bearer token', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Registered successfully.')
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('token_type', 'bearer')
        ->assertJsonStructure([
            'message',
            'data' => ['id', 'name', 'email', 'created_at'],
            'access_token',
            'token_type',
            'expires_in',
        ]);

    expect($response->json('data'))->not->toHaveKey('password');
    expect($response->json('access_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('expires_in'))->toBeInt()->toBeGreaterThan(0);

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    expect(User::query()->where('email', 'ada@example.com')->firstOrFail()->password)
        ->not->toBe('password123');
});

it('returns 422 when registration data is invalid', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
    ]);

    $response->assertUnprocessable()
        ->assertJsonStructure([
            'message',
            'errors' => ['name', 'email', 'password'],
        ]);
});

it('returns 422 when the email is already registered', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['email']]);
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Logged in successfully.')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('token_type', 'bearer');

    expect($response->json('access_token'))->toBeString()->not->toBeEmpty();
});

it('returns 401 when credentials are invalid', function () {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid credentials.')
        ->assertJsonStructure(['message', 'errors']);
});

it('returns the authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->getJson('/api/auth/me', [
        'Authorization' => 'Bearer '.tokenFor($user),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);

    expect($response->json('data'))->not->toHaveKey('password');
});

it('returns 401 when the token is missing', function () {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message', 'errors']);
});

it('returns 401 when the token is invalid', function () {
    $this->getJson('/api/auth/me', [
        'Authorization' => 'Bearer not-a-valid-token',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.')
        ->assertJsonStructure(['message', 'errors']);
});

it('logs out and rejects the previous token', function () {
    $user = User::factory()->create();
    $token = tokenFor($user);

    $this->postJson('/api/auth/logout', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    resetAuthState();

    $this->getJson('/api/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertUnauthorized();
});

it('refreshes the token and rejects the previous one', function () {
    $user = User::factory()->create();
    $token = tokenFor($user);

    $response = $this->postJson('/api/auth/refresh', [], [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Token refreshed successfully.')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('token_type', 'bearer');

    $newToken = $response->json('access_token');

    expect($newToken)->toBeString()->not->toBe($token);

    resetAuthState();

    $this->getJson('/api/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertUnauthorized();

    resetAuthState();

    $this->getJson('/api/auth/me', [
        'Authorization' => 'Bearer '.$newToken,
    ])->assertOk()
        ->assertJsonPath('data.id', $user->id);
});
