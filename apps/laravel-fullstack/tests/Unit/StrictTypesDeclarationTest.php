<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * declare(strict_types=1); を宣言していないファイルが、フレームワーク雛形だけであることを固定する（issue #66）。
 *
 * この宣言の有無は実行時の型変換を変えるのに、**それを見ているゲートが 1 つも無かった**。
 * Larastan は静的な型整合しか見ないため宣言を足す前後どちらも「No errors」で、Pint は
 * pint.json を置かない既定の Laravel プリセットで動くため declare_strict_types を強制しない。
 * 結果、issue #66 の起票（2026-07-25）から着手までに本アプリの対象は 9 → 18 件へ静かに増えていた。
 * ここが唯一の検出装置になる。
 *
 * 判定は「未宣言のファイル集合が EXEMPT_FILES と**完全一致**するか」で行う。包含ではなく一致に
 * するのは、除外リストを片方向にしか壊れない形にするため。未宣言が増えれば当然落ちるが、
 * 雛形に宣言が入った場合も落ちてリストからの削除を促す。**リストが実態から静かにずれない**。
 *
 * 除外してよいのはフレームワーク雛形（中身が `//` だけで規約の対象にならないもの）に限る。
 * `AppServiceProvider` は雛形だったが、レートリミッタの定義が入って実装を持ったため除外から外した
 * （issue #141）。そのとき落ちたのが、この完全一致の判定である。
 *
 * 読み比べ（.claude/rules/duplication.md: 3 アプリ間は共通化しない）:
 * - laravel-api: 同じ形のガードを各アプリに独立して置く。
 * - laminas: 44/44 が宣言済みだが、phpcs.xml は手書きの ruleset で DeclareStrictTypes 相当の
 *   sniff を含まない。宣言はスケルトンの慣習に従っているだけで、ゲートは同様に無い。
 */
class StrictTypesDeclarationTest extends TestCase
{
    /** @var list<string> 走査対象（アプリルートからの相対パス） */
    private const SCANNED_DIRS = ['app', 'tests'];

    /**
     * 宣言を免除するフレームワーク雛形（アプリルートからの相対パス・昇順）。
     *
     * いずれも中身が `//` だけのスケルトン生成物で、規約が対象とする「自作クラス」ではない。
     *
     * @var list<string>
     */
    private const EXEMPT_FILES = [
        'app/Http/Controllers/Controller.php',
        'tests/TestCase.php',
    ];

    /** 宣言は冒頭に置く決まりなので、先頭からこの行数までに現れることを求める */
    private const HEADER_LINES = 5;

    public function test_only_framework_stubs_lack_strict_types_declaration(): void
    {
        $undeclared = [];

        foreach ($this->phpFiles() as $relative) {
            if (! $this->declaresStrictTypes($relative)) {
                $undeclared[] = $relative;
            }
        }

        $this->assertSame(self::EXEMPT_FILES, $undeclared, implode("\n", [
            '未宣言のファイル集合が EXEMPT_FILES と一致しない（.claude/rules/coding-standards.md）。',
            '増えている場合: `<?php` の次の空行に続けて `declare(strict_types=1);` を足す。',
            '  フレームワーク雛形であれば EXEMPT_FILES へ理由とともに追加する。',
            '減っている場合: そのファイルは免除が不要になったので EXEMPT_FILES から外す。',
        ]));
    }

    /**
     * 走査対象の PHP ファイル。
     *
     * @return list<string> アプリルートからの相対パス（昇順）
     */
    private function phpFiles(): array
    {
        $root = $this->appRoot();
        $files = [];

        foreach (self::SCANNED_DIRS as $dir) {
            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }

    private function declaresStrictTypes(string $relative): bool
    {
        $lines = file($this->appRoot().'/'.$relative, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return false;
        }

        return in_array('declare(strict_types=1);', array_slice($lines, 0, self::HEADER_LINES), true);
    }

    /** tests/Unit/ から 2 つ上がアプリルート */
    private function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
