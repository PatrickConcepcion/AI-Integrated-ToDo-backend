<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Category\StoreRequest;
use App\Http\Requests\Category\UpdateRequest;

class CategoryController extends Controller
{
    /**
     * Display a listing of all categories.
     * Includes task count for each category.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Get all categories with count of user's tasks in each
        $categories = Category::withCount([
            'tasks' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }
        ])->get();

        return response()->json([
            'data' => $categories,
            'message' => 'Categories retrieved successfully.'
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validated();

        $category = Category::create($validated);

        return response()->json([
            'data' => $category,
            'message' => 'Category created successfully.'
        ], 201);
    }

    /**
     * Display the specified category with its tasks.
     */
    public function show(Request $request, Category $category): JsonResponse
    {
        $user = Auth::user();

        // Load only the user's tasks for this category
        $category->loadCount([
            'tasks' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }
        ]);

        return response()->json([
            'data' => $category,
            'message' => 'Category retrieved successfully.'
        ]);
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validated();

        $category->update($validated);

        return response()->json([
            'data' => $category,
            'message' => 'Category updated successfully.'
        ]);
    }

    /**
     * Remove the specified category.
     * Associated tasks will have their category_id set to null (as per migration onDelete('set null')).
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.'
        ]);
    }
}
