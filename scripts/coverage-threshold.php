<?php

/**
 * Clover レポートの行カバレッジを読み、下限を下回っていれば異常終了する。
 *
 * 使い方: php scripts/coverage-threshold.php <clover.xml>
 *
 * 下限をこのファイルに 1 箇所だけ置くのは、3 アプリ + 手元（Makefile）と CI（ci.yml）の
 * 4 箇所へ数値を書き写すと、片方だけ更新して「手元と CI で違う基準を見ている」状態に
 * なるため（.claude/rules/github-actions.md の actionlint バージョンと同じ理由）。
 *
 * PHPUnit には fail-under 相当の機能がないため、Clover を読んで自前で判定する。
 * Laravel の `test --coverage --min=` は使えるが、laminas 側に同等の手段が無く、
 * アプリごとに違う基準・違う計算方法になるのを避けてこちらへ統一している。
 */

declare(strict_types=1);

/** 行カバレッジの下限（%）。現状の実測は fullstack 94.6 / api 98.1 / laminas 95.7 前後。 */
const MINIMUM_LINE_COVERAGE = 90.0;

$path = $argv[1] ?? null;

if ($path === null) {
    fwrite(STDERR, "usage: php scripts/coverage-threshold.php <clover.xml>\n");
    exit(2);
}

// レポートが無いのに成功扱いにすると、計測が壊れた日に「ずっと緑」になる。
// 検査できなかったことは、合格ではなく失敗として扱う。
if (! is_file($path)) {
    fwrite(STDERR, "coverage report not found: {$path}\n");
    fwrite(STDERR, "テストが Clover を出力できていない（カバレッジドライバ未導入の可能性）。\n");
    exit(2);
}

$xml = @simplexml_load_file($path);

if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "coverage report is not a readable Clover file: {$path}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "coverage report contains no statements: {$path}\n");
    exit(2);
}

$percentage = $covered / $statements * 100;

printf("line coverage: %.2f%% (%d/%d), minimum: %.2f%%\n", $percentage, $covered, $statements, MINIMUM_LINE_COVERAGE);

if ($percentage < MINIMUM_LINE_COVERAGE) {
    fwrite(STDERR, sprintf(
        "カバレッジが下限を下回っている（%.2f%% < %.2f%%）。テストを追加するか、\n"
        ."下限を下げる場合は scripts/coverage-threshold.php を変更して理由を PR に書くこと。\n",
        $percentage,
        MINIMUM_LINE_COVERAGE
    ));
    exit(1);
}
