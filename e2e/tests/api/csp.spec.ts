import { test, expect } from '@playwright/test';

/**
 * api は JSON と画像バイナリしか返さないため、許可元を持たない
 * `default-src 'none'` を全レスポンスへ適用する（docs/06 / docs/07）。
 */
test.describe('api CSP', () => {
  test('JSON 応答に default-src none の CSP が付く', async ({ request }) => {
    const response = await request.get('/api/tasks', { headers: { Accept: 'application/json' } });
    const csp = response.headers()['content-security-policy'] ?? '';

    expect(csp).toContain("default-src 'none'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).not.toContain('script-src');
    expect(csp).not.toContain('*');
  });
});
