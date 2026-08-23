import { test, expect, BrowserContext, Page } from '@playwright/test';
import { registerLaminas, loginLaminas, logoutLaminas } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD, URLS } from '../../helpers/config';

/**
 * セッション固定攻撃（Session Fixation）対策の E2E（docs/06「セッション管理」）。
 *
 * IT では ext/session を張らないため SessionManager::regenerateId() が no-op になり、
 * 「ID が実際に変わったか」を観測できない。実ブラウザ・実 PHP セッションで検証できる
 * のはこの層だけなので、対策の本体はここで担保する。
 */

/** 現在の PHPSESSID。未発行なら undefined。 */
async function sessionId(context: BrowserContext): Promise<string | undefined> {
  const cookies = await context.cookies(URLS.laminas);
  return cookies.find((c) => c.name === 'PHPSESSID')?.value;
}

/** PHPSESSID を任意の値に差し替える（攻撃者が ID を仕込んだ状況の再現）。 */
async function setSessionId(context: BrowserContext, value: string): Promise<void> {
  const url = new URL(URLS.laminas);
  await context.addCookies([
    { name: 'PHPSESSID', value, domain: url.hostname, path: '/' },
  ]);
}

/** 認証済みかどうかを /tasks への到達可否で判定する。 */
async function isAuthenticated(page: Page): Promise<boolean> {
  await page.goto('/tasks');
  return !/\/login/.test(page.url());
}

test.describe('laminas セッション管理', () => {
  // ---- 正常系: 認証成功で ID が変わる ----

  test('ログインの前後でセッションIDが変わる', async ({ page, context }) => {
    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    await logoutLaminas(page);

    await page.goto('/login');
    const before = await sessionId(context);
    expect(before).toBeDefined();

    await loginLaminas(page, username, PASSWORD);
    await expect(page).toHaveURL(/\/tasks/);

    const after = await sessionId(context);
    expect(after).toBeDefined();
    expect(after).not.toBe(before);
  });

  test('登録（自動ログイン）の前後でセッションIDが変わる', async ({ page, context }) => {
    await page.goto('/register');
    const before = await sessionId(context);
    expect(before).toBeDefined();

    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });

    const after = await sessionId(context);
    expect(after).not.toBe(before);
  });

  test('セッションCookieはHttpOnly・SameSite=Laxで発行される', async ({ page, context }) => {
    await page.goto('/login');

    const cookies = await context.cookies(URLS.laminas);
    const session = cookies.find((c) => c.name === 'PHPSESSID');
    expect(session).toBeDefined();
    expect(session?.httpOnly).toBe(true);
    expect(session?.sameSite).toBe('Lax');
  });

  // ---- 異常系: 仕込まれた ID を引き継がせない ----

  test('攻撃者が仕込んだセッションIDは被害者のログイン後に使えない', async ({ browser }) => {
    // 攻撃者: 正規の手順でセッションを1つ作り、その ID を手に入れる
    const attacker = await browser.newContext({ baseURL: URLS.laminas });
    const attackerPage = await attacker.newPage();
    await attackerPage.goto('/login');
    const plantedId = await sessionId(attacker);
    expect(plantedId).toBeDefined();

    // 被害者: その ID を持たされた状態でログインする
    const victim = await browser.newContext({ baseURL: URLS.laminas });
    await setSessionId(victim, plantedId as string);
    const victimPage = await victim.newPage();
    const username = uniqueUsername();
    await registerLaminas(victimPage, { username, password: PASSWORD });

    // 被害者側の ID は入れ替わっている
    expect(await sessionId(victim)).not.toBe(plantedId);

    // 攻撃者は仕込んだ ID のままでは認証済みになれない
    expect(await isAuthenticated(attackerPage)).toBe(false);

    await attacker.close();
    await victim.close();
  });

  test('ログアウト後は旧セッションIDで認証状態に戻れない', async ({ browser }) => {
    const context = await browser.newContext({ baseURL: URLS.laminas });
    const page = await context.newPage();

    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    const authenticatedId = await sessionId(context);
    expect(authenticatedId).toBeDefined();

    await logoutLaminas(page);
    expect(await sessionId(context)).not.toBe(authenticatedId);

    // 旧 ID を復元しても、そのセッションはもう存在しない
    await setSessionId(context, authenticatedId as string);
    expect(await isAuthenticated(page)).toBe(false);

    await context.close();
  });

  test('未知のセッションIDを仕込んでも採用されない', async ({ browser }) => {
    // use_strict_mode=1 の検証。無効なら PHP は与えられた ID をそのまま採用してしまい、
    // 「攻撃者が任意の ID を選べる」状態になる。
    const unknownId = 'e2e0000000000000000000000000strict';
    const context = await browser.newContext({ baseURL: URLS.laminas });
    await setSessionId(context, unknownId);
    const page = await context.newPage();

    await page.goto('/login');
    expect(await sessionId(context)).not.toBe(unknownId);

    await context.close();
  });
});
