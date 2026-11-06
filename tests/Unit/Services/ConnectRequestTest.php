<?php

namespace Tests\Unit\Services;

use App\Services\Subscription\ConnectRequest;
use PHPUnit\Framework\TestCase;

class ConnectRequestTest extends TestCase
{
    private function request(string $target = 'example.com:443'): string
    {
        $credential = '12345678-1234-1234-1234-123456789abc.42';
        return "CONNECT {$target} HTTP/1.1\r\nProxy-Authorization: Basic " . base64_encode("{$credential}:{$credential}") . "\r\n\r\n";
    }

    public function test_parses_authenticated_connect_and_preserves_initial_payload(): void
    {
        $parsed = ConnectRequest::parse($this->request('[2001:4860:4860::8888]:443') . 'initial-data');
        $this->assertSame(42, $parsed['node_id']);
        $this->assertSame('12345678-1234-1234-1234-123456789abc', $parsed['credential']);
        $this->assertSame('[2001:4860:4860::8888]:443', $parsed['target']);
        $this->assertSame('initial-data', $parsed['remaining']);
        $this->assertNull(ConnectRequest::parse("CONNECT example.com:443 HTTP/1.1\r\n"));
    }

    public function test_rejects_missing_authentication(): void
    {
        $this->expectException(\DomainException::class);
        ConnectRequest::parse("CONNECT example.com:443 HTTP/1.1\r\n\r\n");
    }

    public function test_rejects_duplicate_authorization(): void
    {
        $this->expectException(\DomainException::class);
        ConnectRequest::parse(str_replace("\r\n\r\n", "\r\nProxy-Authorization: Basic eDp4\r\n\r\n", $this->request()));
    }

    public function test_rejects_invalid_ports(): void
    {
        $this->expectException(\DomainException::class);
        ConnectRequest::parse($this->request('example.com:65536'));
    }

    public function test_rejects_unbounded_headers(): void
    {
        $this->expectException(\DomainException::class);
        ConnectRequest::parse(str_repeat('x', 16385));
    }
}
