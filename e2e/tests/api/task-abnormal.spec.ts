import { test, expect } from '@playwright/test';
import { registerApi, authHeaders } from '../../helpers/api';
import { pngUploadOfSize } from '../../helpers/png';

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

  /**
   * 部分更新（PATCH）でも期間の整合性を守る。
   * after_or_equal:start_date は「両方がリクエストにある」前提のルールで、
   * 片側だけ送ると比較対象が消えて素通りする（issue #83 / docs/07）。
   */
  test('部分更新で片側だけ送っても期間の整合性が保たれる', async ({ request }) => {
    const user = await registerApi(request);
    const h = authHeaders(user.token);
    const created = await request.post('/api/tasks', {
      data: { title: 'partial', start_date: '2026-02-10', end_date: '2026-02-20' },
      headers: h,
    });
    const id = (await created.json()).id;

    // 開始日だけを保存済みの終了日より後へ動かす
    const badStart = await request.patch(`/api/tasks/${id}`, {
      data: { start_date: '2026-02-25' },
      headers: h,
    });
    expect(badStart.status()).toBe(422);

    // 終了日だけを保存済みの開始日より前へ動かす
    const badEnd = await request.patch(`/api/tasks/${id}`, {
      data: { end_date: '2026-02-01' },
      headers: h,
    });
    expect(badEnd.status()).toBe(422);

    // 期間内への片側更新は通る
    const ok = await request.patch(`/api/tasks/${id}`, {
      data: { start_date: '2026-02-15' },
      headers: h,
    });
    expect(ok.status()).toBe(200);

    const task = await (await request.get(`/api/tasks/${id}`, { headers: h })).json();
    expect(task.start_date).toBe('2026-02-15');
    expect(task.end_date).toBe('2026-02-20');
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

  /**
   * アップロードサイズの境界（docs/07）。
   * 2MB 以下は受け付け、2MB 超はアプリのバリデーションが 422 で拒否する。
   * nginx の client_max_body_size を既定（1m）のままにすると、1MB 超の
   * 正当な画像が 413 になり Laravel の検証に到達しない（issue #87）。
   */
  test('1MB 超 2MB 以下の画像は登録できる', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', {
      multipart: { title: 'big but ok', image: pngUploadOfSize(1_500_000) },
      headers: authHeaders(user.token),
    });
    expect(res.status()).toBe(201);
    expect(typeof (await res.json()).image_url).toBe('string');
  });

  test('2MB 超の画像はアプリの検証で 422（統一エラー形式）', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', {
      multipart: { title: 'too big', image: pngUploadOfSize(2_200_000) },
      headers: authHeaders(user.token),
    });
    // nginx や PHP ではなく Laravel が弾く＝ errors 付きの JSON が返る
    expect(res.status()).toBe(422);
    const body = await res.json();
    expect(body.errors).toHaveProperty('image');
  });

  test('nginx の上限を超える body は 413 で切られる', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/tasks', {
      multipart: { title: 'way too big', image: pngUploadOfSize(5_500_000) },
      headers: authHeaders(user.token),
    });
    // 外側のハードガード。ここは JSON ではなく nginx の HTML が返る
    expect(res.status()).toBe(413);
  });
});
