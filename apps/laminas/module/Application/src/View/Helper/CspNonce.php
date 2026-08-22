<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Application\Service\ContentSecurityPolicy;

/**
 * PHTML からリクエストの CSP nonce を取り出すビューヘルパー。
 *
 * `<script nonce="<?= $this->escapeHtmlAttr($this->cspNonce()) ?>">` のように使う。
 * ヘッダー側と同じ ContentSecurityPolicy インスタンスを見るため、値は必ず一致する。
 *
 * AbstractHelper を継承しないのは、同クラスが非推奨であり、
 * HelperPluginManager が callable をそのままヘルパーとして受け付けるため。
 */
final class CspNonce
{
    public function __construct(private readonly ContentSecurityPolicy $csp)
    {
    }

    public function __invoke(): string
    {
        return $this->csp->nonce();
    }
}
