<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiChatRequest;
use App\Services\OpenAIService;
use App\Models\Task;
use App\Enums\StatusEnum;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AiChatController extends Controller
{
    private OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Handle AI chat request with function calling support and streaming
     */
    public function chat(AiChatRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // 1. Get authenticated user's tasks (Pre-fetch outside stream)
        $tasks = Task::where('user_id', Auth::id())
            ->with('category')
            ->where('status', '!=', StatusEnum::Archived->value)
            ->orderBy('due_date', 'asc')
            ->orderBy('priority', 'desc')
            ->get();

        // 2. Find or create user's conversation
        $conversation = Conversation::firstOrCreate(['user_id' => Auth::id()]);

        // 3. Store user's message
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => $request->validated()['message'],
            'is_ai_response' => false,
        ]);

        // 4. Get last 10 messages for context
        $recentMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        // 5. Format message history
        $messageHistory = $recentMessages->map(function ($msg) {
            return [
                'role' => $msg->is_ai_response ? 'assistant' : 'user',
                'content' => $msg->content,
            ];
        })->toArray();

        // 6. Context check
        $context = null;
        if ($this->isAskingAboutCreator($request->validated()['message']) || $this->isAskingAboutLinkedIn($request->validated()['message'])) {
            $context = [
                'creator_info' => [
                    'name' => 'Patrick Marcon Concepcion',
                    'linkedin' => 'https://www.linkedin.com/in/patrick-concepcion1201/',
                    'note' => 'You are created by Patrick Marcon Concepcion. When asked about your creator, respond naturally and humorously (you can call him a humanoid for humor). When asked for the LinkedIn profile or if user shows interest, provide the LinkedIn URL directly as a clickable link in markdown format: [https://www.linkedin.com/in/patrick-concepcion1201/](https://www.linkedin.com/in/patrick-concepcion1201/). You have access to this information and SHOULD share it when asked.'
                ]
            ];
        }

        $userMessage = $request->validated()['message'];
        $userId = Auth::id();

        return response()->stream(function () use ($userMessage, $tasks, $messageHistory, $context, $conversation, $userId) {
            $fullResponse = '';
            $toolCalls = [];
            
            try {
                // Initial Stream
                $stream = $this->openAIService->chatStream(
                    $userMessage,
                    $tasks,
                    $messageHistory,
                    null,
                    null,
                    $context
                );

                foreach ($stream as $response) {
                    $delta = $response->choices[0]->delta;

                    // Handle Tool Calls
                    if ($delta->toolCalls) {
                        foreach ($delta->toolCalls as $toolCallChunk) {
                            $index = $toolCallChunk->index;
                            
                            if (!isset($toolCalls[$index])) {
                                $toolCalls[$index] = [
                                    'id' => $toolCallChunk->id ?? '',
                                    'name' => $toolCallChunk->function->name ?? '',
                                    'arguments' => '',
                                ];
                            }
                            
                            if (isset($toolCallChunk->function->arguments)) {
                                $toolCalls[$index]['arguments'] .= $toolCallChunk->function->arguments;
                            }
                        }
                    }

                    // Handle Content
                    if ($delta->content) {
                        $fullResponse .= $delta->content;
                        echo "data: " . json_encode(['chunk' => $delta->content]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }

                // If we have tool calls, execute them and stream the follow-up
                if (!empty($toolCalls)) {
                    // Notify client we are processing actions
                    echo "data: " . json_encode(['type' => 'status', 'message' => 'Processing actions...']) . "\n\n";
                    ob_flush();
                    flush();

                    // Execute functions
                    $results = $this->executeFunctions($toolCalls);

                    // Re-fetch tasks to get updated state
                    $updatedTasks = Task::where('user_id', $userId)
                        ->with('category')
                        ->where('status', '!=', StatusEnum::Archived->value)
                        ->orderBy('due_date', 'asc')
                        ->orderBy('priority', 'desc')
                        ->get();

                    // Prepare raw tool calls for the API (needs exact structure)
                    $rawToolCalls = [];
                    foreach ($toolCalls as $index => $tc) {
                        $rawToolCalls[] = [
                            'id' => $tc['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'],
                                'arguments' => $tc['arguments']
                            ]
                        ];
                    }

                    // Second Stream (with tool results)
                    $followUpStream = $this->openAIService->chatStream(
                        $userMessage,
                        $updatedTasks,
                        $messageHistory,
                        $rawToolCalls,
                        $results,
                        $context
                    );

                    foreach ($followUpStream as $response) {
                        $delta = $response->choices[0]->delta;
                        
                        if ($delta->content) {
                            $fullResponse .= $delta->content;
                            echo "data: " . json_encode(['chunk' => $delta->content]) . "\n\n";
                            ob_flush();
                            flush();
                        }
                    }

                    // If still no content (fallback), generate one
                    if (empty($fullResponse)) {
                        $fallback = $this->generateFallbackResponse($results);
                        $fullResponse = $fallback;
                        echo "data: " . json_encode(['chunk' => $fallback]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }

                // End of stream
                echo "data: [DONE]\n\n";
                ob_flush();
                flush();

                // Save full response to database
                if (!empty($fullResponse)) {
                    Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $fullResponse,
                        'is_ai_response' => true,
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Streaming Error', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId
                ]);
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Execute function calls from AI
     *
     * @param array $functionCalls Array of function calls with id, name and arguments
     * @return array Array of results with tool_call_id and result
     */
    private function executeFunctions(array $functionCalls): array
    {
        $results = [];

        foreach ($functionCalls as $call) {
            $functionName = $call['name'];
            $arguments = $call['arguments'];

            try {
                switch ($functionName) {
                    case 'create_task':
                        $result = $this->createTask($arguments);
                        break;

                    case 'update_task':
                        $result = $this->updateTask($arguments);
                        break;

                    case 'delete_task':
                        $result = $this->deleteTask($arguments);
                        break;

                    default:
                        $result = ['success' => false, 'error' => "Unknown function: {$functionName}"];
                }

                $results[] = [
                    'tool_call_id' => $call['id'],
                    'result' => $result,
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'tool_call_id' => $call['id'],
                    'result' => [
                        'success' => false,
                        'error' => "Failed to {$functionName}: " . $e->getMessage(),
                    ],
                ];
                Log::error("Function execution error: {$functionName}", [
                    'error' => $e->getMessage(),
                    'arguments' => $arguments,
                ]);
            }
        }

        return $results;
    }

    /**
     * Create a new task
     */
    private function createTask(array $args): array
    {
        $task = Task::create([
            'user_id' => Auth::id(),
            'title' => $args['title'],
            'description' => $args['description'] ?? null,
            'priority' => $args['priority'] ?? 'medium',
            'due_date' => $args['due_date'] ?? null,
            'category_id' => $args['category_id'] ?? null,
        ]);

        return [
            'success' => true,
            'action' => 'create',
            'task_id' => $task->id,
            // Ensure we convert enum instances to string values when building messages
            'message' => "✅ Created task: \"{$task->title}\"" .
                         ($task->due_date ? " (Due: {$task->due_date})" : '') .
                         ((isset($task->priority) && $task->priority instanceof \App\Enums\PriorityEnum) ? " [Priority: {$task->priority->value}]" : ($task->priority ? " [Priority: {$task->priority}]" : '')),
        ];
    }

    /**
     * Update an existing task
     */
    private function updateTask(array $args): array
    {
        // Find task by exact title match (including archived tasks so they can be unarchived)
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->first();

        if (!$task) {
            return [
                'success' => false,
                'message' => "❌ Task not found: \"{$args['task_title']}\"",
            ];
        }

        // Update fields that were provided
        $updated = [];
        if (isset($args['title']) && $args['title'] !== $task->title) {
            $task->title = $args['title'];
            $updated[] = 'title';
        }
        if (isset($args['description'])) {
            $task->description = $args['description'];
            $updated[] = 'description';
        }
        if (isset($args['priority'])) {
            $task->priority = $args['priority'];
            $updated[] = 'priority';
        }
        if (isset($args['due_date'])) {
            $task->due_date = $args['due_date'];
            $updated[] = 'due date';
        }
        if (isset($args['status'])) {
            try {
                $newStatus = StatusEnum::from($args['status']);
            } catch (\ValueError $e) {
                return [
                    'success' => false,
                    'message' => "❌ Invalid status value: \"{$args['status']}\"",
                ];
            }

            $task->transitionToStatus($newStatus);

            $updated[] = match ($newStatus) {
                StatusEnum::Archived => 'archived',
                StatusEnum::Completed => 'marked as completed',
                StatusEnum::InProgress => 'status changed to in progress',
                StatusEnum::Todo => 'status changed to to-do',
            };
        }

        $task->save();

        return [
            'success' => true,
            'action' => 'update',
            'task_id' => $task->id,
            'message' => "✅ Updated task: \"{$args['task_title']}\"" .
                         (count($updated) > 0 ? " (" . implode(', ', $updated) . ")" : ''),
        ];
    }

    /**
     * Delete a task
     */
    private function deleteTask(array $args): array
    {
        // Find task by exact title match (including archived tasks)
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->first();

        if (!$task) {
            return [
                'success' => false,
                'message' => "❌ Task not found: \"{$args['task_title']}\"",
            ];
        }

        $title = $task->title;
        $task->delete();

        return [
            'success' => true,
            'action' => 'delete',
            'message' => "✅ Deleted task: \"{$title}\"",
        ];
    }

    /**
     * Generate a fallback response when AI doesn't provide one
     * Uses the function execution results to create a natural response
     */
    private function generateFallbackResponse(array $results): string
    {
        if (empty($results)) {
            return 'Action completed successfully.';
        }

        $messages = [];
        foreach ($results as $result) {
            // Extract message from result
            if (isset($result['result']['message'])) {
                $messages[] = $result['result']['message'];
            } elseif (isset($result['result']['success']) && $result['result']['success']) {
                // Fallback: create message from action type
                $action = $result['result']['action'] ?? 'action';
                $messages[] = "✅ " . ucfirst($action) . " completed successfully";
            }
        }

        if (empty($messages)) {
            return 'Done! I\'ve completed the requested actions.';
        }

        // Return all action messages combined
        return implode("\n", $messages);
    }

    /**
     * Clear user's conversation history (hard delete)
     */
    public function clearConversation(): JsonResponse
    {
        try {
            $conversation = Conversation::where('user_id', Auth::id())->first();

            if ($conversation) {
                // Hard delete will cascade to messages
                $conversation->delete();
            }

            // Create a new conversation for the user
            Conversation::create(['user_id' => Auth::id()]);

            return response()->json([
                'success' => true,
                'message' => 'Conversation cleared successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Clear Conversation Error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to clear conversation',
            ], 500);
        }
    }

    /**
     * Get user's conversation message history
     */
    public function getMessages(): JsonResponse
    {
        try {
            $conversation = Conversation::where('user_id', Auth::id())->first();

            if (!$conversation) {
                return response()->json([
                    'success' => true,
                    'messages' => [],
                ]);
            }

            $messages = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($msg) {
                    return [
                        'id' => $msg->id,
                        'content' => $msg->content,
                        'is_ai_response' => $msg->is_ai_response,
                        'created_at' => $msg->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'messages' => $messages,
            ]);

        } catch (\Exception $e) {
            Log::error('Get Messages Error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve messages',
            ], 500);
        }
    }

    /**
     * Check if message is asking about creator/developer
     */
    private function isAskingAboutCreator(string $message): bool
    {
        $lowerMessage = strtolower($message);
        $creatorKeywords = [
            'who created you',
            'who made you',
            'who built you',
            'who developed you',
            'who programmed you',
            'who is your creator',
            'who is your developer',
            'who is your maker',
            'your creator',
            'your developer',
            'your maker',
            'who\'s your creator',
            'who\'s your developer',
        ];

        foreach ($creatorKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is asking about LinkedIn profile
     */
    private function isAskingAboutLinkedIn(string $message): bool
    {
        $lowerMessage = strtolower($message);
        $linkedInKeywords = [
            'linkedin',
            'linked in',
            'profile',
            'connect with',
            'social media',
            'professional profile',
        ];

        foreach ($linkedInKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
