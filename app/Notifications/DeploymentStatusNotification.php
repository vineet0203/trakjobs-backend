<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries;
    public $timeout;

    protected $deploymentData;

    public function __construct(array $deploymentData)
    {
        $this->deploymentData = $deploymentData;

        $this->tries = config('notifications.tries', 3);
        $this->timeout = config('notifications.timeout', 30);
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return [
            'mail' => config('notifications.queue.emails', 'emails'),
        ];
    }

    public function backoff(): array
    {
        return config('notifications.backoff', [10, 30, 60]);
    }

    public function toMail($notifiable): MailMessage
    {
        $appName = config('app.name', 'TrakJobs');
        $environment = $this->deploymentData['environment'] ?? app()->environment();

        $subject = $this->deploymentData['success']
            ? 'Deployment Successful - ' . $appName . ' (' . $environment . ')'
            : 'Deployment Failed - ' . $appName . ' (' . $environment . ')';

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.deployment.status', [
                'data' => $this->deploymentData,
                'appName' => $appName,
                'environment' => $environment,
                'currentYear' => date('Y'),
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'deployment_status',
            'success' => $this->deploymentData['success'],
            'commit_hash' => $this->deploymentData['commit']['id'] ?? null,
            'repository' => $this->deploymentData['repository']['name'] ?? null,
            'duration' => $this->deploymentData['duration'] ?? 0,
        ];
    }
}
