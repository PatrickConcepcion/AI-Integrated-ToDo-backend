<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RestoreAdminRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:restore-admin-role';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore the admin role to the admin@example.com user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::where('email', 'admin@example.com')->first();

        if (!$user) {
            $this->error('Admin user (admin@example.com) not found!');
            return 1;
        }

        if ($user->hasRole('admin')) {
            $this->info('User already has admin role.');
            return 0;
        }

        $user->assignRole('admin');
        $this->info('Admin role restored successfully to admin@example.com!');
        return 0;
    }
}
