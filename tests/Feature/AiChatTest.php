<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\OpenAIService;
use App\Enums\PriorityEnum;
use App\Enums\StatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'user', 'guard_name' => 'api']);
    }

    // ==========================================
    // AUTHENTICATION TESTS
    // ==========================================

    public function test_unauthenticated_user_cannot_access_chat(): void
    {
        $response = $this->postJson('/api/ai/chat', [
            'message' => 'Hello AI',
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_get_messages(): void
    {
        $response = $this->getJson('/api/ai/messages');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_clear_conversation(): void
    {
        $response = $this->deleteJson('/api/ai/conversations');

        $response->assertStatus(401);
    }

    // ==========================================
    // VALIDATION TESTS
    // ==========================================

    public function test_chat_requires_message(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->postJson('/api/ai/chat', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_chat_message_cannot_be_empty(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_chat_message_cannot_exceed_max_length(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_chat_message_must_be_string(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => ['invalid' => 'array'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    // ==========================================
    // SUCCESSFUL STREAMING TESTS
    // ==========================================

    public function test_chat_returns_streamed_response(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        // Mock the OpenAI service to return a simple stream
        $this->mockOpenAIServiceWithTextResponse('Hello! How can I help you today?');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Hello',
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    }

    public function test_chat_creates_conversation_if_not_exists(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->assertDatabaseMissing('conversations', ['user_id' => $user->id]);

        $this->mockOpenAIServiceWithTextResponse('Hello!');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Hello',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('conversations', ['user_id' => $user->id]);
    }

    public function test_chat_stores_user_message(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithTextResponse('Hello!');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Test message from user',
        ]);

        $response->assertStatus(200);

        $conversation = Conversation::where('user_id', $user->id)->first();
        $this->assertNotNull($conversation);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Test message from user',
            'is_ai_response' => false,
        ]);
    }

    public function test_chat_stores_ai_response_after_streaming(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithTextResponse('This is the AI response');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Hello AI',
        ]);

        $response->assertStatus(200);

        // Get the streamed content to trigger message storage
        $content = $response->streamedContent();

        $conversation = Conversation::where('user_id', $user->id)->first();

        // The AI response should be stored
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'This is the AI response',
            'is_ai_response' => true,
        ]);
    }

    // ==========================================
    // TOOL CALL EXECUTION TESTS
    // ==========================================

    public function test_chat_can_create_task_via_tool_call(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithToolCall('create_task', [
            'title' => 'New Task from AI',
            'description' => 'Task created by AI assistant',
            'priority' => 'high',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Create a task called New Task from AI',
        ]);

        $response->assertStatus(200);
        $response->streamedContent();

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'New Task from AI',
            'description' => 'Task created by AI assistant',
            'priority' => 'high',
        ]);
    }

    public function test_chat_can_update_task_via_tool_call(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'Existing Task',
            'priority' => PriorityEnum::Low->value,
        ]);

        $this->mockOpenAIServiceWithToolCall('update_task', [
            'task_title' => 'Existing Task',
            'priority' => 'high',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Change Existing Task to high priority',
        ]);

        $response->assertStatus(200);
        $response->streamedContent();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'priority' => 'high',
        ]);
    }

    public function test_chat_can_complete_task_via_tool_call(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'Task to Complete',
            'status' => StatusEnum::Todo->value,
        ]);

        $this->mockOpenAIServiceWithToolCall('update_task', [
            'task_title' => 'Task to Complete',
            'status' => 'completed',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Mark Task to Complete as done',
        ]);

        $response->assertStatus(200);
        $response->streamedContent();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => StatusEnum::Completed->value,
        ]);
    }

    public function test_chat_can_delete_task_via_tool_call(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'Task to Delete',
        ]);

        $this->mockOpenAIServiceWithToolCall('delete_task', [
            'task_title' => 'Task to Delete',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Delete Task to Delete',
        ]);

        $response->assertStatus(200);
        $response->streamedContent();

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
        ]);
    }

    // ==========================================
    // ERROR HANDLING TESTS
    // ==========================================

    public function test_chat_handles_openai_exception_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithException(new \Exception('OpenAI API Error'));

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Hello',
        ]);

        // Should still return 200 because it's a streamed response
        $response->assertStatus(200);

        // The stream should contain a generic error message (not exposing internal details)
        // and the [DONE] signal to properly terminate the stream
        $content = $response->streamedContent();
        $this->assertStringContainsString('Something went wrong', $content);
        $this->assertStringNotContainsString('OpenAI API Error', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_handles_update_nonexistent_task_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithToolCall('update_task', [
            'task_title' => 'Nonexistent Task',
            'priority' => 'high',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Update Nonexistent Task',
        ]);

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Should complete without crashing
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_handles_delete_nonexistent_task_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithToolCall('delete_task', [
            'task_title' => 'Nonexistent Task',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Delete Nonexistent Task',
        ]);

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Should complete without crashing
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_handles_invalid_status_value_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test Task',
            'status' => StatusEnum::Todo->value,
        ]);

        $this->mockOpenAIServiceWithToolCall('update_task', [
            'task_title' => 'Test Task',
            'status' => 'invalid_status',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Change status to invalid',
        ]);

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Should complete without crashing
        $this->assertStringContainsString('[DONE]', $content);

        // Task status should remain unchanged
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => StatusEnum::Todo->value,
        ]);
    }

    public function test_chat_handles_unknown_function_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithToolCall('unknown_function', [
            'some_arg' => 'value',
        ]);

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Do something unknown',
        ]);

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Should complete without crashing
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_handles_malformed_tool_arguments_gracefully(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithMalformedToolCall();

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Create a task',
        ]);

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Should complete without crashing
        $this->assertStringContainsString('[DONE]', $content);
    }

    // ==========================================
    // MESSAGE PERSISTENCE TESTS
    // ==========================================

    public function test_conversation_message_history_is_used_for_context(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        // Create existing conversation with messages
        $conversation = Conversation::create(['user_id' => $user->id]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Previous user message',
            'is_ai_response' => false,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Previous AI response',
            'is_ai_response' => true,
        ]);

        $this->mockOpenAIServiceWithTextResponse('New response');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'New message',
        ]);

        $response->assertStatus(200);

        // Consume stream to trigger message storage
        $response->streamedContent();

        // Should have 4 messages now (2 existing + 1 new user + 1 new AI)
        $this->assertEquals(4, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_empty_ai_response_is_not_stored(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithTextResponse('');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Hello',
        ]);

        $response->assertStatus(200);
        $response->streamedContent();

        $conversation = Conversation::where('user_id', $user->id)->first();

        // Only user message should be stored, not empty AI response
        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'is_ai_response' => true,
        ]);
    }

    // ==========================================
    // GET MESSAGES TESTS
    // ==========================================

    public function test_user_can_get_conversation_messages(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $conversation = Conversation::create(['user_id' => $user->id]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'User message',
            'is_ai_response' => false,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'AI response',
            'is_ai_response' => true,
        ]);

        $response = $this->auth($token)->getJson('/api/ai/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'messages' => [
                    '*' => [
                        'id',
                        'content',
                        'is_ai_response',
                        'created_at',
                    ]
                ]
            ]);

        $this->assertCount(2, $response->json('messages'));
    }

    public function test_get_messages_returns_empty_array_if_no_conversation(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $response = $this->auth($token)->getJson('/api/ai/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => [],
            ]);
    }

    public function test_user_cannot_see_other_users_messages(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['user_id' => $user1->id]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'User1 message',
            'is_ai_response' => false,
        ]);

        $token = $this->loginAsUser($user2);

        $response = $this->auth($token)->getJson('/api/ai/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'messages' => [],
            ]);
    }

    // ==========================================
    // CLEAR CONVERSATION TESTS
    // ==========================================

    public function test_user_can_clear_conversation(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $conversation = Conversation::create(['user_id' => $user->id]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Message to delete',
            'is_ai_response' => false,
        ]);

        $response = $this->auth($token)->deleteJson('/api/ai/conversations');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Conversation cleared successfully',
            ]);

        // Old conversation should be deleted
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);

        // A new conversation should be created
        $this->assertDatabaseHas('conversations', ['user_id' => $user->id]);

        // Messages should be deleted
        $this->assertDatabaseMissing('messages', [
            'content' => 'Message to delete',
        ]);
    }

    public function test_clear_conversation_creates_new_one_if_none_exists(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->assertDatabaseMissing('conversations', ['user_id' => $user->id]);

        $response = $this->auth($token)->deleteJson('/api/ai/conversations');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('conversations', ['user_id' => $user->id]);
    }

    public function test_user_cannot_clear_other_users_conversation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['user_id' => $user1->id]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'User1 message',
            'is_ai_response' => false,
        ]);

        $token = $this->loginAsUser($user2);

        $response = $this->auth($token)->deleteJson('/api/ai/conversations');

        $response->assertStatus(200);

        // User1's conversation should still exist
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
        ]);
    }

    // ==========================================
    // CREATOR CONTEXT TESTS
    // ==========================================

    public function test_chat_detects_creator_question(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithTextResponse('I was created by Patrick Marcon Concepcion!');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'Who created you?',
        ]);

        $response->assertStatus(200);
    }

    public function test_chat_detects_linkedin_question(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAsUser($user);

        $this->mockOpenAIServiceWithTextResponse('Here is the LinkedIn profile!');

        $response = $this->auth($token)->postJson('/api/ai/chat', [
            'message' => 'What is your linkedin?',
        ]);

        $response->assertStatus(200);
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Mock OpenAI service to return a simple text response
     */
    private function mockOpenAIServiceWithTextResponse(string $responseText): void
    {
        $this->instance(
            OpenAIService::class,
            Mockery::mock(OpenAIService::class, function (MockInterface $mock) use ($responseText) {
                $mock->shouldReceive('chatStream')
                    ->andReturn($this->createMockStream($responseText));
            })
        );
    }

    /**
     * Mock OpenAI service to return a tool call
     */
    private function mockOpenAIServiceWithToolCall(string $functionName, array $arguments): void
    {
        $this->instance(
            OpenAIService::class,
            Mockery::mock(OpenAIService::class, function (MockInterface $mock) use ($functionName, $arguments) {
                $mock->shouldReceive('chatStream')
                    ->once()
                    ->andReturn($this->createMockStreamWithToolCall($functionName, $arguments));

                // Second call returns the follow-up text response
                $mock->shouldReceive('chatStream')
                    ->andReturn($this->createMockStream('Done!'));
            })
        );
    }

    /**
     * Mock OpenAI service to throw an exception
     */
    private function mockOpenAIServiceWithException(\Exception $exception): void
    {
        $this->instance(
            OpenAIService::class,
            Mockery::mock(OpenAIService::class, function (MockInterface $mock) use ($exception) {
                $mock->shouldReceive('chatStream')
                    ->andThrow($exception);
            })
        );
    }

    /**
     * Mock OpenAI service to return malformed tool call arguments
     */
    private function mockOpenAIServiceWithMalformedToolCall(): void
    {
        $this->instance(
            OpenAIService::class,
            Mockery::mock(OpenAIService::class, function (MockInterface $mock) {
                $mock->shouldReceive('chatStream')
                    ->once()
                    ->andReturn($this->createMockStreamWithMalformedToolCall());

                // Second call returns the follow-up text response
                $mock->shouldReceive('chatStream')
                    ->andReturn($this->createMockStream('Sorry, I encountered an issue.'));
            })
        );
    }

    /**
     * Create a mock stream that yields text content chunks
     */
    private function createMockStream(string $content): \Generator
    {
        // Split content into chunks to simulate streaming
        $chunks = str_split($content, 5);

        foreach ($chunks as $chunk) {
            yield (object) [
                'choices' => [
                    (object) [
                        'delta' => (object) [
                            'content' => $chunk,
                        ],
                    ],
                ],
            ];
        }
    }

    /**
     * Create a mock stream that yields a tool call
     */
    private function createMockStreamWithToolCall(string $functionName, array $arguments): \Generator
    {
        $argumentsJson = json_encode($arguments);

        // First chunk: tool call ID and function name
        yield (object) [
            'choices' => [
                (object) [
                    'delta' => (object) [
                        'toolCalls' => [
                            (object) [
                                'index' => 0,
                                'id' => 'call_' . uniqid(),
                                'function' => (object) [
                                    'name' => $functionName,
                                    'arguments' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Second chunk: arguments (streaming in parts)
        yield (object) [
            'choices' => [
                (object) [
                    'delta' => (object) [
                        'toolCalls' => [
                            (object) [
                                'index' => 0,
                                'function' => (object) [
                                    'arguments' => $argumentsJson,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Create a mock stream with malformed (invalid JSON) tool call arguments
     */
    private function createMockStreamWithMalformedToolCall(): \Generator
    {
        // First chunk: tool call ID and function name
        yield (object) [
            'choices' => [
                (object) [
                    'delta' => (object) [
                        'toolCalls' => [
                            (object) [
                                'index' => 0,
                                'id' => 'call_' . uniqid(),
                                'function' => (object) [
                                    'name' => 'create_task',
                                    'arguments' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Second chunk: malformed arguments (invalid JSON)
        yield (object) [
            'choices' => [
                (object) [
                    'delta' => (object) [
                        'toolCalls' => [
                            (object) [
                                'index' => 0,
                                'function' => (object) [
                                    'arguments' => '{invalid json: missing quotes}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

