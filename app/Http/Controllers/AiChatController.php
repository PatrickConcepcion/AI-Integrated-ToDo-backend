<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiChatRequest;
use App\Services\OpenAIService;
use App\Models\Task;
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
     * Handle AI chat request with function calling support
     */
    public function chat(AiChatRequest $request): JsonResponse
    {
        try {
            // 1. Get authenticated user's tasks
            $tasks = Task::where('user_id', Auth::id())
                ->with('category')
                ->whereNull('archived_at')
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

            // 4. Get last 10 messages for context (increased for better conversation memory)
            $recentMessages = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->reverse()
                ->values();

            // 5. Format message history for OpenAI
            $messageHistory = $recentMessages->map(function ($msg) {
                return [
                    'role' => $msg->is_ai_response ? 'assistant' : 'user',
                    'content' => $msg->content,
                ];
            })->toArray();

            // 6. Check if user is asking about creator or LinkedIn
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

            // 7. Send message to OpenAI with history and context
            $aiResult = $this->openAIService->chat(
                $request->validated()['message'],
                $tasks,
                $messageHistory,
                null,
                null,
                $context
            );

            // 7. Check if AI wants to perform actions
            if ($aiResult['function_calls']) {
                $results = $this->executeFunctions($aiResult['function_calls']);

                // Refresh tasks after actions
                $tasks = Task::where('user_id', Auth::id())
                    ->with('category')
                    ->whereNull('archived_at')
                    ->orderBy('due_date', 'asc')
                    ->orderBy('priority', 'desc')
                    ->get();

                // Send results back to AI to generate natural response
                $finalResult = $this->openAIService->chat(
                    $request->validated()['message'],
                    $tasks,
                    $messageHistory,
                    $aiResult['raw_tool_calls'],
                    $results,
                    $context
                );

                // Prepare response content with fallback
                $responseContent = $finalResult['response'];

                // If AI didn't provide a response, generate one from function results
                if (empty($responseContent)) {
                    $responseContent = $this->generateFallbackResponse($results);
                }

                // Store AI's response
                Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $responseContent,
                    'is_ai_response' => true,
                ]);

                return response()->json([
                    'success' => true,
                    'response' => $responseContent,
                ]);
            }

            // 8. No actions, store and return text response
            $responseContent = $aiResult['response'] ?? 'I understand, but I need more information to help you.';

            // Only store if we have content
            if (!empty($responseContent)) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $responseContent,
                    'is_ai_response' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'response' => $responseContent,
            ]);

        } catch (\OpenAI\Exceptions\ErrorException $e) {
            Log::error('OpenAI API Error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'message' => $request->input('message'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'OpenAI API Error: ' . $e->getMessage(),
            ], 503);

        } catch (\Exception $e) {
            Log::error('AI Chat Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
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

                    case 'archive_task':
                        $result = $this->archiveTask($arguments);
                        break;

                    case 'unarchive_task':
                        $result = $this->unarchiveTask($arguments);
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
            'message' => "✅ Created task: \"{$task->title}\"" .
                         ($task->due_date ? " (Due: {$task->due_date})" : '') .
                         ($task->priority ? " [Priority: {$task->priority}]" : ''),
        ];
    }

    /**
     * Update an existing task
     */
    private function updateTask(array $args): array
    {
        // Find task by exact title match
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->whereNull('archived_at')
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
            $newStatus = $args['status'];
            $task->status = $newStatus;

            // Update completed_at timestamp based on status
            if ($newStatus === 'completed') {
                $task->completed_at = $task->completed_at ?? now();
                $updated[] = 'marked as completed';
            } else {
                $task->completed_at = null;
                if ($newStatus === 'in_progress') {
                    $updated[] = 'status changed to in progress';
                } else if ($newStatus === 'todo') {
                    $updated[] = 'status changed to to-do';
                }
            }
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
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->whereNull('archived_at')
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
     * Archive a task
     */
    private function archiveTask(array $args): array
    {
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->whereNull('archived_at')
            ->first();

        if (!$task) {
            return [
                'success' => false,
                'message' => "❌ Task not found: \"{$args['task_title']}\"",
            ];
        }

        $task->archived_at = now();
        $task->save();

        return [
            'success' => true,
            'action' => 'archive',
            'task_id' => $task->id,
            'message' => "✅ Archived task: \"{$task->title}\"",
        ];
    }

    /**
     * Unarchive a task
     */
    private function unarchiveTask(array $args): array
    {
        $task = Task::where('user_id', Auth::id())
            ->where('title', $args['task_title'])
            ->whereNotNull('archived_at')
            ->first();

        if (!$task) {
            return [
                'success' => false,
                'message' => "❌ Archived task not found: \"{$args['task_title']}\"",
            ];
        }

        $task->archived_at = null;
        $task->save();

        return [
            'success' => true,
            'action' => 'unarchive',
            'task_id' => $task->id,
            'message' => "✅ Unarchived task: \"{$task->title}\"",
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
            if (isset($result['result']['message'])) {
                $messages[] = $result['result']['message'];
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
