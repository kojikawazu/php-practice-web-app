import { test, expect } from '@playwright/test';
import { registerFullstack, createTaskFullstack, setDate, taskItems } from '../../helpers/fullstack';
import { uniqueEmail } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';
import { URLS } from '../../helpers/config';
import { pngUpload } from '../../helpers/png';

test.describe('fullstack タスク（準正常・異常系）', () => {
  test('空タイトルは確認画面へ進まず、エラーになる', async ({ page }) => {
    await registerFullstack(page, { name: 'User', email: uniqueEmail(), password: PASSWORD });
    await page.goto('/tasks');
    await page.getByRole('button', { name: '追加' }).click();

    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByRole('button', { name: 'この内容で登録' })).toHaveCount(0);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });

  test('終了日が開始日より前だと登録できない', async ({ page }) => {
    await registerFullstack(page, { name: 'User', email: uniqueEmail(), password: PASSWORD });
    await page.goto('/tasks');
    await page.fill('input[name="title"]', 'Bad dates');
    await setDate(page, 'start_date', '2026-02-10');
    await setDate(page, 'end_date', '2026-02-01');
    await page.getByRole('button', { name: '追加' }).click();

    await expect(page.getByRole('button', { name: 'この内容で登録' })).toHaveCount(0);
    await expect(page.locator('.bg-red-100')).toBeVisible();
  });

  test('確認画面でキャンセルすると作成されない', async ({ page }) => {
    await registerFullstack(page, { name: 'User', email: uniqueEmail(), password: PASSWORD });
    await page.goto('/tasks');
    await page.fill('input[name="title"]', 'Cancelled task');
    await page.getByRole('button', { name: '追加' }).click();
    await expect(page.getByRole('button', { name: 'この内容で登録' })).toBeVisible();

    await page.getByRole('link', { name: 'キャンセル' }).click();
    await expect(page).toHaveURL(/\/tasks/);
    await expect(page.getByText('Cancelled task')).toHaveCount(0);
  });

  test('他人のタスクは編集できない（404）', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const pageA = await ctxA.newPage();
    await registerFullstack(pageA, { name: 'OwnerA', email: uniqueEmail(), password: PASSWORD });
    await createTaskFullstack(pageA, 'A secret');
    const editHref = await taskItems(pageA)
      .filter({ hasText: 'A secret' })
      .getByRole('link', { name: '編集' })
      .getAttribute('href');
    await ctxA.close();
    expect(editHref).not.toBeNull();

    const ctxB = await browser.newContext();
    const pageB = await ctxB.newPage();
    await registerFullstack(pageB, { name: 'IntruderB', email: uniqueEmail(), password: PASSWORD });
    const path = new URL(editHref as string, URLS.fullstack).pathname;
    const resp = await pageB.goto(path);
    expect(resp?.status()).toBe(404);
    await ctxB.close();
  });

  test('他人のタスク画像は取得できない（404）', async ({ browser }) => {
    const ctxA = await browser.newContext();
    const pageA = await ctxA.newPage();
    await registerFullstack(pageA, { name: 'OwnerA', email: uniqueEmail(), password: PASSWORD });
    await pageA.goto('/tasks');
    await pageA.fill('input[name="title"]', 'A image');
    await pageA.setInputFiles('input[name="image"]', pngUpload());
    await pageA.getByRole('button', { name: '追加' }).click();
    await pageA.getByRole('button', { name: 'この内容で登録' }).click();
    const imgSrc = await taskItems(pageA)
      .filter({ hasText: 'A image' })
      .locator('img')
      .first()
      .getAttribute('src');
    await ctxA.close();
    expect(imgSrc).not.toBeNull();

    const ctxB = await browser.newContext();
    const pageB = await ctxB.newPage();
    await registerFullstack(pageB, { name: 'IntruderB', email: uniqueEmail(), password: PASSWORD });
    const path = new URL(imgSrc as string, URLS.fullstack).pathname;
    const resp = await pageB.request.get(path);
    expect(resp.status()).toBe(404);
    await ctxB.close();
  });
});
