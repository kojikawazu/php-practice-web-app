<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Service\AuthSessionInterface;
use Laminas\Authentication\AuthenticationService;

/**
 * AuthSessionInterface の記録用実装（IT 専用）。
 *
 * IT は実セッション（ext/session）を張らない方針のため、本番実装が呼ぶ
 * SessionManager::regenerateId() は「セッションが active でなければ何もしない」の分岐に落ち、
 * ID の変化を観測できない。そこで差し替えるのは ext/session という外部 I/O の境界だけに
 * とどめ（testing.md「モックは外部 I/O のみ」）、照合・identity 書き込みといった
 * 業務ロジックは本物のまま通す。
 *
 * ここで担保するのは「どの経路で・どの順序で呼ばれるか（呼ばれないか）」。
 * 実際に ID が変わることは E2E（e2e/tests/laminas/session.spec.ts）で担保する。
 */
final class RecordingAuthSession implements AuthSessionInterface
{
    /** @var list<string> 呼ばれたメソッド名を呼び出し順に記録する */
    private array $calls = [];

    /** @var list<bool> regenerate() された時点で identity が既に書かれていたか */
    private array $identityPresentOnRegenerate = [];

    public function __construct(private readonly AuthenticationService $auth)
    {
    }

    public function regenerate(): void
    {
        $this->calls[] = 'regenerate';
        $this->identityPresentOnRegenerate[] = $this->auth->hasIdentity();
    }

    public function invalidate(): void
    {
        $this->calls[] = 'invalidate';
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<bool> */
    public function identityPresentOnRegenerate(): array
    {
        return $this->identityPresentOnRegenerate;
    }
}
