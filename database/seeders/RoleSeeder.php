<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles (idempotent - only creates if not exists)
        Role::firstOrCreate(['name' => 'user'], ['guard_name' => 'api']);
        Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'api']);
    }
}
