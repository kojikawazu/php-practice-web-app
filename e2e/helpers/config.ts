/**
 * E2E の対象 URL を解決する唯一の入口。
 *
 * E2E は登録・作成・削除を繰り返すため、対象を誤ると壊してはいけない環境を壊す。
 * 環境変数での上書きは許すが、**ホストは allowlist で検証**し、外れていたらテストが
 * 1 件も走る前に落とす（`.claude/rules/testing.md`「テスト対象・テスト DB の接続先」）。
 * 既定値は利便性のためのものであり、安全性の判定は allowlist が担う。
 */

/** テスト対象として許可するホスト。ローカル以外を E2E の対象にしない。 */
const ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1'];

/**
 * 環境変数（未設定なら既定値）から baseURL を解決し、ホストを allowlist で検証する。
 *
 * @param name     上書きに使う環境変数名（失敗メッセージに出す）
 * @param value    環境変数の値（未設定なら undefined）
 * @param fallback 未設定時の既定 URL（compose のポート）
 * @throws {Error} URL として解釈できない場合、または許可されていないホストの場合
 */
export function resolveUrl(name: string, value: string | undefined, fallback: string): string {
  const url = value ?? fallback;

  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    throw new Error(
      `${name} が URL として解釈できない: ${url}\n` +
        `復旧手順: ${name} を未設定に戻す（既定: ${fallback}）。`,
    );
  }

  // スキームを先に検査する。'localhost:8001'（スキーム抜き）は new URL が例外を投げず
  // protocol='localhost:' / hostname='' として通るため、ホスト検査だけだと
  // 「許可されていないホスト: （空）」という読めないメッセージになる。
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    throw new Error(
      `${name} が http(s) の URL ではない: ${url}（解釈されたスキーム: ${parsed.protocol}）\n` +
        `復旧手順: スキームから書く（例: ${fallback}）か、${name} を未設定に戻す。`,
    );
  }

  // new URL('http://[::1]:8003').hostname は角括弧付きの '[::1]' を返す。
  const host = parsed.hostname.replace(/^\[|\]$/g, '');

  if (!ALLOWED_HOSTS.includes(host)) {
    throw new Error(
      `${name} が許可されていないホストを指している: ${host}（解決した URL: ${url}）\n` +
        `E2E は登録・作成・削除を実行するため、ローカル（${ALLOWED_HOSTS.join(' / ')}）以外を対象にできない。\n` +
        `復旧手順: ${name} を未設定に戻し、make setup でローカル実環境を起動してから再実行する（既定: ${fallback}）。`,
    );
  }

  return url;
}

/** 対象アプリの baseURL。環境変数で上書き可能（未設定なら compose の既定ポート）。 */
export const URLS = {
  fullstack: resolveUrl('E2E_FS_URL', process.env.E2E_FS_URL, 'http://localhost:8001'),
  api: resolveUrl('E2E_API_URL', process.env.E2E_API_URL, 'http://localhost:8002'),
  laminas: resolveUrl('E2E_LAMINAS_URL', process.env.E2E_LAMINAS_URL, 'http://localhost:8003'),
};

/** テストユーザー共通パスワード（登録要件の 8 文字以上を満たす）。 */
export const PASSWORD = 'password123';
