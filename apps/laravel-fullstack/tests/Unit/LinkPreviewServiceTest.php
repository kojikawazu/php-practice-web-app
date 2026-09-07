<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BlockedUrlException;
use App\Services\LinkPreviewService;
use PHPUnit\Framework\TestCase;

class LinkPreviewServiceTest extends TestCase
{
    private LinkPreviewService $service;

    protected function setUp(): void
    {
        $this->service = new LinkPreviewService;
    }

    // ---- 正常系 ----

    public function test_public_ips_are_allowed(): void
    {
        $this->assertTrue($this->service->isPublicIp('1.1.1.1'));
        $this->assertTrue($this->service->isPublicIp('8.8.8.8'));
        $this->assertTrue($this->service->isPublicIp('93.184.216.34'));
    }

    public function test_extract_title_prefers_og_title(): void
    {
        $html = '<html><head><meta property="og:title" content="OGタイトル"><title>素タイトル</title></head></html>';
        $this->assertSame('OGタイトル', $this->service->extractTitle($html));
    }

    public function test_extract_title_falls_back_to_title_tag(): void
    {
        $html = '<html><head><title>素タイトル</title></head></html>';
        $this->assertSame('素タイトル', $this->service->extractTitle($html));
    }

    public function test_extract_og_image_returns_absolute_http_url(): void
    {
        $html = '<meta property="og:image" content="https://example.com/a.png">';
        $this->assertSame('https://example.com/a.png', $this->service->extractOgImage($html));
    }

    // ---- 異常系（SSRF 対策の核）----

    public function test_private_and_reserved_ips_are_blocked(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '::1'] as $ip) {
            $this->assertFalse($this->service->isPublicIp($ip), "{$ip} は拒否されるべき");
        }
    }

    public function test_non_http_scheme_is_blocked(): void
    {
        $this->expectException(BlockedUrlException::class);
        $this->service->fetch('file:///etc/passwd');
    }

    public function test_loopback_host_is_blocked(): void
    {
        $this->expectException(BlockedUrlException::class);
        $this->service->fetch('http://127.0.0.1/');
    }

    public function test_private_host_is_blocked(): void
    {
        $this->expectException(BlockedUrlException::class);
        $this->service->fetch('http://192.168.0.1/');
    }

    public function test_extract_og_image_rejects_non_http(): void
    {
        $html = '<meta property="og:image" content="javascript:alert(1)">';
        $this->assertNull($this->service->extractOgImage($html));
    }
}
