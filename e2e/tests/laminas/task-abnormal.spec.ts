import { test, expect } from '@playwright/test';
import { registerLaminas, createTaskLaminas, taskItems } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD, URLS } from '../../helpers/config';

test.describe('laminas タスク（準正常・異常系）', () => {
  test('空タイトルは作成できず、エラーになる', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await page.goto('/tasks');
    await page.getByRole('button', { name: '追加' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });

  test('検索ヒットなしで空表示になる', async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(page, 'only one');
    await page.fill('input[name="q"]', 'zzz-none');
    await page.getByRole('button', { name: '検索' }).click();

    await expect(page.getByText('タスクはありません。')).toBeVisible();
  });

  test('他人のタスクは編集画面に入れず一覧へ戻される', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const pageA = await ctxA.newPage();
    await registerLaminas(pageA, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(pageA, 'A private');
    const editHref = await taskItems(pageA)
      .filter({ hasText: 'A private' })
      .getByRole('link', { name: '編集' })
      .getAttribute('href');
    await ctxA.close();
    expect(editHref).not.toBeNull();

    const ctxB = await browser.newContext();
    const pageB = await ctxB.newPage();
    await registerLaminas(pageB, { username: uniqueUsername(), password: PASSWORD });
    const path = new URL(editHref as string, URLS.laminas).pathname;
    await pageB.goto(path);
    // 所有者スコープ外は一覧へリダイレクト。他人のタスクは B の一覧に現れない。
    await expect(pageB).toHaveURL(/\/tasks$/);
    await expect(pageB.getByText('A private')).toHaveCount(0);
    await ctxB.close();
  });

  test('他人のタスクは削除できない', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const pageA = await ctxA.newPage();
    await registerLaminas(pageA, { username: uniqueUsername(), password: PASSWORD });
    await createTaskLaminas(pageA, 'A keep');
    // 削除は POST のみ（docs/06）。フォームの action から対象パスを取る
    const delAction = await taskItems(pageA)
      .filter({ hasText: 'A keep' })
      .locator('form[action*="/tasks/delete/"]')
      .getAttribute('action');
    expect(delAction).not.toBeNull();

    const ctxB = await browser.newContext();
    const pageB = await ctxB.newPage();
    await registerLaminas(pageB, { username: uniqueUsername(), password: PASSWORD });
    // B 自身の正しい CSRF トークンで A のタスク削除を試みる（CSRF ではなく所有者スコープの検証）
    const token = await pageB.locator('input[name="csrf"]').first().inputValue();
    const path = new URL(delAction as string, URLS.laminas).pathname;
    await pageB.request.post(path, { form: { csrf: token } }); // deleteForUser で B スコープ → 無効
    await ctxB.close();

    // A の一覧を再確認: タスクは残っている
    await pageA.goto('/tasks');
    await expect(pageA.getByText('A keep')).toBeVisible();
    await ctxA.close();
  });
});
