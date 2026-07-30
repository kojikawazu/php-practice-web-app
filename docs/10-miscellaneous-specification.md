# 10. その他仕様書（Miscellaneous）

他の仕様書に属さない補足情報をまとめる。

## 用語集

| 用語 | 説明 |
|------|------|
| モノレポ | 複数アプリを 1 リポジトリで管理する構成 |
| fullstack / api / laminas | 3 アプリの略称（`apps/laravel-fullstack` / `apps/laravel-api` / `apps/laminas`）|
| テーブルプレフィックス | 共有 DB 内でアプリを分離する接頭辞（`fs_` / `api_` / `lam_`）|
| Sanctum | Laravel の API トークン認証パッケージ |
| InputFilter | Laminas の入力検証（フィルタ＋バリデータ）の仕組み |
| TableGateway | Laminas の Table Data Gateway パターン（DB アクセス）|
| ライブ smoke | 起動中アプリへ curl で実際にリクエストして確認する手動検証 |

## 参照資料

- ルート: `README.md`（起動・使い方）、`CLAUDE.md`（Claude Code 向け指示）、`AGENTS.md`（Codex 向け指示）、`.claude/rules/`（開発ルールの正本）
- 仕様書: `docs/01`〜`docs/11`
- 外部: Laravel / Laminas / Sanctum / flatpickr の各公式ドキュメント

## 付録・注記（主要な決定事項）

- **Laravel 11 → 12 へ移行**: CVE-2026-48019 が 11 系に修正版なしのため（`docs/06`）。
- **テストは SQLite in-memory**: 共有 MySQL を `RefreshDatabase` の全 DROP から守るため。prefix 動作は実 DB マイグレーションで確認。
- **Laminas 認証は自前 bcrypt 照合 + AuthenticationService 保存**: DbTable アダプタより bcrypt と相性が良いため。
- **flatpickr は CDN 読み込み（SRI 未設定）**: 学習用トレードオフ（`docs/06`）。
- **アプリ別 ExampleTest 残置**: スケルトン由来。整理は任意。
