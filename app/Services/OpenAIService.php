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
     * @param array $messageHistory Previous messages for context (last 5)
     * @param array|null $toolCalls Raw tool calls from previous response
     * @param array|null $toolResults Results from executed functions
     * @param array|null $context Additional context (e.g., creator info)
     * @return array ['response' => string, 'function_calls' => array|null]
     */
    public function chat(string $userMessage, Collection $tasks, array $messageHistory = [], ?array $toolCalls = null, ?array $toolResults = null, ?array $context = null): array
    {
        $systemPrompt = $this->buildSystemPrompt($tasks, $context);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];

        // Add message history if provided (for conversation context)
        if (!empty($messageHistory)) {
            $messages = array_merge($messages, $messageHistory);
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        // If we have tool results, add them to the conversation
        if ($toolCalls && $toolResults) {
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolResults as $result) {
                // Validate tool result structure
                if (!is_array($result)) {
                    \Log::warning('Invalid tool result format: not an array', ['result' => $result]);
                    continue;
                }

                if (!isset($result['tool_call_id']) || !isset($result['result'])) {
                    \Log::warning('Invalid tool result format: missing required keys', [
                        'has_tool_call_id' => isset($result['tool_call_id']),
                        'has_result' => isset($result['result']),
                        'result' => $result
                    ]);
                    continue;
                }

                if (empty($result['tool_call_id'])) {
                    \Log::warning('Invalid tool result: empty tool_call_id', ['result' => $result]);
                    continue;
                }

                // Ensure result is serializable
                try {
                    $serializedResult = json_encode($result['result']);
                    if ($serializedResult === false) {
                        throw new \JsonException('Failed to serialize result');
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to serialize tool result', [
                        'error' => $e->getMessage(),
                        'result' => $result['result']
                    ]);
                    continue;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['tool_call_id'],
                    'content' => $serializedResult,
                ];
            }
        }

        $requestParams = [
            'model' => config('openai.model'),
            'max_completion_tokens' => config('openai.max_tokens'),
            'messages' => $messages,
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
                'raw_tool_calls' => $message->toolCalls,
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
                            'status' => [
                                'type' => 'string',
                                'enum' => ['todo', 'in_progress', 'completed'],
                                'description' => 'Task status: "todo" (not started), "in_progress" (currently working on it), or "completed" (finished)',
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'unarchive_task',
                    'description' => 'Unarchive a task to restore it. Note: archived tasks are not shown in the user\'s current task list.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task_title' => [
                                'type' => 'string',
                                'description' => 'The EXACT title of the task to unarchive',
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
    private function buildSystemPrompt(Collection $tasks, ?array $context = null): string
    {
        $taskContext = $this->formatTasksForAI($tasks);

        $basePrompt = "You are an intelligent task management assistant with the ability to perform actions on behalf of the user.

**User's Current Tasks:**
{$taskContext}";

        // Add creator context if provided
        if ($context && isset($context['creator_info'])) {
            $creatorInfo = $context['creator_info'];
            $basePrompt .= "\n\n**Special Information:**\n";
            $basePrompt .= $creatorInfo['note'] . "\n";
            $basePrompt .= "Creator Name: {$creatorInfo['name']}\n";
            $basePrompt .= "LinkedIn Profile URL: {$creatorInfo['linkedin']}\n";
            $basePrompt .= "\n**CRITICAL INSTRUCTIONS FOR SHARING LINKEDIN PROFILE:**\n";
            $basePrompt .= "1. ALWAYS share the LinkedIn link when:\n";
            $basePrompt .= "   - User explicitly asks for it ('linkedin', 'profile', 'connect')\n";
            $basePrompt .= "   - User asks about the creator/developer ('who created you', 'who made you')\n";
            $basePrompt .= "   - User shows ANY interest after you mention the creator ('yes', 'sure', 'ok', 'tell me more', 'i am', 'curious')\n";
            $basePrompt .= "   - User asks to know more, learn more, or get more information\n";
            $basePrompt .= "2. When sharing, use this EXACT markdown format:\n";
            $basePrompt .= "   [{$creatorInfo['linkedin']}]({$creatorInfo['linkedin']})\n";
            $basePrompt .= "3. NEVER say you 'cannot access the internet' or 'cannot browse links'\n";
            $basePrompt .= "4. NEVER say you 'don't have access to the profile'\n";
            $basePrompt .= "5. You ALREADY HAVE this information - it's provided to you above\n";
            $basePrompt .= "6. Be enthusiastic and helpful when sharing the profile\n";
            $basePrompt .= "7. Example good responses:\n";
            $basePrompt .= "   - 'Here's Patrick's LinkedIn profile: [link]'\n";
            $basePrompt .= "   - 'Absolutely! Check out his LinkedIn: [link]'\n";
            $basePrompt .= "   - 'You can connect with him here: [link]'\n";
        }

        $basePrompt .= "\n\n**Your capabilities:**
- CREATE new tasks when user asks
- UPDATE existing tasks (change title, priority, due date, status)
- DELETE tasks when user requests
- ARCHIVE tasks to hide them from the active list
- UNARCHIVE tasks to restore them to the active list
- Analyze and provide insights about tasks
- Answer questions about task management

**Task Status Options:**
Tasks have three status levels:
1. **todo**: Not started yet (synonyms: pending, not started, haven't done this, to-do)
2. **in_progress**: Currently working on it (synonyms: doing, working on, in progress, started)
3. **completed**: Finished (synonyms: done, finished, complete, completed)

**Important Instructions:**
- USE THE FUNCTIONS provided for all task operations
- For task updates/deletes/archives, use the EXACT task title from the list above
- UNDERSTAND natural language and synonyms - don't require exact keywords

**Natural Language Understanding Guide:**
When user says... → Use this status:
- 'mark as done', 'complete it', 'finish it', 'I finished this' → status='completed'
- 'I'm working on it', 'doing it', 'start it', 'in progress', 'move to doing' → status='in_progress'
- 'mark as todo', 'not started', 'haven't done', 'pending', 'reset it' → status='todo'
- 'delete X', 'remove X' → DELETE it
- 'archive X', 'hide X' → ARCHIVE it
- 'unarchive X', 'restore X' → UNARCHIVE it

**Action Examples:**
- 'add a task', 'create reminder', 'new todo' → CREATE it
- 'change priority to high', 'make it urgent' → UPDATE with priority='high'
- 'due tomorrow', 'deadline next week' → UPDATE with due_date

**Always:**
- Be proactive and interpret user intent
- Confirm actions clearly: 'I've marked X as completed', 'I've moved X to in progress', etc.
- Be concise and friendly";

        return $basePrompt;
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
            // Display status with icons
            $statusIcon = match($task->status) {
                'completed' => '✓',
                'in_progress' => '▶',
                'todo' => '○',
                default => '○'
            };
            $statusText = match($task->status) {
                'completed' => 'Completed',
                'in_progress' => 'In Progress',
                'todo' => 'To-Do',
                default => 'To-Do'
            };
            $status = "{$statusIcon} {$statusText}";
            $isCompleted = $task->status === 'completed';
            $priority = strtoupper($task->priority ?? 'medium');
            $category = $task->category ? " [{$task->category->name}]" : '';
            $dueDate = $task->due_date ? " (Due: {$task->due_date})" : '';

            // Check if overdue
            $overdue = '';
            if ($task->due_date && !$isCompleted) {
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
