<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class UpdateAdminPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-admin-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the admin user password to "password"';

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

        $user->password = Hash::make('password');
        $user->save();

        $this->info('Admin password updated successfully!');
        return 0;
    }
}
