# AI Coding Agent Instructions for AI Integrated ToDo Backend

## Architecture Overview
This is a Laravel 12 backend API for an AI-powered ToDo application. Key components:
- **Models**: `Task`, `User`, `Category`, `Conversation`, `Message` with Eloquent relationships
- **Services**: `OpenAIService` handles AI chat with function calling for task operations
- **Enums**: `StatusEnum` (todo, in_progress, completed, archived), `PriorityEnum` (low, medium, high)
- **Authentication**: JWT-based with Spatie Laravel Permission for RBAC

## Data Flow
- API requests → Controllers → Services/Models → Database
- AI chat: User message → OpenAIService → OpenAI API with tools → Function calls execute via controllers → Response

## Critical Workflows
- **Setup**: `composer install`, `php artisan migrate`, `php artisan db:seed` (AdminUserSeeder, RoleSeeder)
- **Testing**: `php artisan test` (PHPUnit with Feature/Unit dirs)
- **Debugging**: Check `storage/logs/laravel.log` for errors; use `php artisan tinker` for REPL
- **AI Testing**: Use `php artisan test --filter=Feature` for API tests with AI integration

## Project Conventions
- **Task Status Transitions**: No restrictions - can change between any status (e.g., completed → in_progress); use `Task::transitionToStatus()` to update while preserving `previous_status`
- **Duplicate Handling**: For update/delete, call function once with title; system detects duplicates and prompts for clarification
- **Date Calculations**: Use Carbon for relative dates (e.g., `Carbon::now()->addDays(5)->format('Y-m-d')`)
- **AI Prompts**: System prompt in `OpenAIService::buildSystemPrompt()` includes task context and creator info
- **Function Tools**: Defined in `OpenAIService::getToolDefinitions()` for create_task, update_task, delete_task
- **Message Storage**: AI conversations stored in `Conversation` and `Message` models for context
- **Clarification for Unsure Data**: When encountering potentially unused variables, files, or other elements, ask for confirmation before suggesting deletion to avoid accidental removal
- **Design Principles**: Follow SOLID (Single Responsibility, Open-Closed, Liskov Substitution, Interface Segregation, Dependency Inversion) and DRY (Don't Repeat Yourself) principles in code design
- **Naming Conventions**: Use snake_case for database column fields; use camelCase for variables in controllers, services, etc.
- **Import Style**: Never use full paths like `\App\Models\User` in code. Import classes at the top and use just the class name (e.g., `User`) for cleaner, more readable code

## Integration Patterns
- **OpenAI**: Config in `config/openai.php`; streaming via `chatStream()` method
- **Database**: MySQL with migrations; use factories for testing (e.g., `TaskFactory`)
- **Email**: Resend service for notifications (e.g., `ResetPasswordNotification`)
- **Frontend**: Vue 3 expects JSON responses; CORS configured in `config/cors.php`

## Key Files
- `app/Services/OpenAIService.php`: Core AI logic and function calling
- `app/Models/Task.php`: Task model with relationships and scopes
- `routes/api.php`: API routes with auth middleware
- `database/migrations/`: Schema definitions (e.g., tasks table with status/priority enums)

## Examples
- Creating a task: Use `create_task` tool with title, priority, due_date (YYYY-MM-DD)
- Updating status: Call `update_task` with task_title and status (e.g., 'completed')
- Handling duplicates: First call fails, system returns clarification message, then retry with task_status