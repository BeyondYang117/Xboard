<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerSubscription;
use App\Services\Subscription\GatewayEngine;
use App\Services\Subscription\GatewayPool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GatewayPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
    }

    private function airport(int $id): ServerSubscription
    {
        $source = new ServerSubscription();
        $source->forceFill(['id' => $id, 'enabled' => true, 'delivery_mode' => 'relay',
            'gateway_host' => 'relay.example.com', 'gateway_port' => 2443]);
        $node = new Server();
        $node->forceFill(['id' => $id * 10, 'subscription_id' => $id, 'type' => 'trojan', 'host' => 'node.example.com',
            'port' => '443', 'show' => true, 'protocol_settings' => []]);
        return $source->setRelation('nodes', new Collection([$node]));
    }

    public function test_starts_empty_then_discovers_multiple_airports_without_restarting_listener(): void
    {
        $engines = [];
        $pool = new GatewayPool(function ($source) use (&$engines) {
            return $engines[$source->id] = new TestGatewayEngine();
        });
        $disconnected = [];
        $disconnect = function ($id) use (&$disconnected) { $disconnected[] = $id; };
        $pool->refresh([], $disconnect);
        $one = $this->airport(1); $two = $this->airport(2);
        $pool->refresh([$one], $disconnect);
        $this->assertSame(1, $pool->route(10)['subscription_id']);
        $pool->refresh([$one, $two], $disconnect);
        $this->assertSame(2, $pool->route(20)['subscription_id']);
        $this->assertNotSame($pool->route(10)['engine'], $pool->route(20)['engine']);
        $this->assertSame(1, $engines[1]->starts);
        $this->assertTrue(Cache::get('subscription-gateway:1'));
        $this->assertTrue(Cache::get('subscription-gateway:2'));
        $disconnected = [];
        $one->nodes[0]->host = 'changed.example.com';
        $old = $engines[1];
        $pool->refresh([$one, $two], $disconnect);
        $this->assertSame([1], $disconnected);
        $this->assertFalse($old->running);
        $this->assertSame(1, $engines[2]->starts);
        $pool->stop();
    }

    public function test_deletion_and_disable_only_release_the_affected_airport(): void
    {
        $pool = new GatewayPool(fn () => new TestGatewayEngine());
        $one = $this->airport(1); $two = $this->airport(2);
        $disconnected = [];
        $disconnect = function ($id) use (&$disconnected) { $disconnected[] = $id; };
        $pool->refresh([$one, $two], $disconnect);
        $disconnected = [];
        $pool->refresh([$two], $disconnect);
        $this->assertSame([1], $disconnected);
        $this->assertNull(Cache::get('subscription-gateway:1'));
        $this->assertSame(2, $pool->route(20)['subscription_id']);
        $two->enabled = false;
        $pool->refresh([$two], $disconnect);
        $this->assertNull(Cache::get('subscription-gateway:2'));
        $this->expectException(\DomainException::class);
        $pool->route(20);
    }

    public function test_broken_airport_does_not_stop_other_airports_and_can_recover(): void
    {
        $broken = true;
        $pool = new GatewayPool(function ($source) use (&$broken) {
            $engine = new TestGatewayEngine();
            $engine->fail = $source->id === 1 && $broken;
            return $engine;
        });
        $sources = [$this->airport(1), $this->airport(2)];
        $pool->refresh($sources, fn () => null);
        $this->assertNull(Cache::get('subscription-gateway:1'));
        $this->assertSame(2, $pool->route(20)['subscription_id']);
        $broken = false;
        $pool->refresh($sources, fn () => null);
        $this->assertSame(1, $pool->route(10)['subscription_id']);
        $pool->stop();
    }

    public function test_dead_engine_hidden_node_and_unknown_node_are_not_routed(): void
    {
        $engine = new TestGatewayEngine();
        $pool = new GatewayPool(fn () => $engine);
        $source = $this->airport(1);
        $pool->refresh([$source], fn () => null);
        $engine->running = false;
        try { $pool->route(10); $this->fail('Dead engines must fail closed'); }
        catch (\DomainException) {}
        $source->nodes[0]->show = false;
        $pool->refresh([$source], fn () => null);
        $this->assertNull(Cache::get('subscription-gateway:1'));
        $this->expectException(\DomainException::class);
        $pool->route(999);
    }
}

class TestGatewayEngine extends GatewayEngine
{
    public int $starts = 0;
    public bool $running = false;
    public bool $fail = false;

    public function start(ServerSubscription $subscription): void
    {
        $this->starts++;
        if ($this->fail) throw new \RuntimeException('Invalid test outbound');
        $this->running = true;
    }

    public function isRunning(): bool { return $this->running; }
    public function stop(): void { $this->running = false; }
}
