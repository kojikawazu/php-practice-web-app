<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Laminas\Http\Header\HeaderInterface;
use Traversable;

/**
 * Content-Security-Policy ヘッダーの統合テスト（IT）。
 *
 * laminas はミドルウェア層を持たないため、Module::onBootstrap が MvcEvent::EVENT_FINISH
 * を購読してヘッダーを付与する。dispatch を通した実レスポンスで、その配線と
 * 「ヘッダーの nonce と PHTML の nonce が一致する」ことを検証する（`docs/06`）。
 */
class CspHeaderIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * CSP ヘッダーの値を取り出す。
     *
     * Laminas は既知でないヘッダーを GenericMultiHeader として扱うため、
     * Headers::get() は単一ヘッダーでもコレクション（Traversable）を返しうる。
     * どちらの形でも受け取れるようにする。
     */
    private function cspHeader(): string
    {
        $header = $this->getResponse()->getHeaders()->get('Content-Security-Policy');
        $this->assertNotFalse($header, 'CSP ヘッダーが付いていない');

        if ($header instanceof Traversable) {
            $values = [];
            foreach ($header as $one) {
                $this->assertInstanceOf(HeaderInterface::class, $one);
                $values[] = $one->getFieldValue();
            }
            $this->assertCount(1, $values, 'CSP ヘッダーは 1 つだけであるべき（重複は意図が曖昧になる）');

            // 上で 1 件だけと確認済み。implode で string を確実に返す（$values[0] は型が nullable になる）
            return implode('', $values);
        }

        $this->assertInstanceOf(HeaderInterface::class, $header);

        return $header->getFieldValue();
    }

    // ---- 正常系 ----

    public function testHtmlResponseHasCspHeader(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login');

        $csp = $this->cspHeader();
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function testNonceInHeaderMatchesNonceInHtml(): void
    {
        // 認証済みでタスク一覧を描画させる（インライン script を含む唯一の画面）
        $this->prepareServices($this->identity(1));
        $this->dispatch('/tasks');

        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('flatpickr', (string) $this->getResponse()->getContent(), 'タスク一覧が描画されていない');

        $csp = $this->cspHeader();
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m), 'ヘッダーに nonce がない');

        // インライン script はこの nonce でのみ実行を許可される
        $this->assertStringContainsString('nonce="' . $m[1] . '"', (string) $this->getResponse()->getContent());
    }

    public function testAllowsOnlyTheCdnsActuallyUsed(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login');

        $csp = $this->cspHeader();
        $this->assertStringContainsString('https://cdn.tailwindcss.com', $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        // 本アプリは OGP プレビューを持たないため、img-src に外部ホストを含めない
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringNotContainsString('img-src \'self\' data: https:', $csp);
    }

    // ---- 異常系（退行の検出）----

    public function testScriptSrcDoesNotAllowUnsafeInlineOrEval(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login');

        $scriptSrc = '';
        foreach (explode(';', $this->cspHeader()) as $part) {
            if (str_starts_with(trim($part), 'script-src ')) {
                $scriptSrc = trim($part);
            }
        }

        $this->assertNotSame('', $scriptSrc, 'script-src が定義されていない');
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function testPolicyDoesNotUseWildcard(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login');

        $this->assertStringNotContainsString('*', $this->cspHeader());
    }
}
