<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries;
    public $timeout;

    protected $user;
    protected $token;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;

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
            'mail' => config('notifications.queues.emails', 'emails'),
        ];
    }

    public function backoff(): array
    {
        return config('notifications.backoff', [10, 30, 60]);
    }

    public function toMail($notifiable): MailMessage
    {
        $appName = config('app.name', 'TrakJobs');

        // Use the specific frontend reset URL from config
        $resetUrl = $this->buildResetUrl();

        // Get expiration time from config
        $expireMinutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset Your Password - ' . $appName)
            ->view('emails.auth.password-reset', [
                'user' => $this->user,
                'resetUrl' => $resetUrl,
                'appName' => $appName,
                'expireMinutes' => $expireMinutes,
                'currentYear' => date('Y'),
                'supportEmail' => config('notifications.admin.email', config('mail.from.address', 'support@trakjobs.com')),
                'ipAddress' => request()->ip(),
                'userAgent' => request()->header('User-Agent', 'Unknown'),
            ]);
    }

    /**
     * Build the password reset URL
     */
    protected function buildResetUrl(): string
    {
        // First try to use the specific reset URL from config
        $resetUrl = config('app.frontend_password_reset_url');

        if ($resetUrl) {
            // If we have a specific reset URL, append token and email
            return $this->appendQueryParams($resetUrl);
        }

        // Fallback: Build URL from frontend URL and default path
        $frontendUrl = config('app.frontend_url', config('app.url', 'http://localhost:5173'));
        $defaultPath = '/reset-password';

        return $this->appendQueryParams(rtrim($frontendUrl, '/') . $defaultPath);
    }

    /**
     * Append token and email to URL
     */
    protected function appendQueryParams(string $baseUrl): string
    {
        $queryParams = http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        // Check if URL already has query string
        return str_contains($baseUrl, '?')
            ? $baseUrl . '&' . $queryParams
            : $baseUrl . '?' . $queryParams;
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'password_reset',
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'token' => $this->token, // Log token (for debugging)
            'reset_url' => $this->buildResetUrl(), // Log the generated URL
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->header('User-Agent', 'Unknown'), 0, 255),
            'timestamp' => now()->toISOString(),
        ];
    }
}
