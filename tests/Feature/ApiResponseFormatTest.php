<?php

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('/api/test-success-response', fn () => ApiResponse::success(
        data: ['hello' => 'world'],
        message: 'Action completed successfully',
    ));

    Route::get('/api/test-paginated-response', function () {
        $paginator = User::query()
            ->orderBy('id')
            ->paginate(2);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: collect($paginator->items())
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'email' => $user->email,
                ])
                ->all(),
            message: 'Data fetched successfully',
        );
    });
});

test('success responses use the global response format', function () {
    $this->getJson('/api/test-success-response')
        ->assertSuccessful()
        ->assertExactJson([
            'success' => true,
            'message' => 'Action completed successfully',
            'data' => [
                'hello' => 'world',
            ],
        ]);
});

test('validation errors use the global response format', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors(['email', 'password']);
});

test('paginated responses include the global meta structure', function () {
    User::factory()->count(3)->create();

    $this->getJson('/api/test-paginated-response')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Data fetched successfully')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);
});
