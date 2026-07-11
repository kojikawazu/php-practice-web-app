# コーディング規約

3 アプリ共通の PHP コーディング規約。詳細なアプリ別作法は [php.md](./php.md) を参照。

## 言語・スタイル

- **言語**: PHP 8.3（実行は Docker コンテナ内、`composer.json` は `^8.2` を許容）。
- **コーディング標準**: **PSR-12** を土台とする。ファイル冒頭で `declare(strict_types=1);` を宣言し、引数・戻り値の型宣言を付ける。
- **命名**: クラスは PascalCase、メソッド・変数は camelCase、定数は UPPER_SNAKE_CASE。名前空間は PSR-4 に従う（Laravel=`App\`、Laminas=`Application\`）。

## PHPDoc（DocBlock）

PHP 8 の型宣言を第一とし、PHPDoc は**言語の型システムで表現できない情報の補完**として使う（型の二重記述はしない）。

- **型注釈が必須なケース**: 引数・戻り値・戻り値の型宣言だけでは要素型が伝わらないもの。
  - コレクション/イテレータの要素型: `@return iterable<Task>` / `@param array<string, mixed> $data`
  - `mixed`・`object`・配列の中身を具体化したいとき。
  - Laminas は Psalm を `errorLevel="1"`（最厳格）で回すため、上記の型注釈 PHPDoc は**必須**（欠けると解析エラー）。
- **説明 DocBlock を推奨するケース**: クラス・非自明なメソッドの「意図・責務・前提」。型からは読み取れない背景を 1〜2 行で書く（例: `TaskTable` の「認証導入後は user_id でスコープする」）。
- **書かないもの**: 型宣言と重複するだけの `@param string $name`、getter/setter 等の自明なメソッド、フレームワーク雛形が生成した定型 DocBlock（削除も追記も不要・現状維持）。
- **フォーマット**: 要約は日本語可。`@param`/`@return` を書く場合は実際の型・引数名と一致させ、Pint / phpcs のフォーマットに従う。

## Lint / Format（アプリ別）

| アプリ | ツール | 実行 |
|--------|--------|------|
| laravel-fullstack / laravel-api | Laravel Pint（フォーマッタ）+ Larastan/PHPStan（静的解析・`level: max`） | `composer exec pint`（`pint --test` で差分検査）/ `composer analyse` |
| laminas | phpcs / phpcbf（Laminas Coding Standard）+ Psalm（静的解析） | `composer cs-check` / `composer cs-fix` / `vendor/bin/psalm` |

- コミット前に該当アプリのフォーマッタを通し、差分ゼロにする。
- Laminas は配列を短縮構文 `[]` で書く（`array()` 禁止・phpcs で強制）。
- **CI で強制**: `lint` ジョブが Pint（`--test`）・Larastan・phpcs・Psalm を実行する。静的解析の既存指摘は baseline に記録済み（Laravel=`apps/laravel-*/phpstan-baseline.neon` / Laminas=`apps/laminas/psalm-baseline.xml`）で、**新規に増えた指摘のみ CI を失敗させる**（baseline は段階的に減らす運用）。Larastan はメモリ上限のため `composer analyse`（`--memory-limit=1G` 込み）で実行する。

## 依存・設定

- **パッケージマネージャ**: Composer を使用（ホストに PHP/Composer は不要、コンテナ内で実行）。`composer.lock` を必ずコミットする。
- **依存更新時**: `composer audit` を実行し、クリーンを維持する（Laravel は解消済み CVE の再発監視、詳細は `docs/06`）。
- **環境変数**: 設定値は `.env`（`.env.example` を雛形）で管理し、`.env` はコミットしない。
- **シークレット禁止**: 認証情報・鍵をコードにハードコードしない（`docs/06` の既知の妥協点を除く）。
