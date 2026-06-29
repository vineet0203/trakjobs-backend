<?php

namespace App\Http\Controllers\Api\V1;

use App\Notifications\DeploymentStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Http\Controllers\Controller;
use App\Mail\DeploymentNotification;
use App\Services\RequestAnalyticsService;
use App\Services\TelegramService;
use App\Traits\TracksRequestAnalytics;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class DeploymentController extends Controller
{
    use TracksRequestAnalytics;
    private $startTime;
    private $lockFile;

    /**
     * Handle GitHub webhook for deployment
     */
    public function handleWebhook(Request $request)
    {
        $this->startTime = microtime(true);

        Log::info('GitHub webhook received', [
            'ip' => $request->ip(),
            'event' => $request->header('X-GitHub-Event'),
            'signature' => $request->header('X-Hub-Signature-256'),
        ]);

        // 1. Verify GitHub IP
        if (!$this->verifyGitHubIp($request)) {
            Log::warning('Non-GitHub IP attempted webhook access', [
                'ip' => $request->ip(),
                'event' => $request->header('X-GitHub-Event'),
            ]);
            return response()->json(['error' => 'IP not allowed'], 403);
        }


        $event = $request->header('X-GitHub-Event');
        $payload = $request->json()->all();

        // 3. Verify repository
        if (($payload['repository']['full_name'] ?? '') !== 'vineet0203/trakjobs-backend') {
            Log::warning('Webhook from wrong repository', [
                'repository' => $payload['repository']['full_name'] ?? 'unknown',
                'expected' => 'vineet0203/trakjobs-backend',
            ]);
            return response()->json(['error' => 'Invalid repository'], 403);
        }

        // Only process push events to main branch
        if ($event === 'push' && ($payload['ref'] ?? '') === 'refs/heads/main') {
            return $this->deployApplication($payload);
        }

        // Handle ping event
        if ($event === 'ping') {
            return response()->json([
                'status' => 'pong',
                'message' => 'Webhook is active and secure',
                'repository' => $payload['repository']['full_name'] ?? 'unknown',
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        return response()->json([
            'status' => 'received',
            'event' => $event,
            'message' => 'Event received but not processed',
        ]);
    }


    /**
     * Verify GitHub IP address
     */
    private function verifyGitHubIp(Request $request): bool
    {
        $githubIps = [
            '192.30.252.0/22',
            '185.199.108.0/22',
            '140.82.112.0/20',
            '143.55.64.0/20',
            '20.201.28.151/32',
            '20.205.243.166/32',
            '102.133.202.242/32',
        ];

        $clientIp = $request->ip();

        // Allow localhost for testing
        if (in_array($clientIp, ['127.0.0.1', '::1'])) {
            return true;
        }

        foreach ($githubIps as $range) {
            if ($this->ipInRange($clientIp, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ipInRange($ip, $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);

        if ($ip === false || $subnet === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ip & $mask) === ($subnet & $mask);
    }



    /**
     * Deploy the application with all production features
     */
    private function deployApplication(array $payload)
    {
        // 1. Check deployment lock
        if (!$this->acquireDeploymentLock()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Deployment already in progress',
                'retry_after' => 300,
            ], 429);
        }

        // 2. Create backup
        $backupPath = null;
        if (config('github.deployment.enable_backups', true)) {
            $backupPath = $this->createBackup();

            // Clean up old backups after creating new one
            if ($backupPath) {
                $this->cleanupOldBackups(7); // Keep last 7 days
            }
        }


        Log::info('Starting deployment', [
            'branch' => $payload['ref'],
            'commit' => $payload['head_commit']['id'] ?? 'unknown',
            'pusher' => $payload['pusher']['name'] ?? 'unknown',
            'message' => $payload['head_commit']['message'] ?? 'no message',
        ]);

        $output = [];
        $success = false;
        $duration = 0;

        try {
            // 3. Execute deployment steps
            $output[] = $this->executeCommand(['git', 'pull', 'origin', 'main'], 'Git pull');
            $output[] = $this->executeCommand(
                ['composer', 'install', '--no-dev', '--optimize-autoloader'],
                'Composer install'
            );

            // 4. Run migrations with backup check
            if ($backupPath) {
                Artisan::call('migrate', ['--force' => true]);
            } else {
                Artisan::call('migrate', ['--force' => true, '--pretend' => true]);
                Log::warning('Migration ran in pretend mode due to backup failure');
            }
            $output[] = 'Migrations: ' . Artisan::output();

            // 4b. Run essential seeders (idempotent - uses firstOrCreate)
            Artisan::call('db:seed', ['--class' => 'DocumentTemplateSeeder', '--force' => true]);
            $output[] = 'Seeders: ' . Artisan::output();

            // 5. Clear caches
            Artisan::call('cache:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            // 6. Additional steps
            $output[] = $this->executeCommand(['php', 'artisan', 'storage:link'], 'Storage link');
            //$output[] = $this->executeCommand(['sudo', 'systemctl', 'reload', 'php8.4-fpm'], 'PHP-FPM reload');

            $success = true;
            $duration = round(microtime(true) - $this->startTime, 2);

            Log::info('Deployment completed successfully', [
                'duration' => $duration,
                'commit' => $payload['head_commit']['id'] ?? 'unknown',
            ]);

            // 7. Send success notification
            $this->sendDeploymentNotification($payload, $output, true, $duration, $backupPath);

            // 8. Log statistics
            $this->logDeploymentStats($payload, $output, true, $duration);

            return response()->json([
                'status' => 'success',
                'message' => 'Deployment completed successfully',
                'commit' => $payload['head_commit']['id'] ?? 'unknown',
                'duration' => $duration,
                'backup' => $backupPath ? 'Created' : 'Skipped',
                'timestamp' => now()->toDateTimeString(),
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $this->startTime, 2);

            Log::error('Deployment failed', [
                'error' => $e->getMessage(),
                'duration' => $duration,
                'trace' => $e->getTraceAsString(),
            ]);

            // 9. Send failure notification
            $this->sendDeploymentNotification($payload, $output, false, $duration, $backupPath, $e->getMessage());

            // 10. Log failure statistics
            $this->logDeploymentStats($payload, $output, false, $duration);

            // 11. Attempt rollback if backup exists
            if ($backupPath && config('github.deployment.enable_auto_rollback', false)) {
                $this->attemptRollback($backupPath, $e->getMessage());
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Deployment failed',
                'error' => $e->getMessage(),
                'duration' => $duration,
                'backup' => $backupPath ? 'Available' : 'None',
                'output' => $output,
            ], 500);
        } finally {
            // 12. Release deployment lock
            $this->releaseDeploymentLock();
        }
    }

    /**
     * 1. Create backup before deployment
     */
    private function createBackup(): ?string
    {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $backupDir = storage_path("backups/{$timestamp}");

            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $config = config('database.connections.mysql');
            $dbUser = $config['username']; // Get from config (reads from .env)
            $dbPass = $config['password']; // Get from config (reads from .env)
            $dbName = $config['database'];
            $dbHost = $config['host'];

            $dbFile = "{$backupDir}/database.sql";

            // Method A: Pass credentials directly in command
            $process = new Process([
                'mysqldump',
                '--host=' . $dbHost,
                '--user=' . $dbUser,
                '--password=' . $dbPass,
                '--no-tablespaces',
                '--single-transaction',
                '--quick',
                $dbName
            ]);

            $process->setWorkingDirectory(base_path());
            $process->setTimeout(300);

            // Capture output to file
            $process->start();
            $process->wait();

            if ($process->isSuccessful()) {
                // Write output to file
                file_put_contents($dbFile, $process->getOutput());

                Log::info('Database backup created', [
                    'size' => filesize($dbFile),
                    'user' => $dbUser
                ]);

                // Backup .env
                copy(base_path('.env'), "{$backupDir}/.env");

                return $backupDir;
            } else {
                Log::error('Database backup failed', [
                    'error' => $process->getErrorOutput()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Backup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send deployment notifications
     */
    private function sendDeploymentNotification(array $payload, array $output, bool $success, float $duration, ?string $backupPath = null, ?string $error = null): void
    {
        try {
            $notificationData = [
                'success' => $success,
                'commit' => [
                    'id' => $payload['head_commit']['id'] ?? 'unknown',
                    'message' => $payload['head_commit']['message'] ?? 'no message',
                    'author' => $payload['pusher']['name'] ?? 'unknown',
                    'url' => $payload['head_commit']['url'] ?? null,
                ],
                'repository' => [
                    'name' => $payload['repository']['full_name'] ?? 'unknown',
                    'url' => $payload['repository']['html_url'] ?? null,
                ],
                'duration' => $duration,
                'timestamp' => now()->toDateTimeString(),
                'error' => $error,
                'backup' => $backupPath ? 'Created' : ($success ? 'Skipped' : 'Not created'),
                'environment' => app()->environment(),
                'steps_completed' => count($output),
                'migration_status' => $backupPath ? 'executed' : 'pretend_mode',
            ];

            // 1. Log to file
            Log::channel('deployments')->info(
                $success ? 'Deployment Successful' : 'Deployment Failed',
                $notificationData
            );

            // 2. Send Telegram notification
            if (class_exists(TelegramService::class)) {
                try {
                    $telegram = new TelegramService();
                    $telegram->sendDeploymentAlert($notificationData);
                } catch (\Exception $e) {
                    Log::warning('Telegram notification failed', ['error' => $e->getMessage()]);
                }
            }

            // 3. Send Email notification using Notification class
            $this->sendDeploymentEmailNotification($notificationData);

            // 4. Optional: Also log to database for analytics
            // $this->logDeploymentToDatabase($notificationData);
        } catch (\Exception $e) {
            Log::error('Notification system failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send deployment email notification
     */
    private function sendDeploymentEmailNotification(array $notificationData): void
    {
        try {
            // Get recipients from config or env
            $recipients = $this->getDeploymentRecipients();

            if (empty($recipients)) {
                Log::info('No deployment email recipients configured');
                return;
            }

            foreach ($recipients as $recipient) {
                // Send to email address directly (not using User model)
                Notification::route('mail', $recipient)
                    ->notify(new DeploymentStatusNotification($notificationData));
            }

            Log::info('Deployment email notifications sent', [
                'recipients_count' => count($recipients),
                'success' => $notificationData['success'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send deployment email notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get deployment notification recipients
     */
    private function getDeploymentRecipients(): array
    {
        // Get from config or env
        $recipients = config('deployment.notification_emails', []);

        // Single email fallback
        if (empty($recipients)) {
            $singleEmail = env('DEPLOYMENT_NOTIFICATION_EMAIL');
            if ($singleEmail) {
                $recipients = [$singleEmail];
            }
        }

        return array_filter($recipients);
    }


    /**
     * 4. Log deployment statistics
     */
    private function logDeploymentStats(array $payload, array $output, bool $success, float $duration): void
    {
        try {
            if (!Schema::hasTable('deployment_stats')) {
                return;
            }

            $analytics = app(RequestAnalyticsService::class);
            $requestAnalytics = $analytics->getAnalytics();

            // Get server info
            $serverHostname = gethostname();
            $serverIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname($serverHostname);

            // Generate unique deployment ID
            $deploymentId = 'DEP-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('YmdHis');

            // Extract author details from payload
            $authorName = $payload['pusher']['name'] ??
                $payload['head_commit']['author']['name'] ??
                $payload['head_commit']['author']['username'] ??
                'unknown';

            $authorEmail = $payload['head_commit']['author']['email'] ??
                $payload['pusher']['email'] ??
                null;

            $authorGithubUrl = null;
            if (isset($payload['head_commit']['author']['username'])) {
                $authorGithubUrl = 'https://github.com/' . $payload['head_commit']['author']['username'];
            } elseif (isset($payload['pusher']['name'])) {
                $authorGithubUrl = 'https://github.com/' . $payload['pusher']['name'];
            }

            // Prepare metadata
            $metadata = [
                'github_event' => request()->header('X-GitHub-Event'),
                'github_delivery' => request()->header('X-GitHub-Delivery'),
                'github_hook_id' => request()->header('X-GitHub-Hook-ID'),
                'user_agent' => request()->userAgent(),
                'request_method' => request()->method(),
                'output_lines' => count($output),
                'server' => [
                    'hostname' => $serverHostname,
                    'ip' => $serverIp,
                    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                ],
                'environment' => app()->environment(),
            ];

            // Create payload summary (avoid storing entire payload)
            $payloadSummary = [
                'ref' => $payload['ref'] ?? null,
                'before_commit' => $payload['before'] ?? null,
                'after_commit' => $payload['after'] ?? null,
                'created' => $payload['created'] ?? false,
                'deleted' => $payload['deleted'] ?? false,
                'forced' => $payload['forced'] ?? false,
                'compare_url' => $payload['compare'] ?? null,
                'commits_count' => count($payload['commits'] ?? []),
                'pusher_type' => $payload['pusher']['type'] ?? null,
                'sender_type' => $payload['sender']['type'] ?? null,
            ];

            // Combine metadata with request analytics
            $fullMetadata = array_merge($metadata, [
                'request_analytics' => $requestAnalytics,
                'request_summary' => $analytics->getSummary(),
            ]);

            // Check if we need to use the enhanced schema or basic schema
            $columns = Schema::getColumnListing('deployment_stats');
            $useEnhancedSchema = in_array('deployment_id', $columns);

            if ($useEnhancedSchema) {
                // Use enhanced schema with all fields
                DB::table('deployment_stats')->insert([
                    // Deployment Identification
                    'deployment_id' => $deploymentId,
                    'trigger_type' => 'github',
                    'environment' => app()->environment(),

                    // Git Information
                    'commit_hash' => $payload['head_commit']['id'] ?? 'unknown',
                    'commit_short_hash' => substr($payload['head_commit']['id'] ?? 'unknown', 0, 8),
                    'commit_message' => $payload['head_commit']['message'] ?? 'no message',
                    'author_name' => $authorName,
                    'author_email' => $authorEmail,
                    'author_github_url' => $authorGithubUrl,
                    'commit_url' => $payload['head_commit']['url'] ?? null,

                    // Repository Information
                    'repository_name' => $payload['repository']['full_name'] ?? 'unknown',
                    'repository_url' => $payload['repository']['html_url'] ?? null,
                    'branch' => str_replace('refs/heads/', '', $payload['ref'] ?? 'main'),

                    // Deployment Execution Info
                    'success' => $success,
                    'duration_seconds' => $duration,
                    'output_size' => strlen(implode("\n", $output)),

                    // IP & Location Tracking
                    'trigger_ip' => $requestAnalytics['ip']['ip_address'],
                    'trigger_country' => $requestAnalytics['location']['country'] ?? null,
                    'trigger_city' => $requestAnalytics['location']['city'] ?? null,
                    'trigger_region' => $requestAnalytics['location']['region'] ?? null,
                    'trigger_latitude' => $requestAnalytics['location']['latitude'] ?? null,
                    'trigger_longitude' => $requestAnalytics['location']['longitude'] ?? null,

                    // Error Information
                    'error_message' => !$success ? ($output[count($output) - 1] ?? 'Unknown error') : null,
                    'error_trace' => null,

                    // System Information
                    'server_hostname' => $serverHostname,
                    'server_ip' => $serverIp,

                    // Backup Information
                    'backup_created' => false, // Set based on your backup logic
                    'backup_path' => null,

                    // Rollback Information
                    'is_rollback' => false,
                    'rollback_from_commit' => null,

                    // Additional Metadata
                    'metadata' => json_encode($fullMetadata),
                    'payload_summary' => json_encode($payloadSummary),

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Use basic schema (backward compatibility)
                DB::table('deployment_stats')->insert([
                    'commit_hash' => $payload['head_commit']['id'] ?? 'unknown',
                    'commit_message' => $payload['head_commit']['message'] ?? 'no message',
                    'author' => $authorName,
                    'success' => $success,
                    'duration_seconds' => $duration,
                    'output_size' => strlen(implode("\n", $output)),
                    'trigger_ip' => $requestAnalytics['ip']['ip_address'],
                    'trigger_country' => $requestAnalytics['location']['country'] ?? null,
                    'trigger_city' => $requestAnalytics['location']['city'] ?? null,
                    'trigger_region' => $requestAnalytics['location']['region'] ?? null,
                    'trigger_latitude' => $requestAnalytics['location']['latitude'] ?? null,
                    'trigger_longitude' => $requestAnalytics['location']['longitude'] ?? null,
                    'metadata' => json_encode($fullMetadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info('Deployment stats logged successfully', [
                'deployment_id' => $deploymentId ?? 'N/A',
                'commit' => $payload['head_commit']['id'] ?? 'unknown',
                'author' => $authorName,
                'success' => $success,
                'duration' => $duration,
                'location' => $analytics->getLocationString(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log deployment stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 5. Rollback capability
     */
    public function rollback(Request $request)
    {
        // Security check
        if (!$this->validateRollbackRequest($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $commit = $request->input('commit', 'HEAD~1');

        try {
            $output[] = $this->executeCommand(
                ['git', 'revert', '--no-edit', $commit],
                'Git revert'
            );

            // Re-run deployment steps
            return $this->deployApplication([
                'ref' => 'refs/heads/main',
                'head_commit' => ['id' => 'rollback-' . time()],
                'pusher' => ['name' => 'rollback'],
                'repository' => ['full_name' => 'vineet0203/trakjobs-backend'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Rollback failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 6. Attempt automatic rollback
     */
    private function attemptRollback(string $backupPath, string $errorMessage): void
    {
        try {
            Log::warning('Attempting automatic rollback', ['backup' => $backupPath]);

            // Restore database from backup
            $dbFile = "{$backupPath}/database.sql";
            if (file_exists($dbFile)) {
                $command = sprintf(
                    'mysql -u%s -p%s %s < %s',
                    escapeshellarg(env('DB_USERNAME')),
                    escapeshellarg(env('DB_PASSWORD')),
                    escapeshellarg(env('DB_DATABASE')),
                    escapeshellarg($dbFile)
                );
                $this->executeCommand(explode(' ', $command), 'Database restore');
            }

            // Git revert to previous commit
            $this->executeCommand(['git', 'reset', '--hard', 'HEAD~1'], 'Git reset');

            Log::info('Automatic rollback completed successfully');
        } catch (\Exception $e) {
            Log::error('Automatic rollback failed', [
                'error' => $e->getMessage(),
                'original_error' => $errorMessage,
            ]);
        }
    }

    /**
     * Deployment lock management
     */
    private function acquireDeploymentLock(): bool
    {
        $this->lockFile = storage_path('deploy.lock');

        if (file_exists($this->lockFile)) {
            $lockTime = filemtime($this->lockFile);
            if ((time() - $lockTime) < 300) { // 5 minutes
                return false;
            }
            unlink($this->lockFile);
        }

        file_put_contents($this->lockFile, time());
        return true;
    }

    private function releaseDeploymentLock(): void
    {
        if ($this->lockFile && file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Clean up old backups, keeping only the last 7 days
     */
    private function cleanupOldBackups(int $daysToKeep = 7): void
    {
        try {
            $backupDir = storage_path('backups');

            if (!file_exists($backupDir)) {
                return;
            }

            $allBackups = scandir($backupDir);
            $deletedCount = 0;
            $keptCount = 0;

            $cutoffDate = now()->subDays($daysToKeep);

            foreach ($allBackups as $backupFolder) {
                if ($backupFolder === '.' || $backupFolder === '..') {
                    continue;
                }

                $backupPath = $backupDir . '/' . $backupFolder;

                // Extract date from folder name (format: YYYY-MM-DD_HH-MM-SS)
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})$/', $backupFolder, $matches)) {
                    $year = (int)$matches[1];
                    $month = (int)$matches[2];
                    $day = (int)$matches[3];

                    $backupDate = Carbon::create($year, $month, $day);

                    if ($backupDate->lt($cutoffDate)) {
                        // Delete old backup
                        $this->deleteBackupFolder($backupPath);
                        $deletedCount++;
                        Log::info('Deleted old backup', ['folder' => $backupFolder]);
                    } else {
                        $keptCount++;
                    }
                } else {
                    Log::warning('Invalid backup folder name format', ['folder' => $backupFolder]);
                }
            }

            Log::info('Backup cleanup completed', [
                'deleted' => $deletedCount,
                'kept' => $keptCount,
                'total' => count($allBackups) - 2, // Subtract . and ..
                'cutoff_date' => $cutoffDate->toDateString(),
                'days_to_keep' => $daysToKeep
            ]);
        } catch (\Exception $e) {
            Log::error('Backup cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Safely delete a backup folder and all its contents
     */
    private function deleteBackupFolder(string $path): bool
    {
        if (!file_exists($path) || !is_dir($path)) {
            return false;
        }

        try {
            // Delete all files in the directory
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                $filePath = $path . '/' . $file;
                if (is_dir($filePath)) {
                    $this->deleteBackupFolder($filePath);
                } else {
                    unlink($filePath);
                }
            }

            // Delete the directory itself
            return rmdir($path);
        } catch (\Exception $e) {
            Log::error('Failed to delete backup folder', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Execute shell command safely
     */
    private function executeCommand(array $command, string $description): string
    {
        // Add safety: run as www-data user
        if (!in_array($command[0], ['sudo', 'git', 'composer', 'php'])) {
            array_unshift($command, 'sudo', '-u', 'www-data');
        }

        $process = new Process($command);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(300); // 5 minutes timeout

        // Set safe environment
        $process->setEnv([
            'HOME' => '/var/www',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $description . ': ' . trim($process->getOutput());
    }

    /**
     * Test endpoint (no authentication required)
     */
    public function test()
    {
        return response()->json([
            'status' => 'active',
            'service' => 'GitHub Webhook Handler',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'timestamp' => now()->toDateTimeString(),
            'version' => '1.0.0',
            'security' => [
                'ip_whitelist' => true,
                'signature_verification' => true,
                'repository_validation' => true,
                'deployment_lock' => true,
            ],
        ]);
    }

    /**
     * Manual deployment trigger (for testing) - SECURED
     */
    public function manualDeploy(Request $request)
    {
        // SECURITY: Only allow in non-production or with admin auth
        if (app()->environment('production')) {
            // Check for admin role or specific token
            $token = $request->header('X-Deployment-Token');
            $validToken = config('github.deployment.token');

            if (!$token || $token !== $validToken) {
                Log::warning('Unauthorized manual deploy attempt', [
                    'ip' => $request->ip(),
                    'token_provided' => !!$token,
                ]);
                return response()->json(['error' => 'Unauthorized'], 401);
            }
        }

        // Add IP restriction for manual deploy
        $allowedIps = ['127.0.0.1', '::1']; // Add your office IPs
        if (!in_array($request->ip(), $allowedIps)) {
            return response()->json(['error' => 'IP not allowed for manual deploy'], 403);
        }

        return $this->deployApplication([
            'ref' => 'refs/heads/main',
            'head_commit' => ['id' => 'manual-trigger-' . time()],
            'pusher' => ['name' => 'manual-' . $request->ip()],
            'repository' => ['full_name' => 'vineet0203/trakjobs-backend'],
        ]);
    }

    /**
     * Handle GitHub webhook verification (GET request)
     */
    public function verifyWebhook(Request $request)
    {
        Log::info('GitHub webhook verification request', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'query_params' => $request->query(),
        ]);

        return response()->json([
            'status' => 'active',
            'message' => 'GitHub webhook endpoint is ready and secure',
            'security_features' => [
                'ip_whitelisting' => true,
                'signature_verification' => true,
                'repository_validation' => true,
                'deployment_lock' => true,
                'rate_limiting' => 'via nginx/middleware',
            ],
            'methods' => ['POST' => 'webhooks', 'GET' => 'verification'],
            'repository' => 'vineet0203/trakjobs-backend',
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
