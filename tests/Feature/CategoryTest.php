<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create roles for testing
        Role::create(['name' => 'user', 'guard_name' => 'api']);
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
    }

    public function test_user_can_list_categories(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $token = $this->loginAsUser($user);

        Category::factory()->count(3)->create();

        $response = $this->auth($token)
            ->getJson('/api/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'color',
                        'created_at',
                        'updated_at',
                    ]
                ]
            ]);
    }

    public function test_user_can_view_category(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $token = $this->loginAsUser($user);

        $category = Category::factory()->create();

        $response = $this->auth($token)
            ->getJson("/api/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]
            ]);
    }

    public function test_admin_can_create_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->loginAsUser($admin);

        $response = $this->auth($token)
            ->postJson('/api/categories', [
                'name' => 'New Category',
                'description' => 'Category Description',
                'color' => '#FF0000',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'name' => 'New Category',
                    'description' => 'Category Description',
                    'color' => '#FF0000',
                ]
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'New Category',
        ]);
    }

    public function test_admin_can_update_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->loginAsUser($admin);

        $category = Category::factory()->create();

        $response = $this->auth($token)
            ->putJson("/api/categories/{$category->id}", [
                'name' => 'Updated Category',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $category->id,
                    'name' => 'Updated Category',
                ]
            ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Category',
        ]);
    }

    public function test_admin_can_delete_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->loginAsUser($admin);

        $category = Category::factory()->create();

        $response = $this->auth($token)
            ->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_regular_user_cannot_create_category(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)
            ->postJson('/api/categories', [
                'name' => 'New Category',
            ]);

        $response->assertStatus(403);
    }

    public function test_regular_user_cannot_update_category(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $token = $this->loginAsUser($user);

        $category = Category::factory()->create();

        $response = $this->auth($token)
            ->putJson("/api/categories/{$category->id}", [
                'name' => 'Updated Category',
            ]);

        $response->assertStatus(403);
    }

    public function test_regular_user_cannot_delete_category(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $token = $this->loginAsUser($user);

        $category = Category::factory()->create();

        $response = $this->auth($token)
            ->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(403);
    }
}
