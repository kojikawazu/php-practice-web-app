import { test, expect } from '@playwright/test';
import { registerFullstack, createTaskFullstack, taskItems } from '../../helpers/fullstack';
import { uniqueEmail } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';
import { pngUpload } from '../../helpers/png';

test.describe('fullstack タスク CRUD（正常系）', () => {
  test.beforeEach(async ({ page }) => {
    await registerFullstack(page, { name: 'User', email: uniqueEmail(), password: PASSWORD });
  });

  test('タスクを作成できる（確認画面経由）', async ({ page }) => {
    await createTaskFullstack(page, 'Buy milk');
    await expect(taskItems(page).filter({ hasText: 'Buy milk' })).toBeVisible();
  });

  test('タスクを編集できる（確認画面経由）', async ({ page }) => {
    await createTaskFullstack(page, 'Old title');
    await taskItems(page).filter({ hasText: 'Old title' }).getByRole('link', { name: '編集' }).click();
    await page.fill('input[name="title"]', 'New title');
    await page.getByRole('button', { name: '更新' }).click();
    await page.getByRole('button', { name: 'この内容で更新' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('New title')).toBeVisible();
    await expect(page.getByText('Old title', { exact: true })).toHaveCount(0);
  });

  test('タスクを複製すると「（コピー）」が付く', async ({ page }) => {
    await createTaskFullstack(page, 'Dup me');
    await taskItems(page).filter({ hasText: 'Dup me' }).getByRole('link', { name: '複製' }).click();
    await page.getByRole('button', { name: '複製する' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Dup me（コピー）')).toBeVisible();
  });

  test('完了トグルで取り消し線になり、戻せる', async ({ page }) => {
    await createTaskFullstack(page, 'Toggle me');
    await taskItems(page).filter({ hasText: 'Toggle me' }).getByRole('button', { name: '完了' }).click();
    await expect(taskItems(page).filter({ hasText: 'Toggle me' }).locator('span.line-through')).toBeVisible();

    await taskItems(page).filter({ hasText: 'Toggle me' }).getByRole('button', { name: '未完了に戻す' }).click();
    await expect(taskItems(page).filter({ hasText: 'Toggle me' }).getByRole('button', { name: '完了' })).toBeVisible();
  });

  test('タスクを削除できる', async ({ page }) => {
    await createTaskFullstack(page, 'Delete me');
    await taskItems(page).filter({ hasText: 'Delete me' }).getByRole('button', { name: '削除' }).click();

    await expect(page.getByText('Delete me')).toHaveCount(0);
    await expect(page.getByText('タスクはありません。')).toBeVisible();
  });

  test('タイトル検索で絞り込める', async ({ page }) => {
    await createTaskFullstack(page, 'apple pie');
    await createTaskFullstack(page, 'banana bread');

    await page.fill('input[name="q"]', 'apple');
    await page.getByRole('button', { name: '検索' }).click();

    await expect(page.getByText('apple pie')).toBeVisible();
    await expect(page.getByText('banana bread')).toHaveCount(0);
  });

  test('開始日・終了日付きで作成でき、期間が表示される', async ({ page }) => {
    await createTaskFullstack(page, 'Dated task', { start: '2026-01-01', end: '2026-01-31' });
    await expect(page.getByText('[2026-01-01 〜 2026-01-31]')).toBeVisible();
  });

  test('6件で 2 ページに分割される', async ({ page }) => {
    for (let i = 1; i <= 6; i += 1) {
      await createTaskFullstack(page, `PgTask ${i}`);
    }
    await page.goto('/tasks');
    await expect(taskItems(page)).toHaveCount(5);

    await page.goto('/tasks?page=2');
    await expect(taskItems(page)).toHaveCount(1);
  });

  test('画像を添付でき、所有者として閲覧できる', async ({ page }) => {
    await page.goto('/tasks');
    await page.fill('input[name="title"]', 'With image');
    await page.setInputFiles('input[name="image"]', pngUpload());
    await page.getByRole('button', { name: '追加' }).click();
    await page.getByRole('button', { name: 'この内容で登録' }).click();

    const item = taskItems(page).filter({ hasText: 'With image' });
    const img = item.locator('img').first();
    await expect(img).toBeVisible();

    const src = await img.getAttribute('src');
    expect(src).not.toBeNull();
    const resp = await page.request.get(src as string);
    expect(resp.status()).toBe(200);
  });
});
