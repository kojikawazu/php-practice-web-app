/** 対象アプリの baseURL。環境変数で上書き可能（未設定なら compose の既定ポート）。 */
export const URLS = {
  fullstack: process.env.E2E_FS_URL ?? 'http://localhost:8001',
  api: process.env.E2E_API_URL ?? 'http://localhost:8002',
  laminas: process.env.E2E_LAMINAS_URL ?? 'http://localhost:8003',
};

/** テストユーザー共通パスワード（登録要件の 8 文字以上を満たす）。 */
export const PASSWORD = 'password123';
