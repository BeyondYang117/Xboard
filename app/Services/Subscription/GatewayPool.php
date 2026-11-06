<?php

namespace App\Services\Subscription;

use Closure;
use App\Models\ServerSubscription;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** A shared listener discovers airports while isolating each airport's outbound lifecycle. */
class GatewayPool
{
    private array $engines = [];
    private array $versions = [];
    private array $routes = [];

    public function __construct(private ?Closure $factory = null) {}

    public function refresh(iterable $subscriptions, Closure $disconnect): void
    {
        $seen = [];
        $routes = [];
        foreach ($subscriptions as $subscription) {
            $id = $subscription->id;
            $seen[$id] = true;
            $nodes = $subscription->nodes->where('show', true)->sortBy('id');
            $version = hash('sha256', json_encode([$subscription->gatewayEndpoint(), $subscription->delivery_mode,
                $subscription->isAvailable(), $nodes->map(fn ($node) => array_intersect_key(
                    $node->getAttributes(), array_flip(['id', 'type', 'host', 'port', 'protocol_settings', 'external_password'])
                ))->values()->all()]));
            if (($this->versions[$id] ?? null) !== $version || !($this->engines[$id] ?? null)?->isRunning()) {
                $disconnect($id);
                $this->remove($id);
                if (!$subscription->isAvailable() || $subscription->delivery_mode !== 'relay' || $nodes->isEmpty()) continue;
                $engine = $this->factory ? ($this->factory)($subscription) : new GatewayEngine();
                try {
                    $engine->start($subscription);
                    $this->engines[$id] = $engine;
                    $this->versions[$id] = $version;
                } catch (\Throwable $e) {
                    $engine->stop();
                    Log::warning('Airport gateway engine unavailable', ['subscription_id' => $id]);
                    continue;
                }
            }
            Cache::put('subscription-gateway:' . $id, true, 30);
            foreach ($nodes as $node) {
                $routes[$node->id] = $id;
                ServerService::touchNode($node);
            }
        }
        foreach (array_keys($this->engines) as $id) {
            if (!isset($seen[$id])) { $disconnect($id); $this->remove($id); }
        }
        $this->routes = $routes;
    }

    public function route(int $nodeId): array
    {
        $id = $this->routes[$nodeId] ?? null;
        $engine = $this->engines[$id] ?? null;
        if (!$engine?->isRunning()) throw new \DomainException('中转不可用');
        return ['subscription_id' => $id, 'engine' => $engine];
    }

    private function remove(int $id): void
    {
        ($this->engines[$id] ?? null)?->stop();
        unset($this->engines[$id], $this->versions[$id]);
        Cache::forget('subscription-gateway:' . $id);
    }

    public function stop(): void
    {
        foreach (array_keys($this->engines) as $id) $this->remove($id);
        $this->routes = [];
    }
}
