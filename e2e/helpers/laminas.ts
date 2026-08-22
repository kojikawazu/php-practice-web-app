import { Page, expect } from '@playwright/test';

export interface LamUser {
  username: string;
  password: string;
}

/** 登録 → 自動ログイン → タスク一覧まで遷移する。 */
export async function registerLaminas(page: Page, user: LamUser): Promise<void> {
  await page.goto('/register');
  await page.fill('input[name="username"]', user.username);
  await page.fill('input[name="password"]', user.password);
  await page.getByRole('button', { name: '登録' }).click();
  await expect(page).toHaveURL(/\/tasks/);
}

export async function loginLaminas(page: Page, username: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.getByRole('button', { name: 'ログイン' }).click();
}

/**
 * ログアウトする。
 * 状態変更のため GET リンクではなく CSRF トークン付きの POST フォーム（docs/06）。
 */
export async function logoutLaminas(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'ログアウト' }).click();
  await expect(page).toHaveURL(/\/login/);
}

/** laminas は確認画面なし: 追加フォーム submit で即作成 → 一覧へ。 */
export async function createTaskLaminas(page: Page, title: string): Promise<void> {
  await page.goto('/tasks');
  await page.fill('input[name="title"]', title);
  await page.getByRole('button', { name: '追加' }).click();
  await expect(page).toHaveURL(/\/tasks/);
}

/** タスク一覧の各タスク行。 */
export function taskItems(page: Page) {
  return page.locator('ul.space-y-2 > li');
}
