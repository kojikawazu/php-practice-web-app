import { test, expect } from '@playwright/test';
import { registerApi, authHeaders } from '../../helpers/api';

test.describe('api タスク（異常系）', () => {
  test('title 欠落は 422', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', { data: {}, headers: authHeaders(user.token) });
    expect(res.status()).toBe(422);
  });

  test('title が長すぎる（255超）と 422', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', {
      data: { title: 'a'.repeat(256) },
      headers: authHeaders(user.token),
    });
    expect(res.status()).toBe(422);
  });

  test('終了日が開始日より前だと 422', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', {
      data: { title: 'x', start_date: '2026-02-10', end_date: '2026-02-01' },
      headers: authHeaders(user.token),
    });
    expect(res.status()).toBe(422);
  });

  test('他人のタスクは view/update/delete/duplicate/image が 404', async ({ request }) => {
    const owner = await registerApi(request);
    const created = await request.post('/api/tasks', {
      data: { title: 'secret' },
      headers: authHeaders(owner.token),
    });
    const id = (await created.json()).id;

    const intruder = await registerApi(request);
    const h = authHeaders(intruder.token);

    expect((await request.get(`/api/tasks/${id}`, { headers: h })).status()).toBe(404);
    expect((await request.put(`/api/tasks/${id}`, { data: { title: 'z' }, headers: h })).status()).toBe(404);
    expect((await request.delete(`/api/tasks/${id}`, { headers: h })).status()).toBe(404);
    expect((await request.post(`/api/tasks/${id}/duplicate`, { headers: h })).status()).toBe(404);
    expect((await request.get(`/api/tasks/${id}/image`, { headers: h })).status()).toBe(404);
  });
});
