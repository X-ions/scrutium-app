<?php

namespace App\Jobs\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\SecurityNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SecurityDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [300, 900];
    public $timeout = 120;

    protected int $userId;
    protected int $hours;

    public function __construct(int $userId, int $hours = 24)
    {
        $this->userId = $userId;
        $this->hours = $hours;
        $this->onQueue('security-digests');
    }

    public function handle(SecurityNotificationService $notificationService): void
    {
        $user = User::find($this->userId);
        
        if (!$user) {
            Log::warning('User not found for security digest', ['user_id' => $this->userId]);
            return;
        }

        try {
            $notificationService->sendBulkDigest($user, now()->subHours($this->hours));
            
            Log::info('Security digest sent', [
                'user_id' => $user->id,
                'hours' => $this->hours,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send security digest', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}