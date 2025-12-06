# AI Integrated ToDo - Backend API

A Laravel 12 REST API backend for an AI-powered task management application. This API provides comprehensive task management capabilities enhanced with OpenAI integration for natural language task operations and intelligent assistance.

## Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Running the Application](#running-the-application)
- [API Documentation](#api-documentation)
- [Testing](#testing)
- [Architecture](#architecture)
- [License](#license)

## Features

### Core Task Management
- **CRUD Operations**: Create, read, update, and delete tasks with comprehensive validation
- **Task Categorization**: Organize tasks into user-defined categories
- **Priority Levels**: Three-tier priority system (low, medium, high)
- **Status Tracking**: Track task progress through multiple states (todo, in_progress, completed, archived)
- **Due Date Management**: Set and track task deadlines with Carbon date handling
- **Task Archiving**: Archive completed or obsolete tasks while maintaining data history

### AI-Powered Assistant
- **Natural Language Processing**: Interact with your tasks using conversational language
- **OpenAI Function Calling**: Intelligent task operations via GPT-4o-mini with tool use
- **Contextual Awareness**: AI maintains conversation history and task context
- **Streaming Responses**: Real-time AI responses using Server-Sent Events (SSE)
- **Smart Duplicate Detection**: Automatic disambiguation when multiple tasks share the same title
- **Relative Date Processing**: Natural language date handling (e.g., "tomorrow", "next week")

### Authentication & Security
- **JWT Authentication**: Secure token-based authentication using php-open-source-saver/jwt-auth
- **Role-Based Access Control (RBAC)**: Spatie Laravel Permission integration for role management
- **Password Reset**: Email-based password reset flow via Resend
- **Rate Limiting**: Throttle protection on sensitive endpoints
- **Policy Authorization**: Laravel policies for fine-grained access control

### Data Management
- **Eloquent Relationships**: Well-defined model relationships (User, Task, Category, Conversation, Message)
- **Database Migrations**: Version-controlled schema with enum support
- **Factory Support**: Comprehensive factories for testing and seeding
- **Soft Deletes**: Optional soft delete support for data recovery
- **Query Optimization**: Eager loading to prevent N+1 queries

## Technology Stack

- **Framework**: Laravel 12
- **PHP Version**: 8.2+
- **Database**: SQLite (default), MySQL/PostgreSQL compatible
- **Authentication**: JWT (JSON Web Tokens)
- **AI Integration**: OpenAI PHP Client (GPT-4o-mini)
- **Email Service**: Resend
- **Permissions**: Spatie Laravel Permission
- **Testing**: PHPUnit, Mockery
- **Code Quality**: Laravel Pint, PHPStan, Larastan

## Prerequisites

Before installing, ensure you have the following:

- PHP 8.2 or higher
- Composer 2.x
- SQLite3 (or MySQL/PostgreSQL if preferred)
- An OpenAI API account with an active API key
- A Resend account with an active API key (for email functionality)

## Installation

Assuming you have already cloned the repository, follow these steps:

### 1. Install Dependencies

```bash
composer install
```

### 2. Environment Setup

Copy the example environment file and generate an application key:

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Generate JWT Secret

Generate a secure JWT secret key:

```bash
php artisan jwt:secret
```

This will add `JWT_SECRET` to your `.env` file automatically.

## Configuration

### Required API Keys

You must obtain and configure the following API keys in your `.env` file:

#### OpenAI Configuration

1. Sign up at [OpenAI Platform](https://platform.openai.com/)
2. Generate an API key from your account dashboard
3. Add to `.env`:

```env
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-4o-mini
OPENAI_MAX_TOKENS=500
```

#### Resend Configuration

1. Sign up at [Resend](https://resend.com/)
2. Generate an API key from your dashboard
3. Add to `.env`:

```env
RESEND_API_KEY=your_resend_api_key_here
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### Frontend Configuration

Update the frontend URL to match your client application:

```env
FRONTEND_URL=http://localhost:5173
```

### Database Configuration

By default, the application uses SQLite. To use MySQL or PostgreSQL, update these values:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_todo_db
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

## Database Setup

### Run Migrations

Create all necessary database tables:

```bash
php artisan migrate
```

### Seed Database

Seed the database with initial roles and an admin user:

```bash
php artisan db:seed
```

This will create:
- Default roles (admin, user)
- Admin user account (check `database/seeders/AdminUserSeeder.php` for credentials)

### Optional: Seed Test Data

For development, you can create sample categories and tasks:

```bash
php artisan db:seed --class=DatabaseSeeder
```

## Running the Application

### Development Server

Start the Laravel development server:

```bash
php artisan serve
```

The API will be accessible at `http://localhost:8000`.

### Queue Worker (Optional)

If you're using queued jobs, start the queue worker:

```bash
php artisan queue:work
```

### Background Task Logging

For real-time log monitoring during development:

```bash
php artisan pail
```

## API Documentation

### Base URL

```
http://localhost:8000/api
```

### Authentication Endpoints

| Method | Endpoint | Description | Authentication |
|--------|----------|-------------|----------------|
| POST | `/auth/register` | Register a new user | None |
| POST | `/auth/login` | Authenticate and receive JWT token | None |
| POST | `/auth/refresh` | Refresh JWT token | None |
| POST | `/auth/forgot-password` | Request password reset email | None |
| POST | `/auth/reset-password` | Reset password with token | None |
| GET | `/auth/me` | Get authenticated user details | Required |
| POST | `/auth/logout` | Invalidate JWT token | Required |
| POST | `/auth/change-password` | Change user password | Required |

### Task Endpoints

| Method | Endpoint | Description | Authentication |
|--------|----------|-------------|----------------|
| GET | `/tasks` | List all tasks (with filters) | Required |
| GET | `/tasks/archived` | List archived tasks | Required |
| GET | `/tasks/{id}` | Get specific task details | Required |
| POST | `/tasks` | Create a new task | Required |
| PUT/PATCH | `/tasks/{id}` | Update a task | Required |
| DELETE | `/tasks/{id}` | Delete a task | Required |

### Category Endpoints

| Method | Endpoint | Description | Authentication |
|--------|----------|-------------|----------------|
| GET | `/categories` | List all categories | Required |
| GET | `/categories/{id}` | Get specific category | Required |
| POST | `/categories` | Create a new category | Required (Admin) |
| PUT/PATCH | `/categories/{id}` | Update a category | Required (Admin) |
| DELETE | `/categories/{id}` | Delete a category | Required (Admin) |

### AI Assistant Endpoints

| Method | Endpoint | Description | Authentication |
|--------|----------|-------------|----------------|
| POST | `/ai/chat` | Send a message to AI assistant (streaming) | Required |
| GET | `/ai/messages` | Get conversation history | Required |
| DELETE | `/ai/conversations` | Clear conversation history | Required |

### Request Headers

All authenticated requests must include:

```
Authorization: Bearer {your_jwt_token}
Content-Type: application/json
Accept: application/json
```

### Example Requests

#### Register User

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePassword123",
    "password_confirmation": "SecurePassword123"
  }'
```

#### Create Task

```bash
curl -X POST http://localhost:8000/api/tasks \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Complete project documentation",
    "description": "Write comprehensive README and API docs",
    "priority": "high",
    "due_date": "2025-12-15",
    "status": "todo"
  }'
```

#### AI Chat (Streaming)

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Create a task to review pull requests tomorrow with high priority"
  }'
```

## Testing

### Run All Tests

```bash
php artisan test
```

### Run Specific Test Suite

```bash
php artisan test --filter=Feature
php artisan test --filter=Unit
```

### Run with Coverage

```bash
php artisan test --coverage
```

### Code Quality Checks

Run PHPStan for static analysis:

```bash
./vendor/bin/phpstan analyse
```

Run Laravel Pint for code formatting:

```bash
./vendor/bin/pint
```

## Architecture

### Design Principles

This application follows SOLID principles and DRY (Don't Repeat Yourself) methodology:

- **Single Responsibility**: Each class has one clear purpose
- **Open-Closed**: Extensible through inheritance without modification
- **Liskov Substitution**: Subtypes are substitutable for their base types
- **Interface Segregation**: Clients depend only on methods they use
- **Dependency Inversion**: Depend on abstractions, not concretions

### Key Components

#### Models

- `User`: Application users with authentication
- `Task`: Task entities with status, priority, and relationships
- `Category`: Task categorization
- `Conversation`: User conversation threads with AI
- `Message`: Individual messages within conversations

#### Services

- `OpenAIService`: Handles all OpenAI API interactions, streaming, and function calling

#### Enums

- `StatusEnum`: Task statuses (todo, in_progress, completed, archived)
- `PriorityEnum`: Task priorities (low, medium, high)

#### Policies

- `TaskPolicy`: Authorization rules for task operations
- `CategoryPolicy`: Authorization rules for category operations

### Naming Conventions

- **Database columns**: snake_case (e.g., `user_id`, `created_at`)
- **PHP variables/methods**: camelCase (e.g., `$userId`, `createTask()`)
- **Classes**: PascalCase (e.g., `TaskController`, `OpenAIService`)
- **Imports**: Use short class names with imports at the top (avoid full namespace paths in code)

### AI Integration Flow

1. User sends message to `/api/ai/chat`
2. System retrieves user's task context and conversation history
3. OpenAI processes message with function calling tools
4. If functions are called, system executes them (create/update/delete tasks)
5. System streams AI response back to client via Server-Sent Events
6. Conversation is saved to database for context

### Function Calling Tools

The AI has access to these functions:

- `create_task`: Create a new task with title, description, priority, due_date
- `update_task`: Modify existing task properties
- `delete_task`: Remove a task from the system

### Duplicate Task Handling

When multiple tasks share the same title:
1. System detects duplicates during update/delete operations
2. Returns clarification request with task details
3. User specifies which task by status (todo/in_progress/completed/archived)
4. System executes operation on correct task

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
