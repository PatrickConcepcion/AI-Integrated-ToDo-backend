<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Helper method to login a user and return JWT token
     */
    protected function loginAsUser(User $user): string
    {
        return Auth::guard('api')->login($user);
    }

    /**
     * Helper method to set authentication headers
     */
    protected function auth(string $token): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }
}
