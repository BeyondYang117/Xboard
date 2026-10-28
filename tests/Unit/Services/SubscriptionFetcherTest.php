<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\Subscription\SubscriptionFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionFetcherTest extends TestCase
{
    private function fetcher(): SubscriptionFetcher
    {
        return new class extends SubscriptionFetcher {
            protected function resolve(string $host): array
            {
                return match ($host) {
                    'public.example' => ['8.8.8.8'],
                    'mixed.example' => ['8.8.8.8', '127.0.0.1'],
                    default => [$host],
                };
            }
        };
    }

    public function test_downloads_with_pinned_public_address(): void
    {
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen = $options;
            return Http::response('subscription');
        });
        $this->assertSame('subscription', $this->fetcher()->fetch('https://public.example/sub?token=secret'));
        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertSame('', $optionsSeen['proxy']);
        $this->assertSame(['public.example:443:8.8.8.8'], $optionsSeen['curl'][CURLOPT_RESOLVE]);
        $this->assertEmpty($optionsSeen['stream'] ?? null);
    }

    public function test_blocks_private_reserved_mixed_dns_and_non_http_urls_before_request(): void
    {
        Http::fake();
        foreach (['http://127.0.0.1/sub', 'http://10.0.0.1/sub', 'http://169.254.169.254/latest', 'http://[::1]/sub', 'http://[::ffff:127.0.0.1]/sub', 'https://mixed.example/sub', 'file:///etc/passwd', 'https://user:pass@public.example/sub'] as $url) {
            try {
                $this->fetcher()->fetch($url);
                $this->fail('Should reject URL');
            } catch (ApiException $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_validates_redirect_target(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('订阅地址必须指向公网地址');
        $this->fetcher()->fetch('https://public.example/sub');
    }

    public function test_follows_relative_redirects_and_limits_loops(): void
    {
        Http::fake(['https://public.example/sub' => Http::response('', 302, ['Location' => '/real']), 'https://public.example/real' => Http::response('ok')]);
        $this->assertSame('ok', $this->fetcher()->fetch('https://public.example/sub'));
        Http::fake(['*' => Http::response('', 302, ['Location' => '/loop'])]);
        $this->expectException(ApiException::class);
        $this->fetcher()->fetch('https://public.example/sub');
    }

    public function test_rejects_oversized_body(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', SubscriptionFetcher::MAX_BYTES + 1))]);
        $this->expectException(ApiException::class);
        $this->fetcher()->fetch('https://public.example/sub');
    }

    public function test_remote_error_does_not_expose_subscription_secret(): void
    {
        Http::fake(['*' => Http::response('secret error', 403)]);
        try {
            $this->fetcher()->fetch('https://public.example/sub?token=secret');
            $this->fail('Should reject response');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_sink_stops_download_while_writing(): void
    {
        Http::fake(function ($request, $options) {
            $options['sink']->write(str_repeat('x', SubscriptionFetcher::MAX_BYTES));
            $options['sink']->write('x');
            $this->fail('Download should stop at size limit');
        });
        $this->expectException(ApiException::class);
        $this->fetcher()->fetch('https://public.example/sub');
    }
}
