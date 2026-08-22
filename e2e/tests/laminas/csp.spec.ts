import { test, expect } from '@playwright/test';
import { registerLaminas } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';
import { collectCspViolations, cspHeader, directive } from '../../helpers/csp';

/**
 * CSP が「付いていること」と「UI を壊さないこと」を実ブラウザで検証する（docs/06）。
 * ヘッダー文字列の検証は PHPUnit（CspHeaderIntegrationTest）が担当し、ここでは実挙動を見る。
 */
test.describe('laminas CSP', () => {
  test('HTML 応答に CSP が付き、script-src は nonce のみでインラインを許可する', async ({ page }) => {
    const response = await page.goto('/login');
    const csp = cspHeader(response);

    expect(csp).toContain("default-src 'self'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(directive(csp, 'script-src')).toMatch(/'nonce-[a-f0-9]+'/);
    expect(directive(csp, 'script-src')).not.toContain("'unsafe-inline'");
    expect(directive(csp, 'script-src')).not.toContain("'unsafe-eval'");
  });

  test('タスク一覧を操作しても CSP 違反が発生せず、日付ピッカーが動く', async ({ page }) => {
    const violations = collectCspViolations(page);

    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await page.waitForLoadState('networkidle');

    const initialized = await page.evaluate(() => {
      const el = document.querySelector('input.flatpickr') as (HTMLInputElement & { _flatpickr?: unknown }) | null;
      return !!(el && el._flatpickr);
    });
    expect(initialized).toBe(true);

    expect(violations, `CSP 違反: ${violations.join(' / ')}`).toHaveLength(0);
  });
});
