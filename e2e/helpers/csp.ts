import { Page, Response } from '@playwright/test';

/**
 * ページ操作中に発生した CSP 違反を収集する。
 *
 * ブラウザは CSP でブロックしたリソースをコンソールへ報告する。ヘッダーの文字列だけを
 * 見ても「実際に UI が壊れないか」は分からないため、E2E ではこの実挙動を検証する。
 */
export function collectCspViolations(page: Page): string[] {
  const violations: string[] = [];
  page.on('console', (msg) => {
    const text = msg.text();
    if (/Content Security Policy|Refused to (load|execute|apply)/i.test(text)) {
      violations.push(text);
    }
  });
  return violations;
}

/** レスポンスの CSP ヘッダー値（無ければ空文字）。 */
export function cspHeader(response: Response | null): string {
  return response?.headers()['content-security-policy'] ?? '';
}

/** `script-src` などの 1 ディレクティブを取り出す。 */
export function directive(csp: string, name: string): string {
  const found = csp.split(';').map((s) => s.trim()).find((s) => s.startsWith(`${name} `));
  return found ?? '';
}
