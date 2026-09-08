<?php

declare(strict_types=1);

namespace Application\Model;

use DateTimeImmutable;

/**
 * Table 層で日時カラムに書く文字列を作る。
 *
 * `'Y-m-d H:i:s'` に固定しているのは、本番の MySQL（TIMESTAMP）とテストの SQLite（TEXT）の
 * **双方が解釈でき、かつ辞書順の比較がそのまま時系列の比較になる**ため。時刻の生成元を
 * アプリの時計に統一しているのは、DB 関数（NOW()）に頼ると SQLite との差が出るから。
 *
 * `TaskTable` / `UserTable` に同じ private メソッドが 2 つあった状態から、`LoginAttemptTable`
 * が 3 箇所目になった時点で抽出した。`UserTable::now()` の DocBlock が
 * 「2 箇所目のため共通化しない。3 箇所目が出たら Table 層の共通トレイト等へ抽出する」と
 * 予告していたとおりで、`.claude/rules/duplication.md` の「2 回目までは重複を許容し、
 * 3 回目で共通化する」に沿う（issue #142）。
 */
trait FormatsTimestamps
{
    /** 監査列・記録時刻に書く現在時刻 */
    private function now(): string
    {
        return $this->formatMoment(new DateTimeImmutable('now'));
    }

    /** 指定秒数だけ前の時刻。期間で絞り込む WHERE の下限に使う */
    private function secondsAgo(int $seconds): string
    {
        return $this->formatMoment(new DateTimeImmutable("-{$seconds} seconds"));
    }

    private function formatMoment(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
