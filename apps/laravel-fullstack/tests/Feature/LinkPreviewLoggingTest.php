<?php

namespace Tests\Feature;

use App\Services\LinkPreviewService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LinkPreviewService が「失敗を無言で捨てない」ことを検証する（issue #67）。
 *
 * プレビュー取得の失敗はユーザー操作を止めない設計（null を返して継続）だが、
 * ログにも何も残らないと**壊れたこと自体に誰も気づけない**。ここでは失敗の各経路で
 * warning が出ること、ログの中身が原因究明に足りること、そして Error（プログラミング
 * バグ）は握りつぶさず伝播することを固定する。
 *
 * 既存の tests/Unit/LinkPreviewServiceTest.php はコンテナを起動しない素の PHPUnit
 * テストのため、Http::fake / Log ファサードが使えず fetch() の HTTP 経路を検証できない。
 * そのため本クラスは Feature 側に置く（分類上は IT。docs/08 の粒度定義に従う）。
 *
 * ホストに public IP リテラルを使うのは、resolveSafeIp() の DNS 解決を避けるため
 * （テストを外部 DNS に依存させない）。Http::fake があるので実通信は発生しない。
 */
class LinkPreviewLoggingTest extends TestCase
{
    /** public IP リテラル（DNS 解決不要でブロック判定を通る） */
    private const URL = 'http://93.184.216.34/page';

    private LinkPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LinkPreviewService;
        Log::spy();
    }

    /**
     * 直近の warning ログのコンテキストを取り出す。
     *
     * @return array<string, mixed>
     */
    private function loggedContext(): array
    {
        $captured = [];

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$captured): bool {
                $captured = $context;

                return $message === 'URL preview failed';
            });

        return $captured;
    }

    // ---- 準正常系（外部要因の失敗。ユーザー操作は継続する）----

    public function test_connection_failure_returns_null_and_logs_warning(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertNull($this->service->fetch(self::URL));

        $context = $this->loggedContext();
        $this->assertSame('request_failed', $context['reason']);
        $this->assertSame('93.184.216.34', $context['host']);
        // スタックトレース付きで記録するため、例外オブジェクトそのものを渡している
        // （.claude/rules/error-handling.md「スタックトレースを含めてログ出力する」）
        $this->assertInstanceOf(ConnectionException::class, $context['exception']);
    }

    public function test_http_error_status_is_logged(): void
    {
        Http::fake(fn () => Http::response('', 503));

        $this->assertNull($this->service->fetch(self::URL));

        $context = $this->loggedContext();
        $this->assertSame('http_error', $context['reason']);
        // ステータスが無いと「相手が落ちている」のか「こちらの不具合」なのか切り分けられない
        $this->assertSame(503, $context['status']);
    }

    public function test_redirect_without_location_is_logged(): void
    {
        Http::fake(fn () => Http::response('', 302));

        $this->assertNull($this->service->fetch(self::URL));

        $this->assertSame('redirect_without_location', $this->loggedContext()['reason']);
    }

    public function test_too_many_redirects_is_logged_with_depth(): void
    {
        // リダイレクト上限を超えた状態を直接指定する（実際は fetch の再帰で到達する）
        $this->assertNull($this->service->fetch(self::URL, 4));

        $context = $this->loggedContext();
        $this->assertSame('too_many_redirects', $context['reason']);
        $this->assertSame(4, $context['depth']);
    }

    // ---- ログの内容（センシティブ情報を含めない）----

    public function test_log_context_records_host_only_not_the_full_url(): void
    {
        Http::fake(fn () => Http::response('', 500));

        $this->service->fetch('http://93.184.216.34/page?token=super-secret');

        $context = $this->loggedContext();
        // URL 全体を記録すると、クエリ文字列のトークンや user:pass@ がログに残る
        // （.claude/rules/error-handling.md「センシティブ情報はログに含めない」）
        $this->assertArrayNotHasKey('url', $context);
        $this->assertStringNotContainsString('super-secret', json_encode($context, JSON_THROW_ON_ERROR));
    }

    // ---- 異常系（プログラミングバグは握りつぶさない）----

    public function test_error_is_not_swallowed(): void
    {
        Http::fake(fn () => throw new \TypeError('プレビュー処理の実装バグ'));

        // \Throwable を捕まえていた頃は、この TypeError が null に化けて
        // 「プレビューが付かないだけ」に見え、ログにも残らなかった（issue #67）
        $this->expectException(\TypeError::class);
        $this->service->fetch(self::URL);
    }
}
