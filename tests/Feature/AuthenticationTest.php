<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'user', 'guard_name' => 'api']);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);
    }

    public function test_user_can_get_own_profile(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                ],
                'message' => 'User profile retrieved successfully.',
            ]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully logged out.',
            ]);
    }

    public function test_admin_can_login_and_access_profile(): void
    {
        // Create admin role
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        // Test login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);

        // Test profile access with admin role
        $token = $this->loginAsUser($admin);

        $response = $this->auth($token)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $admin->id,
                    'email' => $admin->email,
                    'roles' => ['admin'],
                ],
            ]);
    }
}
