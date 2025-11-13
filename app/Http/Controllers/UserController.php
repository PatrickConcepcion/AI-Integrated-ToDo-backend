<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Display a listing of all users.
     * Admin only.
     */
    public function index(): JsonResponse
    {
        $users = User::all();

        return response()->json([
            'data' => $users,
            'message' => 'Users retrieved successfully.'
        ]);
    }

    /**
     * Ban the specified user.
     * Admin only.
     */
    public function ban($id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Prevent banning self
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'You cannot ban yourself.'
            ], 403);
        }

        $user->is_banned = true;
        $user->save();

        return response()->json([
            'data' => $user,
            'message' => 'User banned successfully.'
        ]);
    }
}