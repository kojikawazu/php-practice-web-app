let counter = 0;

/**
 * テスト間・実行間で衝突しない一意サフィックスを返す。
 * E2E は実 MySQL に対して走るため、ユーザー名/メールは毎回ユニークにして
 * 所有者スコープでテストを独立させる（DB 全リセットはしない方針）。
 */
export function uniqueSuffix(): string {
  counter += 1;
  const rand = Math.floor(Math.random() * 1_000_000);
  return `${Date.now().toString(36)}_${counter}_${rand}`;
}

export function uniqueEmail(): string {
  return `e2e_${uniqueSuffix()}@example.test`;
}

/** laminas の username は英数字前提のため記号を含めない。 */
export function uniqueUsername(): string {
  return `e2e_${uniqueSuffix()}`;
}
