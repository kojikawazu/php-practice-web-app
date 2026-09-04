<?php

namespace Tests\Unit;

use App\Services\SizeCappedSink;
use PHPUnit\Framework\TestCase;

/**
 * URL プレビューの本文サイズ上限（issue #88）の実体を検証する。
 *
 * 上限は「解析に使う長さを切る」ことではなく「**それ以上受信しない**」ことで成立する。
 * その実体は cURL の書き込み関数の戻り値で、渡されたバイト数より少ない値を返すと
 * 転送が中断される（`CURLE_WRITE_ERROR`）。つまりここで固定すべき契約は
 * **「上限に達したら短い値を返す」**の一点であり、それが守られている限り
 * 転送は上限で止まる。
 *
 * 実際に転送が止まる様子そのものは、cURL を通らないフェイクでは再現できない
 * （`tests/Feature/LinkPreviewSizeLimitTest.php` の DocBlock 参照）。
 */
class SizeCappedSinkTest extends TestCase
{
    /** 書き込んだ内容を読み出す（sink は書き込み後に位置が末尾にある） */
    private function contentsOf(SizeCappedSink $sink): string
    {
        $sink->rewind();

        return $sink->getContents();
    }

    // ---- 正常系（上限内）----

    public function test_writes_within_the_limit_are_accepted_in_full(): void
    {
        $sink = new SizeCappedSink(10);

        $this->assertSame(4, $sink->write('abcd'));
        $this->assertSame(4, $sink->write('efgh'));

        $this->assertFalse($sink->capReached());
        $this->assertSame('abcdefgh', $this->contentsOf($sink));
    }

    public function test_writing_exactly_up_to_the_limit_is_not_a_cap(): void
    {
        $sink = new SizeCappedSink(4);

        $this->assertSame(4, $sink->write('abcd'));

        // ちょうど上限までは「全部書けた」ので中断させる理由がない
        $this->assertFalse($sink->capReached());
        $this->assertSame('abcd', $this->contentsOf($sink));
    }

    // ---- 準正常系（上限超過）----

    public function test_write_crossing_the_limit_returns_a_short_count(): void
    {
        $sink = new SizeCappedSink(4);

        // 6 バイト渡して 4 バイトしか受け取らない。この「短い戻り値」が
        // cURL に転送を中断させる合図になる
        $this->assertSame(4, $sink->write('abcdef'));

        $this->assertTrue($sink->capReached());
        $this->assertSame('abcd', $this->contentsOf($sink));
    }

    public function test_writes_after_the_limit_are_refused(): void
    {
        $sink = new SizeCappedSink(4);
        $sink->write('abcd');

        // 上限ちょうどで埋まった後の書き込みは 1 バイトも受け取らない
        $this->assertSame(0, $sink->write('efgh'));

        $this->assertTrue($sink->capReached());
        $this->assertSame('abcd', $this->contentsOf($sink), '上限を超えた内容は保持しない');
    }

    public function test_cap_state_is_kept_once_reached(): void
    {
        $sink = new SizeCappedSink(4);
        $sink->write('abcdef');
        $sink->write('ghij');

        // 一度上限に達したら、その後の書き込みで状態が戻らない
        $this->assertTrue($sink->capReached());
    }
}
