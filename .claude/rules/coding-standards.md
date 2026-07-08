# コーディング規約

3 アプリ共通の PHP コーディング規約。詳細なアプリ別作法は [php.md](./php.md) を参照。

## 言語・スタイル

- **言語**: PHP 8.3（実行は Docker コンテナ内、`composer.json` は `^8.2` を許容）。
- **コーディング標準**: **PSR-12** を土台とする。ファイル冒頭で `declare(strict_types=1);` を宣言し、引数・戻り値の型宣言を付ける。
- **命名**: クラスは PascalCase、メソッド・変数は camelCase、定数は UPPER_SNAKE_CASE。名前空間は PSR-4 に従う（Laravel=`App\`、Laminas=`Application\`）。

## Lint / Format（アプリ別）

| アプリ | ツール | 実行 |
|--------|--------|------|
| laravel-fullstack / laravel-api | Laravel Pint（フォーマッタ） | `composer exec pint`（`pint --test` で差分検査） |
| laminas | phpcs / phpcbf（Laminas Coding Standard）+ Psalm（静的解析） | `composer cs-check` / `composer cs-fix` / `vendor/bin/psalm` |

- コミット前に該当アプリのフォーマッタを通し、差分ゼロにする。
- Laminas は配列を短縮構文 `[]` で書く（`array()` 禁止・phpcs で強制）。

## 依存・設定

- **パッケージマネージャ**: Composer を使用（ホストに PHP/Composer は不要、コンテナ内で実行）。`composer.lock` を必ずコミットする。
- **依存更新時**: `composer audit` を実行し、クリーンを維持する（Laravel は解消済み CVE の再発監視、詳細は `docs/06`）。
- **環境変数**: 設定値は `.env`（`.env.example` を雛形）で管理し、`.env` はコミットしない。
- **シークレット禁止**: 認証情報・鍵をコードにハードコードしない（`docs/06` の既知の妥協点を除く）。
