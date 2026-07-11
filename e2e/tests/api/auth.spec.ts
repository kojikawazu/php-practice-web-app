import { test, expect } from '@playwright/test';
import { registerApi, authHeaders } from '../../helpers/api';
import { uniqueEmail } from '../../helpers/unique';
import { PASSWORD } from '../../helpers/config';

test.describe('api 認証（Sanctum トークン）', () => {
  // ---- 正常系 ----
  test('登録するとトークンが返る（201）', async ({ request }) => {
    const email = uniqueEmail();
    const res = await request.post('/api/register', {
      data: { name: 'Alice', email, password: PASSWORD },
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(201);
    const body = await res.json();
    expect(typeof body.token).toBe('string');
    expect((body.token as string).length).toBeGreaterThan(0);
    expect(body.user.email).toBe(email);
  });

  test('ログインするとトークンが返る（200）', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/login', {
      data: { email: user.email, password: user.password },
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(typeof body.token).toBe('string');
  });

  test('ログアウトするとトークンが失効する（204→以後 401）', async ({ request }) => {
    const user = await registerApi(request);
    const logout = await request.post('/api/logout', { headers: authHeaders(user.token) });
    expect(logout.status()).toBe(204);

    const after = await request.get('/api/tasks', { headers: authHeaders(user.token) });
    expect(after.status()).toBe(401);
  });

  // ---- 異常系 ----
  test('誤ったパスワードは 422', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/login', {
      data: { email: user.email, password: 'wrong-password' },
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(422);
  });

  test('メール重複は 422', async ({ request }) => {
    const user = await registerApi(request);
    const res = await request.post('/api/register', {
      data: { name: 'Dup', email: user.email, password: PASSWORD },
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(422);
  });

  test('短いパスワードは 422', async ({ request }) => {
    const res = await request.post('/api/register', {
      data: { name: 'Short', email: uniqueEmail(), password: 'short' },
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(422);
  });

  test('トークンなしは 401', async ({ request }) => {
    const res = await request.get('/api/tasks', { headers: { Accept: 'application/json' } });
    expect(res.status()).toBe(401);
  });

  test('無効なトークンは 401', async ({ request }) => {
    const res = await request.get('/api/tasks', {
      headers: { Authorization: 'Bearer invalid-token', Accept: 'application/json' },
    });
    expect(res.status()).toBe(401);
  });
});
