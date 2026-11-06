<?php

namespace App\Services\Subscription;

use App\Jobs\StatServerJob;
use App\Models\Server;
use App\Models\ServerSubscription;
use App\Models\User;
use App\Models\StatUser;
use App\Services\DeviceStateService;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared admission and cumulative, idempotent accounting for every gateway worker. */
class SubscriptionGateway
{
    public const LEASE_SECONDS = 60;

    private function active()
    {
        return DB::table('v2_subscription_session')->whereNull('closed_at')->where('expires_at', '>', time());
    }

    public function online(int $subscriptionId): array
    {
        $sessions = $this->active()->where('subscription_id', $subscriptionId)->get(['user_id', 'ip']);
        return [
            'online_users' => $sessions->pluck('user_id')->unique()->count(),
            'online_ips' => $sessions->pluck('ip')->unique()->count(),
            'online_connections' => $sessions->count(),
        ];
    }

    private function allowed(?ServerSubscription $subscription, ?Server $node, ?User $user): bool
    {
        return $subscription && $subscription->delivery_mode === 'relay' && $subscription->isAvailable()
            && $node && $node->show && (int) $node->subscription_id === $subscription->id
            && (!$node->transfer_enable || $node->u + $node->d < $node->transfer_enable)
            && $user && !$user->banned && (!$user->expired_at || $user->expired_at > time())
            && $user->u + $user->d < $user->transfer_enable
            && in_array((string) $user->group_id, $node->group_ids ?? [], true)
            && in_array((string) $user->group_id, $subscription->group_ids ?? [], true);
    }

    private function userIps(int $userId): array
    {
        $managed = app(DeviceStateService::class)->getUsersDevices([$userId])[$userId] ?? [];
        return array_values(array_unique(array_merge($managed, $this->active()->where('user_id', $userId)->pluck('ip')->all())));
    }

    private function updateUserOnline(int $userId): void
    {
        $ips = $this->userIps($userId);
        User::whereKey($userId)->update(['online_count' => count($ips), 'last_online_at' => now()]);
    }

    private function withinCurrentLimit(ServerSubscription $subscription, object $session): bool
    {
        if (!$subscription->max_online) return true;
        $key = match ($subscription->online_limit_mode) { 'user' => 'user_id', 'connection' => 'id', default => 'ip' };
        $admitted = $this->active()->where('subscription_id', $subscription->id)->orderBy('id')->get(['id', 'user_id', 'ip'])
            ->pluck($key)->unique()->take($subscription->max_online);
        return $admitted->contains($session->{$key});
    }

    public function open(int $subscriptionId, int $nodeId, string $credential, string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new \DomainException('无效的客户端 IP');
        $ip = inet_ntop(inet_pton($ip));
        return DB::transaction(function () use ($subscriptionId, $nodeId, $credential, $ip) {
            // Lock the provider first: admission across nodes/workers/hosts is serialized.
            $subscription = ServerSubscription::lockForUpdate()->find($subscriptionId);
            $node = Server::find($nodeId);
            $user = User::where('uuid', $credential)->lockForUpdate()->first();
            if (!$this->allowed($subscription, $node, $user)) throw new \DomainException('账号或机场节点不可用');
            $sessions = $this->active()->where('subscription_id', $subscriptionId)->get(['user_id', 'ip']);
            $mode = $subscription->online_limit_mode;
            $count = match ($mode) {
                'user' => $sessions->pluck('user_id')->unique()->count(),
                'connection' => $sessions->count(),
                default => $sessions->pluck('ip')->unique()->count(),
            };
            $existing = match ($mode) {
                'user' => $sessions->contains('user_id', $user->id),
                'connection' => false,
                default => $sessions->contains('ip', $ip),
            };
            if ($subscription->max_online && !$existing && $count >= $subscription->max_online) {
                throw new \DomainException('机场在线名额已满');
            }
            $ips = $this->userIps($user->id);
            if ($user->device_limit > 0 && !in_array($ip, $ips, true) && count($ips) >= $user->device_limit) {
                throw new \DomainException('账号在线设备已达到上限');
            }
            $id = Str::random(64);
            DB::table('v2_subscription_session')->insert([
                'id' => $id, 'subscription_id' => $subscriptionId, 'server_id' => $nodeId,
                'user_id' => $user->id, 'ip' => $ip, 'protocol' => $node->type,
                'rate' => $node->getCurrentRate(), 'expires_at' => time() + self::LEASE_SECONDS,
            ]);
            $this->updateUserOnline($user->id);
            $this->updateNodeOnline($node);
            return $id;
        }, 3);
    }

