<?php

namespace App\Services\Subscription;

use App\Models\ServerSubscription;
use App\Services\NodeProbe\ProbeOutbound;
use App\Services\NodeProbe\PublicEndpoint;
use Symfony\Component\Process\Process;

/** One private sing-box process per worker; authenticated routing selects the upstream node. */
class GatewayEngine
{
    private ?Process $process = null;
    private ?string $path = null;
    public int $port;
    public string $password;

    public function start(ServerSubscription $subscription): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$listener) throw new \RuntimeException('无法分配中转内核端口');
        $this->port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $this->password = bin2hex(random_bytes(32));
        $outbounds = $rules = $users = [];
        foreach ($subscription->nodes()->where('show', true)->get() as $node) {
            $ip = app(PublicEndpoint::class)->addresses($node->host)[0];
            $outbound = app(ProbeOutbound::class)->build($node, null, $ip, (int) $node->port);
            $outbound['tag'] = 'upstream-' . $node->id;
            $outbounds[] = $outbound;
            $rules[] = ['auth_user' => [(string) $node->id], 'action' => 'route', 'outbound' => $outbound['tag']];
            $users[] = ['username' => (string) $node->id, 'password' => $this->password];
        }
        if (!$outbounds) throw new \RuntimeException('没有可用的机场节点');
        $config = ['log' => ['disabled' => true],
            'inbounds' => [['type' => 'http', 'listen' => '127.0.0.1', 'listen_port' => $this->port, 'users' => $users]],
            'outbounds' => $outbounds,
            // No direct outbound exists: missing routing must never bypass the provider.
            'route' => ['rules' => $rules, 'final' => $outbounds[0]['tag']],
        ];
        $directory = storage_path('subscription-gateway');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('无法创建中转目录');
        $this->path = tempnam($directory, 'engine-');
        chmod($this->path, 0600);
        file_put_contents($this->path, json_encode($config, JSON_THROW_ON_ERROR));
        $check = new Process([config('node_probe.binary'), 'check', '-c', $this->path]);
        $check->setTimeout(10); $check->disableOutput(); $check->run();
        if (!$check->isSuccessful()) throw new \RuntimeException('中转内核不接受机场节点配置，请检查协议参数');
        $this->process = new Process([config('node_probe.binary'), 'run', '-c', $this->path]);
        $this->process->setTimeout(null); $this->process->disableOutput(); $this->process->start();
        $deadline = microtime(true) + 3;
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.05);
            if ($socket) { fclose($socket); return; }
            usleep(25000);
        } while ($this->isRunning() && microtime(true) < $deadline);
        throw new \RuntimeException('中转内核启动失败');
    }

    public function isRunning(): bool { return $this->process && $this->process->isRunning(); }

    public function stop(): void
    {
        if ($this->isRunning()) $this->process->stop(0.2);
        if ($this->path && is_file($this->path)) unlink($this->path);
        $this->process = null; $this->path = null;
    }

    public function __destruct() { $this->stop(); }
}
