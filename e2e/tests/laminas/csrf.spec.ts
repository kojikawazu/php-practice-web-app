import { test, expect } from '@playwright/test';
import { registerLaminas, createTaskLaminas, taskItems } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';

/**
 * CSRF 対策の実挙動（docs/06）。
 *
 * ヘッダー相当の細かな条件は IT（CsrfProtectionIntegrationTest）が担当し、
 * ここでは実ブラウザ・実セッションで「攻撃が通らず、通常操作は通る」ことを見る。
 */
test.describe('laminas CSRF', () => {
  test('状態変更は GET では実行されない（405）', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(page, 'GET では消せない');

    const action = await taskItems(page)
      .filter({ hasText: 'GET では消せない' })
      .locator('form[action*="/tasks/delete/"]')
      .getAttribute('action');
    expect(action).not.toBeNull();

    // 認証済みセッションのまま GET で叩く（<img src="..."> を踏んだ状況に相当）
    const response = await page.request.get(action as string);
    expect(response.status()).toBe(405);

    await page.goto('/tasks');
    await expect(page.getByText('GET では消せない')).toBeVisible();
  });

  test('ログアウトも GET では実行されない（405）', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });

    const response = await page.request.get('/logout');
    expect(response.status()).toBe(405);

    await page.goto('/tasks');
    await expect(page.getByRole('heading', { name: 'タスク一覧' })).toBeVisible();
  });

  test('CSRF トークンの無い POST は拒否される（403）', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(page, 'トークン無しでは消せない');

    const action = await taskItems(page)
      .filter({ hasText: 'トークン無しでは消せない' })
      .locator('form[action*="/tasks/delete/"]')
      .getAttribute('action');

    const response = await page.request.post(action as string, { form: {} });
    expect(response.status()).toBe(403);

    await page.goto('/tasks');
    await expect(page.getByText('トークン無しでは消せない')).toBeVisible();
  });

  test('不正な CSRF トークンの POST は拒否される（403）', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });

    const response = await page.request.post('/tasks', {
      form: { title: '偽トークン', csrf: 'not-a-valid-token' },
    });
    expect(response.status()).toBe(403);

    await page.goto('/tasks');
    await expect(page.getByText('偽トークン')).toHaveCount(0);
  });

  test('完了トグルも GET / トークン無しでは実行されない', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(page, 'トグルは守られる');

    const action = await taskItems(page)
      .filter({ hasText: 'トグルは守られる' })
      .locator('form[action*="/tasks/toggle/"]')
      .getAttribute('action');

    expect((await page.request.get(action as string)).status()).toBe(405);
    expect((await page.request.post(action as string, { form: {} })).status()).toBe(403);

    await page.goto('/tasks');
    await expect(taskItems(page).filter({ hasText: 'トグルは守られる' }).locator('span.line-through')).toHaveCount(0);
  });

  test('全 POST フォームに CSRF トークンが埋め込まれている', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('form[method="post"] input[name="csrf"]')).toHaveCount(1);

    await page.goto('/register');
    await expect(page.locator('form[method="post"] input[name="csrf"]')).toHaveCount(1);

    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(page, 'フォーム確認');

    // 追加・ログアウト・複製・削除のすべてが POST + トークン付き
    const forms = page.locator('form[method="post"]');
    const count = await forms.count();
    expect(count).toBeGreaterThanOrEqual(4);
    await expect(page.locator('form[method="post"] input[name="csrf"]')).toHaveCount(count);
  });
});
