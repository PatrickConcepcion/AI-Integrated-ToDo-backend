<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiChatRequest;
use App\Services\OpenAIService;
use App\Models\Task;
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

            // 2. Send message to OpenAI
            $aiResult = $this->openAIService->chat(
                $request->validated()['message'],
                $tasks
            );

            // 3. Check if AI wants to perform actions
            if ($aiResult['function_calls']) {
                $results = $this->executeFunctions($aiResult['function_calls']);

                return response()->json([
                    'success' => true,
                    'response' => $results['message'],
                    'actions_performed' => $results['actions'],
                    'message' => 'Actions completed successfully.',
                ]);
            }

            // 4. No actions, just return text response
            return response()->json([
                'success' => true,
                'response' => $aiResult['response'],
                'message' => 'AI response generated successfully.',
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
     * @param array $functionCalls Array of function calls with name and arguments
     * @return array ['message' => string, 'actions' => array]
     */
    private function executeFunctions(array $functionCalls): array
    {
        $actions = [];
        $messages = [];

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

                    default:
                        $result = ['success' => false, 'message' => "Unknown function: {$functionName}"];
                }

                $actions[] = $result;
                $messages[] = $result['message'];

            } catch (\Exception $e) {
                $error = "Failed to {$functionName}: " . $e->getMessage();
                $actions[] = ['success' => false, 'message' => $error];
                $messages[] = $error;
                Log::error("Function execution error: {$functionName}", [
                    'error' => $e->getMessage(),
                    'arguments' => $arguments,
                ]);
            }
        }

        return [
            'message' => implode("\n", $messages),
            'actions' => $actions,
        ];
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
        if (isset($args['completed'])) {
            $task->completed = $args['completed'];
            $task->completed_at = $args['completed'] ? now() : null;
            $updated[] = $args['completed'] ? 'marked as completed' : 'marked as incomplete';
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
}
