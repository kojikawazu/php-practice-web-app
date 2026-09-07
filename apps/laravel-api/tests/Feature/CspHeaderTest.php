<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Content-Security-Policy ヘッダーの検証（`docs/06`）。
 *
 * 本アプリは JSON と画像バイナリしか返さないため、許可元を持たない
 * `default-src 'none'` を全レスポンスに適用する。緩む方向の変更を検出する。
 */
class CspHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    // ---- 正常系 ----

    public function test_json_response_has_strict_csp(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/tasks')->assertOk()->assertHeader('Content-Security-Policy', self::POLICY);
    }

    public function test_unauthenticated_response_has_strict_csp(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized()->assertHeader('Content-Security-Policy', self::POLICY);
    }

    // ---- 異常系（退行の検出）----

    public function test_policy_never_allows_scripts_or_inline(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $csp = (string) $this->getJson('/api/tasks')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('script-src', $csp, 'JSON API に script の許可は要らない');
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test_error_response_also_has_csp_header(): void
    {
        $response = $this->getJson('/api/tasks/999999');

        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }
}
