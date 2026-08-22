import { test, expect } from '@playwright/test';
import { registerFullstack } from '../../helpers/fullstack';
import { uniqueEmail } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';
import { collectCspViolations, cspHeader, directive } from '../../helpers/csp';

/**
 * CSP が「付いていること」と「UI を壊さないこと」を実ブラウザで検証する（docs/06）。
 * ヘッダー文字列の検証は PHPUnit（CspHeaderTest）が担当し、ここでは実挙動を見る。
 */
test.describe('fullstack CSP', () => {
  test('HTML 応答に CSP が付き、script-src は nonce のみでインラインを許可する', async ({ page }) => {
    const response = await page.goto('/login');
    const csp = cspHeader(response);

    expect(csp).toContain("default-src 'self'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(directive(csp, 'script-src')).toMatch(/'nonce-[A-Za-z0-9+/=]+'/);
    expect(directive(csp, 'script-src')).not.toContain("'unsafe-inline'");
    expect(directive(csp, 'script-src')).not.toContain("'unsafe-eval'");
  });

  test('タスク一覧を操作しても CSP 違反が発生せず、CDN と日付ピッカーが動く', async ({ page }) => {
    const violations = collectCspViolations(page);

    await registerFullstack(page, { name: 'CSP', email: uniqueEmail(), password: PASSWORD });
    await page.waitForLoadState('networkidle');

    // Tailwind Play CDN が適用されている（style-src が正しく許可されている）
    await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(243, 244, 246)');

    // nonce 付きインライン script が実行され flatpickr が初期化されている
    const initialized = await page.evaluate(() => {
      const el = document.querySelector('input.flatpickr') as (HTMLInputElement & { _flatpickr?: unknown }) | null;
      return !!(el && el._flatpickr);
    });
    expect(initialized).toBe(true);

    expect(violations, `CSP 違反: ${violations.join(' / ')}`).toHaveLength(0);
  });

  test('許可していないインライン script は実行されない', async ({ page }) => {
    const violations = collectCspViolations(page);
    await page.goto('/login');

    // nonce の無いインライン script を注入しても実行されない（XSS が刺さっても動かない）
    await page.evaluate(() => {
      const s = document.createElement('script');
      s.textContent = 'window.__cspEscaped = true;';
      document.body.appendChild(s);
    });

    expect(await page.evaluate(() => (window as unknown as Record<string, unknown>).__cspEscaped)).toBeUndefined();
    expect(violations.length).toBeGreaterThan(0);
  });
});