    /** Totals are per connection, so retransmitting a final flush cannot double-charge. */
    public function record(string $id, int $upload, int $download, bool $close = false): bool
    {
        if ($upload < 0 || $download < 0) throw new \InvalidArgumentException('流量不能为负数');
        $lookup = DB::table('v2_subscription_session')->where('id', $id)->first();
        if (!$lookup) return false;
        return DB::transaction(function () use ($lookup, $id, $upload, $download, $close) {
            $subscription = ServerSubscription::lockForUpdate()->find($lookup->subscription_id);
            $session = DB::table('v2_subscription_session')->where('id', $id)->lockForUpdate()->first();
            $user = User::lockForUpdate()->find($session->user_id);
            $node = Server::find($session->server_id);
            if ($upload < $session->u || $download < $session->d) throw new \InvalidArgumentException('累计流量不能回退');
            $delta = [$upload - $session->u, $download - $session->d];
            $rate = $node ? $node->getCurrentRate() : (float) $session->rate;
            $factor = (int) round($rate * 100);
            $weightedU = $delta[0] * $factor + $session->upload_remainder;
            $weightedD = $delta[1] * $factor + $session->download_remainder;
            $billedU = intdiv($weightedU, 100); $billedD = intdiv($weightedD, 100);
            if (array_sum($delta) > 0) {
                if ($user) {
                    // Synchronous quota debit; asynchronous queue delay cannot grant extra quota.
                    $user->u += $billedU;
                    $user->d += $billedD;
                    $user->t = time();
                    $user->saveQuietly();
                    $stat = StatUser::firstOrNew(['user_id' => $user->id, 'server_rate' => $rate,
                        'record_at' => strtotime(date('Y-m-d')), 'record_type' => 'd']);
                    $stat->u = ($stat->u ?? 0) + $billedU; $stat->d = ($stat->d ?? 0) + $billedD;
                    $stat->save();
                }
                (new StatServerJob(['id' => $session->server_id, 'rate' => $rate], [$session->user_id => $delta], $session->protocol))->handle();
                if ($subscription) {
                    $subscription->u += $delta[0]; $subscription->d += $delta[1];
                    $subscription->save();
                }
                if ($node) $node->refresh();
            }
            $allowed = !$close && !$session->closed_at && $session->expires_at > time()
                && $this->allowed($subscription, $node, $user) && $this->withinCurrentLimit($subscription, $session);
            DB::table('v2_subscription_session')->where('id', $id)->update([
                'u' => $upload, 'd' => $download, 'rate' => $rate,
                'upload_remainder' => $weightedU % 100, 'download_remainder' => $weightedD % 100,
                'expires_at' => $allowed ? time() + self::LEASE_SECONDS : time(),
                'closed_at' => $allowed ? null : ($session->closed_at ?? time()),
            ]);
            $this->updateUserOnline($session->user_id);
            if ($node) $this->updateNodeOnline($node);
            return $allowed;
        }, 3);
    }

    private function updateNodeOnline(Server $node): void
    {
        $count = $this->active()->where('server_id', $node->id)->distinct()->count('user_id');
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_ONLINE_USER', $node->id), $count, self::LEASE_SECONDS);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_PUSH_AT', $node->id), time(), self::LEASE_SECONDS);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_METRICS', $node->id), [
            'active_connections' => $this->active()->where('server_id', $node->id)->count(),
        ], self::LEASE_SECONDS);
    }
}
