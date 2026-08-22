<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Application\Service\CsrfGuard;
use Laminas\Escaper\Escaper;

/**
 * POST フォームへ CSRF トークンの hidden を出力するビューヘルパー。
 *
 * `<?= $this->csrfInput() ?>` と書く。Laravel Blade の `@csrf` に相当する。
 *
 * AbstractHelper を継承しないのは、同クラスが非推奨であり、
 * HelperPluginManager が callable をそのままヘルパーとして受け付けるため。
 */
final class CsrfInput
{
    public function __construct(
        private readonly CsrfGuard $guard,
        private readonly Escaper $escaper
    ) {
    }

    public function __invoke(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            $this->escaper->escapeHtmlAttr(CsrfGuard::FIELD),
            $this->escaper->escapeHtmlAttr($this->guard->token())
        );
    }
}
