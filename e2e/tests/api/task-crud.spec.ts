import { test, expect } from '@playwright/test';
import { registerApi, authHeaders } from '../../helpers/api';
import { URLS } from '../../helpers/config';
import { pngUpload } from '../../helpers/png';

test.describe('api タスク CRUD（正常系）', () => {
  test('作成→取得→更新→削除を通しで実行できる', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);

    const created = await request.post('/api/tasks', { data: { title: 'API task' }, headers: h });
    expect(created.status()).toBe(201);
    const task = await created.json();
    expect(task.title).toBe('API task');
    expect(task.done).toBe(false);
    const id = task.id;

    const shown = await request.get(`/api/tasks/${id}`, { headers: h });
    expect(shown.status()).toBe(200);
    expect((await shown.json()).id).toBe(id);

    const updated = await request.put(`/api/tasks/${id}`, { data: { title: 'API updated' }, headers: h });
    expect(updated.status()).toBe(200);
    expect((await updated.json()).title).toBe('API updated');

    const deleted = await request.delete(`/api/tasks/${id}`, { headers: h });
    expect(deleted.status()).toBe(204);

    const gone = await request.get(`/api/tasks/${id}`, { headers: h });
    expect(gone.status()).toBe(404);
  });

  test('複製すると 201 で「（コピー）」が付く', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    const created = await request.post('/api/tasks', { data: { title: 'Dup' }, headers: h });
    const id = (await created.json()).id;

    const dup = await request.post(`/api/tasks/${id}/duplicate`, { headers: h });
    expect(dup.status()).toBe(201);
    expect((await dup.json()).title).toBe('Dup（コピー）');
  });

  test('日付付きで作成でき、Y-m-d で返る', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    const res = await request.post('/api/tasks', {
      data: { title: 'Dated', start_date: '2026-01-01', end_date: '2026-01-31' },
      headers: h,
    });
    expect(res.status()).toBe(201);
    const task = await res.json();
    expect(task.start_date).toBe('2026-01-01');
    expect(task.end_date).toBe('2026-01-31');
  });

  test('per_page は 1〜50 にクランプされる', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    const res = await request.get('/api/tasks?per_page=999', { headers: h });
    expect(res.status()).toBe(200);
    expect((await res.json()).per_page).toBe(50);
  });

  test('タイトル検索で絞り込める', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    await request.post('/api/tasks', { data: { title: 'apple' }, headers: h });
    await request.post('/api/tasks', { data: { title: 'banana' }, headers: h });

    const res = await request.get('/api/tasks?q=apple', { headers: h });
    const body = await res.json();
    expect(body.total).toBe(1);
    expect(body.data[0].title).toBe('apple');
  });

  test('画像付きで作成すると image_url が返り、所有者として取得できる', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    const res = await request.post('/api/tasks', {
      headers: h,
      multipart: { title: 'with image', image: pngUpload() },
    });
    expect(res.status()).toBe(201);
    const task = await res.json();
    expect(typeof task.image_url).toBe('string');

    const url = new URL(task.image_url as string, URLS.api);
    const img = await request.get(url.pathname + url.search, { headers: h });
    expect(img.status()).toBe(200);
  });
});
