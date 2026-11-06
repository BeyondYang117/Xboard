<?php

namespace App\Console\Commands;

use App\Models\ServerSubscription;
use App\Services\Subscription\ConnectRequest;
use App\Services\Subscription\GatewayPool;
use App\Services\Subscription\SubscriptionGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class SubscriptionGatewayServer extends Command
{
    protected $signature = 'subscription:gateway {id? : Optional airport ID for a dedicated legacy listener; omit for all airports} {--host=0.0.0.0} {--cert= : TLS certificate PEM} {--key= : TLS private key PEM}';
    protected $description = 'Run an authenticated TLS CONNECT gateway with provider-wide admission and metered traffic';

    public function handle(): int
    {
        $id = $this->argument('id');
        $subscription = $id ? ServerSubscription::findOrFail($id) : null;
        if ($subscription && $subscription->delivery_mode !== 'relay') {
            $this->error('请先配置机场的中转计费模式'); return self::FAILURE;
        }
        $endpoint = $subscription ? $subscription->gatewayEndpoint() : [
            'host' => config('subscription_gateway.host'), 'port' => config('subscription_gateway.port', 2443),
        ];
        if (!$endpoint['host'] || (!filter_var($endpoint['host'], FILTER_VALIDATE_IP)
            && !filter_var($endpoint['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            || $endpoint['port'] < 1 || $endpoint['port'] > 65535) {
            $this->error('请在 .env 配置 SUBSCRIPTION_GATEWAY_HOST 和有效的 SUBSCRIPTION_GATEWAY_PORT'); return self::FAILURE;
        }
        $cert = $this->option('cert') ?: config('subscription_gateway.cert');
        $key = $this->option('key') ?: config('subscription_gateway.key');
        if (!is_readable((string) $cert) || !is_readable((string) $key)
            || !is_executable(config('node_probe.binary')) || !function_exists('pcntl_fork')) {
            $this->error('需要可读的 TLS 证书/私钥、sing-box 内核及 pcntl 扩展'); return self::FAILURE;
        }
        global $argv;
        $argv = [$argv[0], 'start'];
        $directory = storage_path('subscription-gateway/' . ($subscription?->id ?? 'shared'));
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        Worker::$pidFile = $directory . '/gateway.pid';
        Worker::$logFile = $directory . '/worker.log';
        $worker = new Worker('tcp://' . $this->option('host') . ':' . $endpoint['port'], [
            'ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false],
        ]);
        $worker->transport = 'ssl'; $worker->name = 'subscription-' . ($subscription?->id ?? 'shared'); $worker->count = 1;
        $pool = new GatewayPool(); $connections = []; $pending = [];
        $gateway = app(SubscriptionGateway::class);
        $spool = function (string $id, int $u, int $d) use ($directory) {
            $path = $directory . '/session-' . $id . '.json';
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, json_encode([$id, $u, $d]), LOCK_EX) === false) throw new \RuntimeException('无法保存计费记录');
            chmod($tmp, 0600); rename($tmp, $path);
            return $path;
        };
        $flush = function (object $state, bool $close = false) use ($gateway, $spool, &$pending): bool {
            if (!$state->session) return false;
            try {
                $path = $spool($state->session, $state->u, $state->d);
                $allowed = $gateway->record($state->session, $state->u, $state->d, $close);
                @unlink($path); unset($pending[$state->session]);
                return $allowed;
            } catch (\Throwable $e) {
                // Fail closed; retry cumulative totals without logging credentials or destinations.
                $pending[$state->session] = [$state->u, $state->d];
                return false;
            }
        };
        $refresh = function () use ($subscription, $pool, &$connections) {
            $query = ServerSubscription::with(['nodes' => fn ($query) => $query->where('show', true)->orderBy('id')])
                ->where('delivery_mode', 'relay');
            if ($subscription) {
                $query->whereKey($subscription->id);
                if (!config('subscription_gateway.host')) {
                    $query->where('gateway_host', $subscription->gateway_host)->where('gateway_port', $subscription->gateway_port);
                }
            }
            $pool->refresh($query->get(), function (int $id) use (&$connections) {
                foreach ($connections as $client) {
                    if ($client->gatewayState->subscriptionId === $id) $client->close();
                }
            });
            if (!$subscription) Cache::put('subscription-gateway:shared', true, 30);
        };
        $worker->onWorkerStart = function () use ($refresh, $flush, $directory, $gateway, &$pending, &$connections) {
            foreach (glob($directory . '/session-*.json') as $path) {
                $record = json_decode(file_get_contents($path), true);
                if (is_array($record) && count($record) === 3) $pending[$record[0]] = [$record[1], $record[2]];
            }
            $refresh();
            Timer::add(10, function () use ($refresh, $flush, $directory, $gateway, &$pending, &$connections) {
                foreach ($pending as $id => [$u, $d]) {
                    try { $gateway->record($id, $u, $d, true); unset($pending[$id]); @unlink($directory . '/session-' . $id . '.json'); }
                    catch (\Throwable $e) { /* Leave durable totals for the next retry. */ }
                }
                foreach ($connections as $client) {
                    $state = $client->gatewayState;
                    if ((!$state->session && time() - $state->opened > 15)
                        || ($state->session && (!$flush($state) || time() - $state->lastData > 300))) $client->close();
                }
                try { $refresh(); }
                catch (\Throwable $e) { foreach ($connections as $client) $client->close(); }
            });
        };
        $worker->onConnect = function (TcpConnection $client) use (&$connections) {
            $client->gatewayState = (object) ['buffer' => '', 'session' => null, 'upstream' => null,
                'subscriptionId' => null, 'ready' => false, 'u' => 0, 'd' => 0, 'opened' => time(), 'lastData' => time()];
            $client->maxSendBufferSize = 1048576; $connections[$client->id] = $client;
        };
        $worker->onMessage = function (TcpConnection $client, string $data) use ($gateway, $pool) {
            $state = $client->gatewayState; $state->lastData = time();
            if ($state->ready) {
                if ($state->upstream->send($data) === false) { $client->close(); return; }
                $state->u += strlen($data); return;
            }
            if ($state->session) { $client->close(); return; }
            $state->buffer .= $data;
            try {
                $request = ConnectRequest::parse($state->buffer);
                if (!$request) return;
                $route = $pool->route($request['node_id']);
                $engine = $route['engine'];
                $state->subscriptionId = $route['subscription_id'];
                $state->session = $gateway->open($state->subscriptionId, $request['node_id'], $request['credential'], $client->getRemoteIp());
                $state->buffer = '';
                $upstream = new AsyncTcpConnection('tcp://127.0.0.1:' . $engine->port);
                $state->upstream = $upstream; $upstream->maxSendBufferSize = 1048576;
                $auth = base64_encode($request['node_id'] . ':' . $engine->password);
                $upstream->onConnect = function ($connection) use ($request, $auth) {
                    $connection->send("CONNECT {$request['target']} HTTP/1.1\r\nHost: {$request['target']}\r\nProxy-Authorization: Basic {$auth}\r\n\r\n");
                };
                $response = '';
                $upstream->onMessage = function ($connection, $chunk) use ($client, $state, $request, &$response) {
                    $state->lastData = time();
                    if (!$state->ready) {
                        $response .= $chunk; $end = strpos($response, "\r\n\r\n");
                        if (strlen($response) > 16384) { $client->close(); return; }
                        if ($end === false) return;
                        if (!preg_match('/^HTTP\/1\.[01] 200(?: |\r)/', $response)) { $client->close("HTTP/1.1 502 Bad Gateway\r\n\r\n"); return; }
                        $state->ready = true;
                        $client->send("HTTP/1.1 200 Connection Established\r\n\r\n");
                        if ($request['remaining'] !== '') { $connection->send($request['remaining']); $state->u += strlen($request['remaining']); }
                        $chunk = substr($response, $end + 4); $response = '';
                        $client->resumeRecv();
                    }
                    if ($chunk !== '') { $client->send($chunk); $state->d += strlen($chunk); }
                };
                $upstream->onClose = fn () => $client->close();
                $upstream->onError = fn () => $client->close();
                $upstream->onBufferFull = fn () => $client->pauseRecv();
                $upstream->onBufferDrain = fn () => $client->resumeRecv();
                $client->pauseRecv(); $upstream->connect();
                Timer::add(10, function () use ($client, $state) { if (!$state->ready) $client->close(); }, [], false);
            } catch (\Throwable $e) { $client->close("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n"); }
        };
        $worker->onBufferFull = fn ($client) => $client->gatewayState->upstream?->pauseRecv();
        $worker->onBufferDrain = fn ($client) => $client->gatewayState->upstream?->resumeRecv();
        $worker->onClose = function ($client) use ($flush, &$connections) {
            unset($connections[$client->id]);
            $state = $client->gatewayState;
            if ($state->upstream) { $state->upstream->onClose = null; $state->upstream->close(); }
            if ($state->session) $flush($state, true);
        };
        $worker->onWorkerStop = function () use ($subscription, $pool, &$connections) {
            foreach ($connections as $client) $client->close();
            $pool->stop();
            if (!$subscription) Cache::forget('subscription-gateway:shared');
        };
        Worker::runAll();
        return self::SUCCESS;
    }
}
