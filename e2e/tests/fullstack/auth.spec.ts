import { test, expect } from '@playwright/test';
import { registerFullstack, loginFullstack } from '../../helpers/fullstack';
import { uniqueEmail } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';

test.describe('fullstack 認証', () => {
  // ---- 正常系 ----
  test('登録すると自動ログインして一覧が表示される', async ({ page }) => {
    const email = uniqueEmail();
    await registerFullstack(page, { name: 'Alice', email, password: PASSWORD });
    await expect(page.getByText('Alice さん')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'タスク一覧' })).toBeVisible();
  });

  test('ログアウト後に再ログインできる', async ({ page }) => {
    const email = uniqueEmail();
    await registerFullstack(page, { name: 'Bob', email, password: PASSWORD });
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL(/\/login/);

    await loginFullstack(page, email, PASSWORD);
    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Bob さん')).toBeVisible();
  });

  // ---- 異常系 ----
  test('未ログインで /tasks はログインへ誘導される', async ({ page }) => {
    await page.goto('/tasks');
    await expect(page).toHaveURL(/\/login/);
  });

  test('誤ったパスワードではログインできない', async ({ page }) => {
    const email = uniqueEmail();
    await registerFullstack(page, { name: 'Carol', email, password: PASSWORD });
    await page.getByRole('button', { name: 'ログアウト' }).click();

    await loginFullstack(page, email, 'wrong-password');
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('.bg-red-100')).toContainText('正しくありません');
  });

  test('登録済みメールは重複登録できない', async ({ page }) => {
    const email = uniqueEmail();
    await registerFullstack(page, { name: 'Dave', email, password: PASSWORD });
    await page.getByRole('button', { name: 'ログアウト' }).click();

    await page.goto('/register');
    await page.fill('input[name="name"]', 'Dave2');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.fill('input[name="password_confirmation"]', PASSWORD);
    await page.getByRole('button', { name: '登録' }).click();

    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });

  test('パスワード確認が一致しないと登録できない', async ({ page }) => {
    await page.goto('/register');
    await page.fill('input[name="name"]', 'Eve');
    await page.fill('input[name="email"]', uniqueEmail());
    await page.fill('input[name="password"]', PASSWORD);
    await page.fill('input[name="password_confirmation"]', 'different-pass');
    await page.getByRole('button', { name: '登録' }).click();

    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });

  test('短いパスワードは登録できない', async ({ page }) => {
    await page.goto('/register');
    await page.fill('input[name="name"]', 'Frank');
    await page.fill('input[name="email"]', uniqueEmail());
    await page.fill('input[name="password"]', 'short');
    await page.fill('input[name="password_confirmation"]', 'short');
    await page.getByRole('button', { name: '登録' }).click();

    await expect(page).toHaveURL(/\/register/);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });
});
