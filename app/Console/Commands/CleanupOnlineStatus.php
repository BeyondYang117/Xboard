<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\DeviceStateService;

class CleanupOnlineStatus extends Command
{
    protected $signature = 'cleanup:online-status';

    protected $description = 'Reset stale online_count for users whose devices have expired from Redis';

    public function handle(): void
    {
        $expired = DB::table('v2_subscription_session')->whereNull('closed_at')->where('expires_at', '<=', time());
        $users = (clone $expired)->distinct()->pluck('user_id');
        $expired->update(['closed_at' => time()]);
        foreach ($users as $userId) app(DeviceStateService::class)->notifyUpdate($userId);
        DB::table('v2_subscription_session')->where('expires_at', '<', time() - 30 * 86400)->delete();
        $affected = User::where('online_count', '>', 0)
            ->where(function ($query) {
                $query->where('last_online_at', '<', now()->subMinutes(10))
                    ->orWhereNull('last_online_at');
            })
            ->update(['online_count' => 0]);

        if ($affected > 0) {
            $this->info("Reset online_count for {$affected} stale users.");
        }
    }
}
