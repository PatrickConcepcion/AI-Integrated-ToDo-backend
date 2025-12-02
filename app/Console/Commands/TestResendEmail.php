<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestResendEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test-resend {to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test email using Resend to verify configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $to = $this->argument('to');

        $this->info("Sending test email to: {$to}...");

        try {
            Mail::raw('This is a test email from Laravel using Resend.', function ($message) use ($to) {
                $message->to($to)
                    ->subject('Resend Test Email');
            });

            $this->info('Test email sent successfully!');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}