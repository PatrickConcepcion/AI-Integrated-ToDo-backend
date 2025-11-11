<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Collection;

class OpenAIService
{
    private $client;

    public function __construct()
    {
        $this->client = OpenAI::client(config('openai.api_key'));
    }

    /**
     * Send chat request to OpenAI with function calling support
     *
     * @param string $userMessage The user's question or request
     * @param Collection $tasks The user's tasks from database
     * @return array ['response' => string, 'function_calls' => array|null]
     */
    public function chat(string $userMessage, Collection $tasks): array
    {
        $systemPrompt = $this->buildSystemPrompt($tasks);

        // Temporarily disable tools to test if model supports them
        $requestParams = [
            'model' => config('openai.model'),
            'max_completion_tokens' => config('openai.max_tokens'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userMessage,
                ],
            ],
        ];

        // Check if model supports function calling
        $modelSupportsTools = !str_contains(config('openai.model'), 'nano');

        if ($modelSupportsTools) {
            $requestParams['tools'] = $this->getToolDefinitions();
            $requestParams['tool_choice'] = 'auto';
        }

        $response = $this->client->chat()->create($requestParams);

        $message = $response->choices[0]->message;

        // Debug logging
        \Log::info('OpenAI Response', [
            'message' => $message,
            'content' => $message->content ?? null,
            'toolCalls' => $message->toolCalls ?? null,
        ]);

        // Check if AI wants to call functions
        if (isset($message->toolCalls) && count($message->toolCalls) > 0) {
            $functionCalls = [];
            foreach ($message->toolCalls as $toolCall) {
                $functionCalls[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->function->name,
                    'arguments' => json_decode($toolCall->function->arguments, true),
                ];
            }

            return [
                'response' => null,
                'function_calls' => $functionCalls,
            ];
        }

        // No function calls, return text response
        return [
            'response' => $message->content ?? '',
            'function_calls' => null,
        ];
    }

    /**
     * Define available functions (tools) for the AI
     *
     * These tell the AI what actions it can perform
     */
    private function getToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_task',
                    'description' => 'Create a new task for the user',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'The task title/name',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional description of the task',
                            ],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['low', 'medium', 'high'],
                                'description' => 'Task priority level',
                            ],
                            'due_date' => [
                                'type' => 'string',
                                'description' => 'Due date in YYYY-MM-DD format',
                            ],
                            'category_id' => [
                                'type' => 'integer',
                                'description' => 'Category ID if user specified a category',
                            ],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_task',
                    'description' => 'Update an existing task. Use the exact task title from the user\'s task list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task_title' => [
                                'type' => 'string',
                                'description' => 'The EXACT title of the task to update (must match existing task)',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'New title for the task',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'New description',
                            ],
                            'priority' => [
                                'type' => 'string',
                                'enum' => ['low', 'medium', 'high'],
                                'description' => 'New priority',
                            ],
                            'due_date' => [
                                'type' => 'string',
                                'description' => 'New due date in YYYY-MM-DD format',
                            ],
                            'completed' => [
                                'type' => 'boolean',
                                'description' => 'Mark as completed (true) or incomplete (false)',
                            ],
                        ],
                        'required' => ['task_title'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'delete_task',
                    'description' => 'Delete a task. Use the exact task title from the user\'s task list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task_title' => [
                                'type' => 'string',
                                'description' => 'The EXACT title of the task to delete',
                            ],
                        ],
                        'required' => ['task_title'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'archive_task',
                    'description' => 'Archive a task. Use the exact task title from the user\'s task list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task_title' => [
                                'type' => 'string',
                                'description' => 'The EXACT title of the task to archive',
                            ],
                        ],
                        'required' => ['task_title'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build system prompt with user's task context
     */
    private function buildSystemPrompt(Collection $tasks): string
    {
        $taskContext = $this->formatTasksForAI($tasks);

        return "You are an intelligent task management assistant with the ability to perform actions on behalf of the user.

**User's Current Tasks:**
{$taskContext}

**Your capabilities:**
- CREATE new tasks when user asks
- UPDATE existing tasks (change title, priority, due date, mark complete)
- DELETE tasks when user requests
- ARCHIVE completed tasks
- Analyze and provide insights about tasks
- Answer questions about task management

**Important Instructions:**
- When creating/updating/deleting tasks, USE THE FUNCTIONS provided
- For task updates/deletes, use the EXACT task title from the list above
- Be proactive: if user says 'add a task', 'create reminder', 'new todo' - CREATE it
- If user says 'mark X as done', 'complete X', 'finish X' - UPDATE it
- If user says 'delete X', 'remove X' - DELETE it
- Always confirm actions: 'I've created...', 'I've updated...', 'I've deleted...'
- Be concise and friendly";
    }

    /**
     * Format user's tasks into readable context for AI
     */
    private function formatTasksForAI(Collection $tasks): string
    {
        if ($tasks->isEmpty()) {
            return "No tasks yet.";
        }

        $formatted = [];

        foreach ($tasks as $task) {
            $status = $task->completed ? '✓ Completed' : '○ Pending';
            $priority = strtoupper($task->priority ?? 'medium');
            $category = $task->category ? " [{$task->category->name}]" : '';
            $dueDate = $task->due_date ? " (Due: {$task->due_date})" : '';

            // Check if overdue
            $overdue = '';
            if ($task->due_date && !$task->completed) {
                $dueDateTime = new \DateTime($task->due_date);
                $now = new \DateTime();
                if ($dueDateTime < $now) {
                    $overdue = ' ⚠️ OVERDUE';
                }
            }

            $formatted[] = "{$status} [{$priority}]{$category} {$task->title}{$dueDate}{$overdue}";

            if ($task->description) {
                $formatted[] = "  Description: {$task->description}";
            }
        }

        return implode("\n", $formatted);
    }
}
