<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Enums\StatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Task\StoreRequest;
use App\Http\Requests\Task\UpdateRequest;
use Carbon\Carbon;

class TaskController extends Controller
{
    /**
     * Display a listing of active (non-archived) tasks.
     * Supports filtering by category, priority, status, and due date.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Task::where('user_id', Auth::id())
            ->active() // Only non-archived tasks
            ->with('category')
            ->when($request->has('category_id') && $request->category_id !== null, function ($q) use ($request) {
                return $q->where('category_id', $request->category_id);
            })
            ->when($request->has('priority') && $request->priority !== null, function ($q) use ($request) {
                return $q->where('priority', $request->priority);
            })
            ->when($request->has('status') && in_array($request->status, ['todo', 'in_progress', 'completed']), function ($q) use ($request) {
                return $q->where('status', $request->status);
            })
            ->when($request->has('due_date') && $request->due_date !== null, function ($q) use ($request) {
                return $q->whereDate('due_date', $request->due_date);
            })
            ->when($request->has('overdue') && $request->overdue === 'true', function ($q) {
                return $q->where('due_date', '<', Carbon::now())->where('status', '!=', 'completed');
            });

        // Sort by due date or created date
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tasks = $query->get();

        return response()->json([
            'data' => $tasks,
            'message' => 'Tasks retrieved successfully.'
        ]);
    }

    /**
     * Display a listing of archived tasks.
     */
    public function archived(): JsonResponse
    {
        $tasks = Task::where('user_id', Auth::id())
            ->archived()
            ->with('category')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $tasks,
            'message' => 'Archived tasks retrieved successfully.'
        ]);
    }

    /**
     * Store a newly created task.
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $task = Task::create(array_merge($validated, ['user_id' => Auth::id()]));

        $task->load('category');

        return response()->json([
            'data' => $task,
            'message' => 'Task created successfully.'
        ], 201);
    }

    /**
     * Display the specified task.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $task->load('category');

        return response()->json([
            'data' => $task,
            'message' => 'Task retrieved successfully.'
        ]);
    }

    /**
     * Update the specified task.
     */
    public function update(UpdateRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validated();

        if (array_key_exists('status', $validated)) {
            $newStatus = StatusEnum::from($validated['status']);

            // Preserve the previous status when archiving; clear when leaving archive
            $task->transitionToStatus($newStatus);
            unset($validated['status']);
        }

        $task->fill($validated);
        $task->save();
        $task->load('category');

        return response()->json([
            'data' => $task,
            'message' => 'Task updated successfully.'
        ]);
    }

    /**
     * Remove the specified task from storage.
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $taskId = $task->id;
        $task->delete();

        return response()->json([
            'message' => 'Task deleted successfully.'
        ]);
    }
}
