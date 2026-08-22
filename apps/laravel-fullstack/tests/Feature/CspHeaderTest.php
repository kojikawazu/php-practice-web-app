<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Content-Security-Policy ヘッダーの検証（`docs/06`）。
 *
 * 「意図した許可元だけが並んでいるか」を固定する。特に script-src へ 'unsafe-inline' が
 * 紛れ込むと nonce が無効化される（CSP は nonce/hash があると 'unsafe-inline' を無視する）ため、
 * 退行を検出できるようにしておく。
 */
class CspHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function csp(string $uri, ?User $user = null): string
    {
        $response = $user ? $this->actingAs($user)->get($uri) : $this->get($uri);
        $response->assertOk();

        return (string) $response->headers->get('Content-Security-Policy');
    }

    // ---- 正常系 ----

    public function test_html_response_has_csp_header(): void
    {
        $csp = $this->csp(route('login'));

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_nonce_in_header_matches_nonce_in_html(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('tasks.index'));
        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m), 'ヘッダーに nonce がない');

        // インライン script はこの nonce でのみ実行を許可される
        $this->assertStringContainsString('nonce="'.$m[1].'"', (string) $response->getContent());
    }

    public function test_nonce_differs_per_request(): void
    {
        $first = $this->csp(route('login'));
        $second = $this->csp(route('login'));

        $this->assertNotSame($first, $second, 'nonce はリクエストごとに変わる必要がある');
    }

    public function test_allows_only_the_cdns_actually_used(): void
    {
        $csp = $this->csp(route('login'));

        $this->assertStringContainsString('https://cdn.tailwindcss.com', $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        // OGP プレビュー画像は任意の外部ホストから来るため https: を許可している
        $this->assertStringContainsString("img-src 'self' data: https:", $csp);
    }

    // ---- 異常系（退行の検出）----

    public function test_script_src_does_not_allow_unsafe_inline_or_eval(): void
    {
        $csp = $this->csp(route('login'));
        $scriptSrc = $this->directive($csp, 'script-src');

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
    }

    public function test_policy_does_not_use_wildcard_default_src(): void
    {
        $csp = $this->csp(route('login'));

        $this->assertStringNotContainsString('default-src *', $csp);
        $this->assertStringNotContainsString("default-src 'unsafe", $csp);
    }

    public function test_error_response_also_has_csp_header(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'), 'エラーページにも CSP が要る');
    }

    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $part) {
            if (str_starts_with(trim($part), $name.' ')) {
                return trim($part);
            }
        }

        return '';
    }
}
