<?php

namespace Tests\Feature;

use App\Services\LinkPreviewService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * URL プレビューの本文サイズ上限を検証する（issue #88）。
 *
 * 以前は応答本文を全量受信・文字列化してから `substr` していたため、`MAX_BYTES` は
 * 「解析に使う長さ」でしかなく、転送量・メモリの上限として機能していなかった。
 * ここでは「上限を超えた分は解析に使われない」「取得先が申告する Content-Length で
 * 上限を迂回できない」「上限内の解析は従来どおり」を固定する。
 *
 * 担当範囲: ここで見るのは**解析に使う長さ**の層（`readCapped`）まで。転送そのものを止める
 * 層は `SizeCappedSink` が担い、その契約（上限に達したら短い書き込みを返す = cURL が転送を
 * 中断する）は `tests/Unit/SizeCappedSinkTest.php` が固定している。
 *
 * 限界: 「実際に転送が止まること」は Http::fake では検証できない。フェイクはハンドラ
 * （cURL）を通らず、`sink` も Laravel がエミュレートする（`PendingRequest::sinkStubHandler` は
 * スタブ本文を全量 `getContents()` してから書き出す）ため、フェイク上の読み取り量は
 * 本番の転送量と一致しない。実サーバーに対する計測は、SSRF ガードが localhost を拒否する
 * （`resolveSafeIp()`）ため本アプリでは実行できない。
 */
class LinkPreviewSizeLimitTest extends TestCase
{
    /** public IP リテラル（DNS 解決不要でブロック判定を通る） */
    private const URL = 'http://93.184.216.34/page';

    /** LinkPreviewService::MAX_BYTES と同じ値。実装を変えたらここも落ちて気づける */
    private const MAX_BYTES = 524288;

    private LinkPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LinkPreviewService;
        Log::spy();
    }

    /** 先頭に og:title を置き、指定バイト数まで詰め物で膨らませた HTML */
    private function htmlOfSize(int $size, string $tail = ''): string
    {
        $head = '<html><head><meta property="og:title" content="先頭のタイトル"></head><body>';
        $padding = str_repeat('a', max(0, $size - strlen($head) - strlen($tail)));

        return $head.$padding.$tail;
    }

    // ---- 正常系（上限内は従来どおり）----

    public function test_body_within_limit_is_parsed_as_before(): void
    {
        Http::fake(fn () => Http::response('<html><head><title>素タイトル</title></head></html>'));

        $meta = $this->service->fetch(self::URL);

        $this->assertSame('素タイトル', $meta['title']);
        Log::shouldNotHaveReceived('info');
    }

    public function test_body_exactly_at_the_limit_is_not_treated_as_truncated(): void
    {
        Http::fake(fn () => Http::response($this->htmlOfSize(self::MAX_BYTES)));

        $meta = $this->service->fetch(self::URL);

        // 境界ちょうどは切り詰めではない（超過していないため記録も出ない）
        $this->assertSame('先頭のタイトル', $meta['title']);
        Log::shouldNotHaveReceived('info');
    }

    // ---- 準正常系（上限超過）----

    public function test_content_beyond_the_limit_is_not_used(): void
    {
        // 上限の外側にだけ og:image を置く。上限が効いていれば拾えない
        $tail = '<meta property="og:image" content="https://example.com/beyond-the-cap.png">';
        Http::fake(fn () => Http::response($this->htmlOfSize(self::MAX_BYTES + 4096).$tail));

        $meta = $this->service->fetch(self::URL);

        $this->assertSame('先頭のタイトル', $meta['title'], '上限内にある情報は従来どおり拾える');
        $this->assertNull($meta['image'], '上限を超えた位置の内容は解析に使われない');
    }

    public function test_truncation_is_recorded(): void
    {
        Http::fake(fn () => Http::response($this->htmlOfSize(self::MAX_BYTES + 1)));

        $this->service->fetch(self::URL);

        // 失敗ではないので warning ではなく info。「プレビューが変」と言われたときに
        // 切り詰めを疑えるようにするための記録（issue #67 で入れたログ方針の続き）
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'URL preview body truncated'
                && $context['host'] === '93.184.216.34'
                && $context['limit'] === self::MAX_BYTES);
    }

    // ---- 異常系（取得先の自己申告を信頼しない）----

    public function test_lying_content_length_does_not_bypass_the_limit(): void
    {
        // 「10 バイトしかない」と申告しつつ上限超のデータを送りつける応答。
        // Content-Length を根拠に上限を判断していると、この 1 行で迂回される
        $tail = '<meta property="og:image" content="https://example.com/beyond-the-cap.png">';
        Http::fake(fn () => Http::response(
            $this->htmlOfSize(self::MAX_BYTES + 4096).$tail,
            200,
            ['Content-Length' => '10']
        ));

        $meta = $this->service->fetch(self::URL);

        $this->assertNull($meta['image'], 'Content-Length の申告値に関わらず上限で切る');
    }
}
