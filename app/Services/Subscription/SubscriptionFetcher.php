<?php

namespace App\Services\Subscription;

use App\Exceptions\ApiException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

class SubscriptionFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function fetch(string $url): string
    {
        try {
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $parts = parse_url($url);
                if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    throw new ApiException('订阅地址必须是有效的 HTTP 或 HTTPS 链接', 422);
                }
                $host = trim($parts['host'], '[]');
                $addresses = $this->resolve($host);
                if (!$addresses) {
                    throw new ApiException('无法解析订阅地址', 422);
                }
                foreach ($addresses as $address) {
                    if (!$this->isPublicAddress($address)) {
                        throw new ApiException('订阅地址必须指向公网地址', 422);
                    }
                }
                $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
                // Pin the validated DNS result, including on redirects, to prevent DNS rebinding.
                $ip = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
                $buffer = Utils::streamFor(fopen('php://temp', 'w+'));
                $bytes = 0;
                $sink = FnStream::decorate($buffer, ['write' => function ($chunk) use ($buffer, &$bytes) {
                    $bytes += strlen($chunk);
                    if ($bytes > self::MAX_BYTES) {
                        throw new ApiException('订阅内容超过 2 MB 限制', 422);
                    }
                    return $buffer->write($chunk);
                }]);
                try {
                    $response = Http::withHeaders(['User-Agent' => 'Clash.Meta/Xboard-Importer', 'Accept' => 'text/plain, application/yaml'])
                        ->connectTimeout(5)->timeout(20)->withOptions([
                            'allow_redirects' => false,
                            // Keep Guzzle's cURL handler: its stream handler does not honor CURLOPT_RESOLVE.
                            'sink' => $sink,
                            'proxy' => '',
                            'curl' => [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]],
                        ])->get($url);
                } catch (\Throwable $e) {
                    $sink->close();
                    throw $e;
                }
                $body = $response->toPsrResponse()->getBody();
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('Location');
                    $body->close();
                    $sink->close();
                    if (!$location || $redirects === 3) {
                        throw new ApiException('订阅重定向次数过多或地址无效', 422);
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                    continue;
                }
                if (!$response->successful()) {
                    $body->close();
                    $sink->close();
                    throw new ApiException('订阅下载失败，请检查链接是否有效', 422);
                }
                try {
                    $content = '';
                    while (!$body->eof()) {
                        $content .= $body->read(8192);
                        if (strlen($content) > self::MAX_BYTES) {
                            throw new ApiException('订阅内容超过 2 MB 限制', 422);
                        }
                    }
                    return $content;
                } finally {
                    $body->close();
                    $sink->close();
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Transport exceptions contain the full subscription URL and its credentials.
            throw new ApiException('订阅下载失败，请检查链接、证书或网络连接', 422);
        }
        throw new ApiException('订阅下载失败', 422);
    }

    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
    }

    private function isPublicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            return IpUtils::checkIp($address, '2000::/3')
                && !IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16']);
        }
        return !IpUtils::checkIp($address, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24',
            '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4']);
    }
}
