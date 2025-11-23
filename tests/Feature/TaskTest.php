<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Enums\PriorityEnum;
use App\Enums\StatusEnum;
use Spatie\Permission\Models\Role;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'user', 'guard_name' => 'api']);
    }

    public function test_user_can_create_task(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->postJson('/api/tasks', [
            'title' => 'Test Task',
            'description' => 'This is a test task',
            'priority' => PriorityEnum::Medium->value,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'title' => 'Test Task',
                    'description' => 'This is a test task',
                    'priority' => PriorityEnum::Medium->value,
                ]
            ]);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Test Task',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_list_tasks(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        Task::factory()->count(3)->create([
            'user_id' => $user->id,
        ]);

        $response = $this->auth($token)->getJson('/api/tasks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'created_at',
                    ]
                ]
            ]);
    }

    public function test_user_can_update_task(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->auth($token)->putJson("/api/tasks/{$task->id}", [
            'title' => 'Updated Task',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'title' => 'Updated Task',
                ]
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Task',
        ]);
    }

    public function test_user_can_delete_task(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->auth($token)->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_user_can_toggle_task_completion(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => StatusEnum::Todo->value,
        ]);

        $response = $this->auth($token)->putJson("/api/tasks/{$task->id}", [
            'status' => StatusEnum::Completed->value,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $task->id,
                    'status' => StatusEnum::Completed->value,
                ]
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => StatusEnum::Completed->value,
        ]);
    }

    public function test_user_cannot_update_another_users_task(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token = $this->loginAsUser($user1);

        $task = Task::factory()->create([
            'user_id' => $user2->id,
        ]);

        $response = $this->auth($token)->putJson("/api/tasks/{$task->id}", [
            'title' => 'Updated Task',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_another_users_task(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token = $this->loginAsUser($user1);

        $task = Task::factory()->create([
            'user_id' => $user2->id,
        ]);

        $response = $this->auth($token)->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_completion_of_another_users_task(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $token = $this->loginAsUser($user1);

        $task = Task::factory()->create([
            'user_id' => $user2->id,
            'status' => StatusEnum::Todo->value,
        ]);

        $response = $this->auth($token)->putJson("/api/tasks/{$task->id}", [
            'status' => StatusEnum::Completed->value,
        ]);

        $response->assertStatus(403);
    }
}
