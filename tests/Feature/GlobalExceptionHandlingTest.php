<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('auth:sanctum')
        ->get('/api/test-exceptions/authenticated', fn () => response()->json([
            'success' => true,
        ]));

    Route::get('/api/test-exceptions/bad-request', function () {
        throw new BadRequestHttpException('Malformed payload');
    });

    Route::get('/api/test-exceptions/forbidden', function () {
        throw new AuthorizationException('Restricted action');
    });

    Route::middleware('throttle:1,1')
        ->get('/api/test-exceptions/throttle', fn () => response()->json([
            'success' => true,
        ]));

    Route::get('/api/test-exceptions/server-error', function () {
        throw new RuntimeException('Sensitive server secret should not leak');
    });
});

test('unauthenticated api requests return the global 401 response format', function () {
    $this->getJson('/api/test-exceptions/authenticated')
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'errors' => [],
        ]);
});

test('bad requests return the global 400 response format', function () {
    $this->getJson('/api/test-exceptions/bad-request')
        ->assertStatus(400)
        ->assertExactJson([
            'success' => false,
            'message' => 'Bad request.',
            'errors' => [],
        ]);
});

test('forbidden actions return the global 403 response format', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/test-exceptions/forbidden')
        ->assertForbidden()
        ->assertExactJson([
            'success' => false,
            'message' => 'Forbidden.',
            'errors' => [],
        ]);
});

test('missing api routes return the global 404 response format', function () {
    $this->getJson('/api/route-that-does-not-exist')
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'message' => 'Resource not found.',
            'errors' => [],
        ]);
});

test('validation failures return the global 422 response format', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors(['email', 'password']);
});

test('throttled requests return the global 429 response format', function () {
    $this->getJson('/api/test-exceptions/throttle')
        ->assertSuccessful();

    $this->getJson('/api/test-exceptions/throttle')
        ->assertStatus(429)
        ->assertExactJson([
            'success' => false,
            'message' => 'Too many requests.',
            'errors' => [],
        ]);
});

test('server errors return the global 500 response format without leaking sensitive details', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/test-exceptions/server-error');

    $response
        ->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Server error.',
            'errors' => [],
        ]);

    expect($response->getContent())->not->toContain('Sensitive server secret should not leak');
});
