<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private $botToken;
    private $chatId;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN');
        $this->chatId = env('TELEGRAM_CHAT_ID');
    }

    public function isConfigured()
    {
        return !empty($this->botToken) && !empty($this->chatId);
    }

    public function sendDeploymentAlert($data)
    {
        if (!$this->isConfigured()) {
            Log::warning('Telegram not configured, skipping notification');
            return false;
        }

        $message = $this->formatDeploymentMessage($data);
        
        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                Log::info('Telegram notification sent successfully');
                return true;
            } else {
                Log::error('Telegram API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error('Telegram send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function formatDeploymentMessage($data)
    {
        $icon = isset($data['success']) && $data['success'] ? '✅' : '❌';
        $status = isset($data['success']) && $data['success'] ? 'SUCCESS' : 'FAILED';
        
        // Get commit ID safely
        $commitId = 'unknown';
        if (isset($data['commit']['id'])) {
            $commitId = substr($data['commit']['id'], 0, 8);
        }
        
        $commitUrl = "https://github.com/vineet0203/trakjobs-backend/commit/{$commitId}";
        
        // Get commit message safely
        $commitMessage = isset($data['commit']['message']) ? $data['commit']['message'] : 'no message';
        if (strlen($commitMessage) > 100) {
            $commitMessage = substr($commitMessage, 0, 97) . '...';
        }
        
        // Get author safely
        $author = isset($data['commit']['author']) ? $data['commit']['author'] : 'unknown';
        
        // Get duration safely
        $duration = isset($data['duration']) ? $data['duration'] : 0;
        
        // Get timestamp safely
        $timestamp = isset($data['timestamp']) ? $data['timestamp'] : date('Y-m-d H:i:s');
        
        $message = "<b>{$icon} DEPLOYMENT {$status}</b>\n\n";
        $message .= "<b>Repository:</b> Trakjobs Backend\n";
        $message .= "<b>Commit:</b> <a href=\"{$commitUrl}\">{$commitId}</a>\n";
        $message .= "<b>Message:</b> {$commitMessage}\n";
        $message .= "<b>Author:</b> {$author}\n";
        $message .= "<b>Duration:</b> {$duration}s\n";
        $message .= "<b>Time:</b> {$timestamp}\n";
        
        if ((!isset($data['success']) || !$data['success']) && isset($data['error']) && !empty($data['error'])) {
            // Truncate error message
            $error = $data['error'];
            if (strlen($error) > 150) {
                $error = substr($error, 0, 147) . '...';
            }
            $message .= "\n<b>Error:</b>\n<code>{$error}</code>\n";
        }
        
        // Add environment if not production
        if (app()->environment() !== 'production') {
            $message .= "\n<b>Environment:</b> " . strtoupper(app()->environment());
        }
        
        return $message;
    }
    
    public function sendTestMessage()
    {
        $message = "🔔 <b>Test Notification</b>\n\n";
        $message .= "Your deployment notification system is working!\n";
        $message .= "Environment: " . strtoupper(app()->environment()) . "\n";
        $message .= "Time: " . date('Y-m-d H:i:s');
        
        return $this->sendRawMessage($message);
    }
    
    public function sendRawMessage($text)
    {
        if (!$this->isConfigured()) {
            return false;
        }
        
        try {
            $response = Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
            
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}