<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Notifications\DeploymentStatusNotification;
use Illuminate\Support\Facades\Notification;

class TestDeploymentEmail extends Command
{
    protected $signature = 'test:deployment-email {email} {--f|failure}';
    protected $description = 'Test deployment email notification';

    public function handle()
    {
        $email = $this->argument('email');
        $isFailure = $this->option('failure');

        $deploymentData = [
            'success' => !$isFailure,
            'commit' => [
                'id' => 'cc172706f35a0991e02bad68847c4b72758ef6a8',
                'message' => $isFailure ? 'fix: resolve deployment issues' : 'feat: add new features',
                'author' => 'rajpootsourabh',
                'url' => 'https://github.com/vineet0203/trakjobs-backend/commit/cc172706f35a0991e02bad68847c4b72758ef6a8',
            ],
            'repository' => [
                'name' => 'vineet0203/trakjobs-backend',
                'url' => 'https://github.com/vineet0203/trakjobs-backend',
            ],
            'duration' => $isFailure ? 3.45 : 4.31,
            'timestamp' => now()->toDateTimeString(),
            'backup' => $isFailure ? 'None' : 'Created',
            'environment' => app()->environment(),
            'error' => $isFailure ? "The command 'sudo systemctl reload php8.4-fpm' failed.\n\nExit Code: 1(General error)" : null,
        ];

        if ($isFailure) {
            $this->info('Sending failure email...');
        } else {
            $this->info('Sending success email...');
        }

        Notification::route('mail', $email)
            ->notify(new DeploymentStatusNotification($deploymentData));

        $this->info('Email sent to: ' . $email);
        
        return 0;
    }
}