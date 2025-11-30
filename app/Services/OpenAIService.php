<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Collection;
use Carbon\Carbon;

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

        // If we just executed functions, force a text response instead of allowing more function calls
        if ($modelSupportsTools && !$toolResults) {
            $requestParams['tools'] = $this->getToolDefinitions();
            $requestParams['tool_choice'] = 'auto';
        } elseif ($modelSupportsTools && $toolResults) {
            // After function execution, include tools but force none to get text response
            $requestParams['tools'] = $this->getToolDefinitions();
            $requestParams['tool_choice'] = 'none';
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
                                'description' => 'Due date in YYYY-MM-DD format. Calculate relative dates (tomorrow, next week, 5 days from now, etc.) based on today\'s date provided in the system prompt.',
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
                                'description' => 'New due date in YYYY-MM-DD format. Calculate relative dates (tomorrow, next week, 5 days from now, etc.) based on today\'s date provided in the system prompt.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['todo', 'in_progress', 'completed', 'archived'],
                                'description' => 'Task status: "todo" (not started), "in_progress" (currently working on it), "completed" (finished), or "archived" (hidden from active list)',
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
        ];
    }

    /**
     * Build system prompt with user's task context
     */
    private function buildSystemPrompt(Collection $tasks, ?array $context = null): string
    {
        $taskContext = $this->formatTasksForAI($tasks);

        // Get current date information for date-aware AI responses
        $today = Carbon::now(); // e.g., "2025-11-17"
        $dayOfWeek = $today->format('l'); // e.g., "Sunday"

        $basePrompt = <<<EOT
You are an intelligent task management assistant with the ability to perform actions on behalf of the user.

**IMPORTANT: Today's Date Information**
Today is {$dayOfWeek}, {$today} (YYYY-MM-DD format).
When users mention relative dates like 'tomorrow', '5 days from now', 'next week', 'next Monday', etc., you MUST calculate the exact date in YYYY-MM-DD format based on today's date.

**About This Application:**
This application was built using modern web technologies:
- Backend: Laravel 12 (PHP framework)
- Frontend: Vue 3 (JavaScript framework)
- Database: MySQL
- Styling: Tailwind CSS
- Form Validation: Vee-validate
- HTTP Client: Axios
- AI Integration: OpenAI API

**User's Current Tasks:**
{$taskContext}
EOT;

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

        $basePrompt .= <<<EOT


**Your capabilities:**
- CREATE new tasks when user asks
- UPDATE existing tasks (change title, priority, due date, status)
- DELETE tasks when user requests
- Analyze and provide insights about tasks
- Answer questions about task management

**Task Status Options:**
Tasks have four status levels:
1. **todo**: Not started yet (synonyms: pending, not started, haven't done this, to-do)
2. **in_progress**: Currently working on it (synonyms: doing, working on, in progress, started)
3. **completed**: Finished (synonyms: done, finished, complete, completed)
4. **archived**: Hidden from active list (synonyms: archive, hide, store away, remove from list)

**CRITICAL: Status Transition Rules:**
- You can change a task to ANY status from ANY other status - there are NO restrictions!
- Tasks can be archived regardless of whether they are todo, in_progress, or completed
- Tasks can be unarchived back to any status
- Tasks can move from completed to in_progress, from in_progress to todo, etc. 
- NEVER tell the user a status change is not allowed - just do it!

**Important Instructions:**
- USE THE FUNCTIONS provided for all task operations
- For task updates/deletes, use the EXACT task title from the list above
- UNDERSTAND natural language and synonyms - don't require exact keywords
- DO NOT make up restrictions that don't exist in the system

**CRITICAL: LANGUAGE & LOCALIZATION**
**YOU MUST FOLLOW THESE RULES EXACTLY - THE COMMAND LANGUAGE ALWAYS WINS!**

1. **ANALYZE THE MESSAGE STRUCTURE:**
   - Split the message into: [Greeting/Interjection] + [Command/Question/Content]
   - The **Greeting** is typically at the start (e.g., "Hello", "Ohayo", "Hola", "Nako! Hala!")
   - The **Command** is everything after the greeting that asks for something or gives an instruction

2. **DETECT THE COMMAND LANGUAGE (MOST IMPORTANT!):**
   - Look at the COMMAND portion ONLY (ignore the greeting!)
   - **Japanese Romaji Detection**: If you see words like "tasukete", "kudasai", "onegai", "arigatou", "sumimasen", "gomen", "dozo", "yoroshiku", "matte", "chotto" -> This is JAPANESE!
   - **Tagalog/Filipino Detection**: If you see words like "kamusta", "tulungan", "pakiusap", "salamat", "nako", "hala" (when not just interjections) -> This is TAGALOG/FILIPINO!
   - **English Detection**: Standard English words
   - **Spanish Detection**: Spanish words
   
3. **DETECT GREETING-ONLY LANGUAGE:**
   - **Japanese Romaji Greetings**: "ohayo", "konnichiwa", "konbanwa", "sayonara", "oyasumi", "itterasshai", "tadaima", "okaeri" -> This is JAPANESE!
   - **English Greetings**: "hello", "hi", "hey", "good morning", "good evening" -> This is ENGLISH!
   - **Spanish Greetings**: "hola", "buenos dias", "buenas tardes" -> This is SPANISH!
   - **Tagalog Greetings**: "kamusta", "magandang umaga", "magandang hapon" -> This is TAGALOG!

4. **DETERMINE RESPONSE LANGUAGE - COMMAND ALWAYS WINS:**
   - **IF there is a Command/Question after the greeting:**
     - **USE THE COMMAND'S LANGUAGE - COMPLETELY IGNORE THE GREETING LANGUAGE!**
     - Example: "Hello! Tasukete kudasai!" -> Command = "Tasukete kudasai" (JAPANESE ROMAJI) -> **RESPOND ENTIRELY IN JAPANESE**
     - Example: "Ohayo! Help me with this." -> Command = "Help me with this" (ENGLISH) -> **RESPOND ENTIRELY IN ENGLISH**
     - Example: "Konnichiwa. Tasukete kudasai!" -> Command = "Tasukete kudasai" (JAPANESE ROMAJI) -> **RESPOND ENTIRELY IN JAPANESE**
     - Example: "Nako! Hala! Delete this task." -> Command = "Delete this task" (ENGLISH) -> **RESPOND ENTIRELY IN ENGLISH**
   
   - **IF there is ONLY a greeting/interjection with NO command:**
     - Use the greeting's language based on the detection rules above
     - Example: "Ohayo!" -> Detected as JAPANESE greeting -> **RESPOND ENTIRELY IN JAPANESE**
     - Example: "Konnichiwa!" -> Detected as JAPANESE greeting -> **RESPOND ENTIRELY IN JAPANESE**
     - Example: "Hello!" -> Detected as ENGLISH greeting -> **RESPOND ENTIRELY IN ENGLISH**
     - Example: "Kamusta!" -> Detected as TAGALOG greeting -> **RESPOND ENTIRELY IN TAGALOG**

5. **EXECUTE:** Your ENTIRE response must be in the detected language. NO mixing languages!

**Natural Language Understanding Guide:**
When user says... → Use this status in update_task:
- 'mark as done', 'complete it', 'finish it', 'I finished this' → status='completed'
- 'I'm working on it', 'doing it', 'start it', 'in progress', 'move to doing' → status='in_progress'
- 'mark as todo', 'not started', 'haven't done', 'pending', 'reset it' → status='todo'
- 'archive X', 'hide X', 'put away X', 'remove from list X' → status='archived'
- 'unarchive X', 'restore X', 'bring back X' → status to its previous_status (if available)
- 'put it back to the previous status', 'what was it before' → reference previous_status field
- 'delete X', 'remove X' → DELETE it (permanently removes)

**Status History Tracking:**
Every task maintains a `previous_status` field that tracks the last status it was in. You can reference this to:
- Help users restore tasks to their previous state
- Answer questions about what a task was before
- Provide better context when making status changes

**Action Examples:**
- 'add a task', 'create reminder', 'new todo' → CREATE it
- 'change priority to high', 'make it urgent' → UPDATE with priority='high'
- 'due tomorrow', 'deadline next week' → UPDATE with due_date
- 'move to doing', 'start this', 'begin working on it' → UPDATE with status='in_progress'

**Always:**
- Be proactive and interpret user intent
- Confirm actions clearly: 'I've marked X as completed', 'I've archived X', 'I've moved X to in progress', etc.
- Be concise and friendly
EOT;

        return $basePrompt;
    }

    /**
     * Format user's tasks into readable context for AI
     * @param Collection<int, Task> $tasks
     */
    private function formatTasksForAI(Collection $tasks): string
    {
        if ($tasks->isEmpty()) {
            return "No tasks yet.";
        }

        $formatted = [];

        foreach ($tasks as $task) {
            // Get string values from enums
            $statusValue = $task->status instanceof \App\Enums\StatusEnum ? $task->status->value : $task->status;
            $priorityValue = $task->priority instanceof \App\Enums\PriorityEnum ? $task->priority->value : ($task->priority ?? 'medium');

            // Display status with icons
            $statusIcon = match ($statusValue) {
                'completed' => '✓',
                'in_progress' => '▶',
                'todo' => '○',
                default => '○'
            };
            $statusText = match ($statusValue) {
                'completed' => 'Completed',
                'in_progress' => 'In Progress',
                'todo' => 'To-Do',
                default => 'To-Do'
            };
            $status = "{$statusIcon} {$statusText}";
            $isCompleted = $statusValue === 'completed';
            $priority = strtoupper($priorityValue);
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
