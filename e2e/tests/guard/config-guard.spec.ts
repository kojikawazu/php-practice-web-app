import { test, expect } from '@playwright/test';
import { resolveUrl } from '../../helpers/config';

/**
 * 対象 URL の接続先ガード（`.claude/rules/testing.md`）。
 *
 * ガードが壊れても他の E2E は緑のままになるため、ここで明示的に検証する。
 * 起動中のアプリもブラウザも要らない（guard project は baseURL を持たない）。
 */
test.describe('E2E 対象 URL の接続先ガード', () => {
  // 正常系
  test('環境変数が未設定なら既定のローカル URL を使う', () => {
    expect(resolveUrl('E2E_FS_URL', undefined, 'http://localhost:8001')).toBe('http://localhost:8001');
  });

  test('ローカルを指す上書きは許可する（localhost / 127.0.0.1 / ::1・ポート違いを含む）', () => {
    expect(resolveUrl('E2E_FS_URL', 'http://localhost:18001', 'http://localhost:8001')).toBe(
      'http://localhost:18001',
    );
    expect(resolveUrl('E2E_API_URL', 'http://127.0.0.1:8002', 'http://localhost:8002')).toBe(
      'http://127.0.0.1:8002',
    );
    expect(resolveUrl('E2E_LAMINAS_URL', 'http://[::1]:8003', 'http://localhost:8003')).toBe(
      'http://[::1]:8003',
    );
  });

  // 準正常系: 想定内の異常入力（対象を取り違えた上書き）
  test('リモートホストを指していたら実行前に落とす', () => {
    expect(() => resolveUrl('E2E_FS_URL', 'https://example.com', 'http://localhost:8001')).toThrow(
      /許可されていないホストを指している: example\.com/,
    );
  });

  test('ローカルを含んだだけの紛らわしいホストも落とす', () => {
    expect(() =>
      resolveUrl('E2E_FS_URL', 'http://localhost.example.com', 'http://localhost:8001'),
    ).toThrow(/localhost\.example\.com/);
    expect(() => resolveUrl('E2E_API_URL', 'http://127.0.0.1.example.com', 'http://localhost:8002')).toThrow(
      /127\.0\.0\.1\.example\.com/,
    );
  });

  test('失敗メッセージに解決したホストと復旧手順を含む', () => {
    let message = '';
    try {
      resolveUrl('E2E_LAMINAS_URL', 'http://10.0.0.5:8003', 'http://localhost:8003');
    } catch (e) {
      message = (e as Error).message;
    }
    expect(message).toContain('E2E_LAMINAS_URL');
    expect(message).toContain('10.0.0.5');
    expect(message).toContain('http://10.0.0.5:8003');
    expect(message).toContain('make setup');
    expect(message).toContain('http://localhost:8003');
  });

  // 異常系: 想定外の入力
  test('スキームの無い値は落とす（ホスト空のまま素通りさせない）', () => {
    // new URL('localhost:8001') は例外を投げず protocol='localhost:' として通るため、
    // ホスト検査だけでは「許可されていないホスト: （空）」という読めない失敗になる。
    expect(() => resolveUrl('E2E_FS_URL', 'localhost:8001', 'http://localhost:8001')).toThrow(
      /http\(s\) の URL ではない: localhost:8001/,
    );
    expect(() => resolveUrl('E2E_FS_URL', 'file:///etc/passwd', 'http://localhost:8001')).toThrow(
      /http\(s\) の URL ではない/,
    );
  });

  test('URL として解釈できない値は落とす', () => {
    expect(() => resolveUrl('E2E_FS_URL', '', 'http://localhost:8001')).toThrow(
      /URL として解釈できない/,
    );
    expect(() => resolveUrl('E2E_API_URL', '://broken', 'http://localhost:8002')).toThrow(
      /URL として解釈できない/,
    );
  });
});
