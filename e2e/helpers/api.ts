import { APIRequestContext, expect } from '@playwright/test';
import { PASSWORD } from './config';
import { uniqueEmail } from './unique';

export interface ApiUser {
  name: string;
  email: string;
  password: string;
  token: string;
}

/** ユニークなユーザーを登録し、Bearer トークン付きの ApiUser を返す。 */
export async function registerApi(request: APIRequestContext, name = 'E2E User'): Promise<ApiUser> {
  const email = uniqueEmail();
  const res = await request.post('/api/register', {
    data: { name, email, password: PASSWORD },
    headers: { Accept: 'application/json' },
  });
  expect(res.status()).toBe(201);
  const body = await res.json();
  return { name, email, password: PASSWORD, token: body.token };
}

/** Bearer + JSON の共通ヘッダ。 */
export function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' };
}
