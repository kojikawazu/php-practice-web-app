import { Page, expect } from '@playwright/test';

export interface FsUser {
  name: string;
  email: string;
  password: string;
}

/** 登録 → 自動ログイン → タスク一覧まで遷移する。 */
export async function registerFullstack(page: Page, user: FsUser): Promise<void> {
  await page.goto('/register');
  await page.fill('input[name="name"]', user.name);
  await page.fill('input[name="email"]', user.email);
  await page.fill('input[name="password"]', user.password);
  await page.fill('input[name="password_confirmation"]', user.password);
  await page.getByRole('button', { name: '登録' }).click();
  await expect(page).toHaveURL(/\/tasks/);
}

export async function loginFullstack(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.getByRole('button', { name: 'ログイン' }).click();
}

/**
 * flatpickr の日付を設定する。input を fill するとカレンダーが開いて送信ボタンを
 * 覆ってしまうため、value を直接セットして input/change を発火させる（カレンダーを開かない）。
 */
export async function setDate(page: Page, name: string, value: string): Promise<void> {
  await page.locator(`input[name="${name}"]`).evaluate((el, v) => {
    const input = el as HTMLInputElement;
    input.value = v as string;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

/**
 * 追加フォーム → 確認画面 →「この内容で登録」まで通す（fullstack は 2 ステップ確認）。
 */
export async function createTaskFullstack(
  page: Page,
  title: string,
  opts: { start?: string; end?: string } = {},
): Promise<void> {
  await page.goto('/tasks');
  await page.fill('input[name="title"]', title);
  if (opts.start) {
    await setDate(page, 'start_date', opts.start);
  }
  if (opts.end) {
    await setDate(page, 'end_date', opts.end);
  }
  await page.getByRole('button', { name: '追加' }).click();
  await page.getByRole('button', { name: 'この内容で登録' }).click();
  await expect(page).toHaveURL(/\/tasks/);
}

/** タスク一覧の各タスク行（ページャの li と区別するため ul.space-y-2 直下に限定）。 */
export function taskItems(page: Page) {
  return page.locator('ul.space-y-2 > li');
}
