<?php

use App\Models\AnnouncementCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function announcementCategoryAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    return $user;
}

test('admin can list, create, update, and deactivate announcement categories', function () {
    $admin = announcementCategoryAdmin();
    $token = $admin->createToken('admin-device')->plainTextToken;

    AnnouncementCategory::query()->create(['name' => 'General', 'status' => 'active']);
    AnnouncementCategory::query()->create(['name' => 'Old News', 'status' => 'inactive']);

    $this->withToken($token)
        ->getJson('/api/announcement-categories?status=active')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'General');

    $createResponse = $this->withToken($token)
        ->postJson('/api/announcement-categories', [
            'name' => 'Finance',
            'description' => 'Finance related announcements',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Finance')
        ->assertJsonPath('data.status', 'active');

    $categoryId = $createResponse->json('data.id');

    $this->withToken($token)
        ->putJson("/api/announcement-categories/{$categoryId}", [
            'name' => 'Finance Updates',
            'description' => 'Updated description',
            'status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Finance Updates')
        ->assertJsonPath('data.description', 'Updated description');

    $this->withToken($token)
        ->deleteJson("/api/announcement-categories/{$categoryId}")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('message', 'Announcement category deactivated successfully.');

    $this->assertDatabaseHas('announcement_categories', [
        'id' => $categoryId,
        'status' => 'inactive',
    ]);
});

test('announcement category name must be unique', function () {
    $admin = announcementCategoryAdmin();
    $token = $admin->createToken('admin-device')->plainTextToken;

    AnnouncementCategory::query()->create(['name' => 'General', 'status' => 'active']);

    $this->withToken($token)
        ->postJson('/api/announcement-categories', ['name' => 'General'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('employees without permission cannot manage announcement categories', function () {
    $employee = User::factory()->create(['status' => 'active']);
    $employee->assignRole('employee');
    $token = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/announcement-categories')
        ->assertForbidden();

    $this->withToken($token)
        ->postJson('/api/announcement-categories', ['name' => 'New Category'])
        ->assertForbidden();
});
