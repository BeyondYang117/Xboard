<?php

namespace App\Services\NodeProbe;

use Symfony\Component\Process\Process;

class ProxyRunner
{
    public function available(): bool
    {
        return is_executable(config('node_probe.binary')) && function_exists('proc_open') && function_exists('curl_init');
    }

    public function run(array $outbound, string $target): array
    {
        if (!$this->available()) return ['state' => 'error', 'stage' => 'engine', 'message' => '未安装测试内核或运行环境缺少进程/cURL 支持，请升级镜像'];
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$listener) return ['state' => 'error', 'stage' => 'engine', 'message' => '无法分配本地测试端口'];
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $username = bin2hex(random_bytes(12)); $password = bin2hex(random_bytes(24));
        $config = [
            'log' => ['disabled' => true],
            'inbounds' => [['type' => 'http', 'tag' => 'test-in', 'listen' => '127.0.0.1', 'listen_port' => $port, 'users' => [['username' => $username, 'password' => $password]]]],
            'outbounds' => [$outbound], 'route' => ['final' => 'probe'],
        ];
        $path = null; $process = null; $curl = null;
        try {
            $directory = storage_path('tmp');
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Temporary directory unavailable');
            $path = tempnam($directory, 'node-probe-');
            if ($path === false) throw new \RuntimeException('Temporary file unavailable');
            chmod($path, 0600);
            if (file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) throw new \RuntimeException('Cannot write configuration');
            $check = new Process([config('node_probe.binary'), 'check', '-c', $path]);
            $check->setTimeout(3); $check->disableOutput(); $check->run();
            if (!$check->isSuccessful()) return ['state' => 'unsupported', 'stage' => 'config', 'message' => '测试内核不接受此节点配置，请检查协议参数或使用客户端实测'];
            $process = new Process([config('node_probe.binary'), 'run', '-c', $path]);
            $process->setTimeout(12); $process->disableOutput(); $process->start();
            $deadline = microtime(true) + 1.5; $ready = false;
            while (microtime(true) < $deadline && $process->isRunning()) {
                $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.05);
                if ($socket) { fclose($socket); $ready = true; break; }
                usleep(25000);
            }
            if (!$ready) return ['state' => 'error', 'stage' => 'engine', 'message' => '测试内核未能启动'];
            $curl = curl_init($target);
            $bytes = 0;
            curl_setopt_array($curl, [
                CURLOPT_PROXY => "http://127.0.0.1:{$port}", CURLOPT_PROXYUSERPWD => "{$username}:{$password}", CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
                CURLOPT_NOPROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 8000,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Xboard-Node-Probe/1', CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$bytes) {
                    $bytes += strlen($chunk); return $bytes > 16384 ? 0 : strlen($chunk);
                },
            ]);
            $success = curl_exec($curl); $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $latency = (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);
            if ($success === false) return ['state' => 'failed', 'stage' => 'proxy', 'message' => $errno === CURLE_OPERATION_TIMEDOUT ? '代理请求超时（8 秒）' : '代理请求失败，请检查认证信息、TLS 配置和网络连接', 'latency_ms' => null];
            if ($status !== 204) return ['state' => 'failed', 'stage' => 'http', 'message' => "测试网站返回 HTTP {$status}，未达到预期的 204", 'http_status' => $status];
            return ['state' => 'ok', 'stage' => 'http', 'message' => '代理请求成功（HTTP 204）', 'http_status' => $status, 'latency_ms' => $latency];
        } catch (\Throwable $error) {
            // Never expose or log engine output/configuration: both contain credentials.
            return ['state' => 'error', 'stage' => 'engine', 'message' => '测试运行异常，请检查测试内核和服务器运行环境'];
        } finally {
            if ($curl) curl_close($curl);
            if ($process && $process->isRunning()) $process->stop(0.1);
            if ($path && is_file($path)) unlink($path);
        }
    }
}
