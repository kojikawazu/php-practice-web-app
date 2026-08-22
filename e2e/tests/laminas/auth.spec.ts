import { test, expect } from '@playwright/test';
import { registerLaminas, loginLaminas, logoutLaminas } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';

test.describe('laminas 認証', () => {
  // ---- 正常系 ----
  test('登録すると自動ログインして一覧が表示される', async ({ page }) => {
    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    await expect(page.getByText(`${username} さん`)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'タスク一覧' })).toBeVisible();
  });

  test('ログアウト後に再ログインできる', async ({ page }) => {
    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    await logoutLaminas(page);

    await loginLaminas(page, username, PASSWORD);
    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText(`${username} さん`)).toBeVisible();
  });

  // ---- 異常系 ----
  test('未ログインで /tasks はログインへ誘導される', async ({ page }) => {
    await page.goto('/tasks');
    await expect(page).toHaveURL(/\/login/);
  });

  test('誤ったパスワードではログインできない', async ({ page }) => {
    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    await logoutLaminas(page);

    await loginLaminas(page, username, 'wrong-password');
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('.bg-red-100')).toContainText('正しくありません');
  });

  test('既存ユーザー名は登録できない（既存パスワードは不変）', async ({ page }) => {
    const username = uniqueUsername();
    await registerLaminas(page, { username, password: PASSWORD });
    await logoutLaminas(page);

    await page.goto('/register');
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: '登録' }).click();

    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('.bg-red-100')).toContainText('既に使われています');
  });

  test('短いパスワードは登録できない', async ({ page }) => {
    await page.goto('/register');
    await page.fill('input[name="username"]', uniqueUsername());
    await page.fill('input[name="password"]', 'short');
    await page.getByRole('button', { name: '登録' }).click();

    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });
});
