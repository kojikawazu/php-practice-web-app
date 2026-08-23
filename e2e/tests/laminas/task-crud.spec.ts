import { test, expect } from '@playwright/test';
import { registerLaminas, createTaskLaminas, taskItems } from '../../helpers/laminas';
import { uniqueUsername } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';

test.describe('laminas タスク CRUD（正常系）', () => {
  test.beforeEach(async ({ page }) => {
    await registerLaminas(page, { username: uniqueUsername(), password: PASSWORD });
  });

  test('タスクを作成できる', async ({ page }) => {
    await createTaskLaminas(page, 'Lam task');
    await expect(page.getByText('Lam task')).toBeVisible();
  });

  test('タスクを編集できる', async ({ page }) => {
    await createTaskLaminas(page, 'Lam old');
    await taskItems(page).filter({ hasText: 'Lam old' }).getByRole('link', { name: '編集' }).click();
    await page.fill('input[name="title"]', 'Lam new');
    await page.getByRole('button', { name: '更新' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Lam new')).toBeVisible();
    await expect(page.getByText('Lam old', { exact: true })).toHaveCount(0);
  });

  test('タスクを複製すると「（コピー）」が付く', async ({ page }) => {
    await createTaskLaminas(page, 'Lam dup');
    await taskItems(page).filter({ hasText: 'Lam dup' }).getByRole('button', { name: '複製' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Lam dup（コピー）')).toBeVisible();
  });

  test('完了トグルで取り消し線になり、戻せる', async ({ page }) => {
    await createTaskLaminas(page, 'Lam toggle');
    await taskItems(page).filter({ hasText: 'Lam toggle' }).getByRole('button', { name: '完了' }).click();
    await expect(taskItems(page).filter({ hasText: 'Lam toggle' }).locator('span.line-through')).toBeVisible();

    await taskItems(page).filter({ hasText: 'Lam toggle' }).getByRole('button', { name: '未完了に戻す' }).click();
    await expect(taskItems(page).filter({ hasText: 'Lam toggle' }).getByRole('button', { name: '完了' })).toBeVisible();
  });

  test('タスクを削除できる', async ({ page }) => {
    await createTaskLaminas(page, 'Lam del');
    await taskItems(page).filter({ hasText: 'Lam del' }).getByRole('button', { name: '削除' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Lam del')).toHaveCount(0);
  });

  test('タイトル検索で絞り込める', async ({ page }) => {
    await createTaskLaminas(page, 'alpha task');
    await createTaskLaminas(page, 'beta task');

    await page.fill('input[name="q"]', 'alpha');
    await page.getByRole('button', { name: '検索' }).click();

    await expect(page.getByText('alpha task')).toBeVisible();
    await expect(page.getByText('beta task')).toHaveCount(0);
  });

  test('6件で 2 ページに分割される', async ({ page }) => {
    for (let i = 1; i <= 6; i += 1) {
      await createTaskLaminas(page, `LamPg ${i}`);
    }
    await page.goto('/tasks');
    await expect(taskItems(page)).toHaveCount(5);
    await expect(page.getByText('ページ 1 / 2（全 6 件）')).toBeVisible();

    await page.getByRole('link', { name: '次へ' }).click();
    await expect(taskItems(page)).toHaveCount(1);
  });
});
