# 11. タスク（Tasks）

開発タスク・マイルストーン・進捗を管理する。

## マイルストーン

| マイルストーン | 内容 | 状態 |
|----------------|------|------|
| M1 基盤 | モノレポ + Docker + MySQL + テスト（PR #1〜3）| 完了 |
| M2 認証 | 3アプリ認証 + ユーザー毎タスク（PR #4）| 完了 |
| M3 機能拡充 | 編集 / 検索・ページ / トークン管理 / バリデーション / 複製（PR #5〜9）| 完了 |
| M4 CI | GitHub Actions 自動テスト（PR #10）| 完了 |
| M5 日付 | 開始日・終了日（カレンダー）（PR #11）| 完了 |
| M6 UI/UX拡充 | 仕様同期 / Tailwind / 画像アップロード / URL プレビュー(SSRF対策) / 2ステップ確認画面（PR #12〜16）| 完了 |
| M7 テスト拡充 | IT 導入（PR #28）/ E2E 導入（Playwright・3アプリ横断・CI）| 進行中 |

## タスク一覧


| ID | タスク | 状態 | 担当 | 期日 |
|----|--------|------|------|------|
| 1 | モノレポ初期構築（3アプリ + Docker + MySQL共有 + テスト） | DONE | - | - |
| 2 | Laravel 依存をパッチ版へ更新し `composer audit` をクリーンに（→ Laravel 12.61.1 へアップグレードで解消） | DONE | - | - |
| 3 | laminas スキャフォールド同梱の Dockerfile / docker-compose.yml（未使用）の整理判断 | DONE | - | - |
| 4 | 各アプリへ認証（Sanctum 等）・追加機能を学習課題として実装（3アプリに認証＋ユーザー毎Task） | DONE | - | - |
| 5 | 追加機能: タスク編集 / ページネーション・検索 / API トークン管理 / Laminas バリデーション / タスク複製 | DONE | - | - |
| 6 | CI（GitHub Actions）で push/PR 時に3アプリのテストを自動実行 | DONE | - | - |
| 7 | UI/UX 拡充: Tailwind 適用 / 画像アップロード（所有者のみ閲覧）/ URL の OGP プレビュー（SSRF対策）/ 新規・編集・複製の2ステップ確認画面 | DONE | - | - |
| 8 | （フォロー）laminas の異常系（所有者スコープ）を PHPUnit 化・カバレッジ計測 | 一部DONE | - | - |
| 8a | ↳ laminas 所有者スコープ（`TaskTable`）を SQLite in-memory で PHPUnit 化 | DONE | - | - |
| 8b | ↳ カバレッジ計測（CI の `coverage: none` を解除） | TODO | - | - |
| 9 | 静的チェックを CI 導入（Laravel=Pint / Laminas=phpcs+Psalm）・既存違反解消・Psalm baseline 運用 | DONE | - | - |
| 10 | PHPDoc 規約整備（`coding-standards.md`）と 3 アプリへの付与 | DONE | - | - |
| 11 | テスト拡充: laminas 認可 PHPUnit 化 / api ユニット新設 / 異常系（境界値・per_page クランプ）追加 | DONE | - | - |
| 12 | **IT（統合テスト）の導入**: レイヤ横断の結合テスト方針を定義。特に laminas のコントローラ↔認証↔`TaskTable` フローを PHPUnit 化（Laravel の Feature は IT として整理） | DONE | - | - |
| 13 | **E2E テストの導入**: Playwright（`e2e/`）で 3 アプリ（fullstack/laminas=ブラウザ, api=HTTP/Bearer）の主要フロー（登録→ログイン→CRUD→ログアウト）と認可・境界値・不正入力を実環境（実 MySQL）で検証。CI に専用 `e2e` ジョブを追加 | DONE | - | - |
| 14 | Larastan 導入: Laravel 2 アプリにも型解析を追加し Psalm 相当のゲート化（`BelongsTo<>` 等のジェネリクスを活用） | DONE | - | - |

## 進捗メモ

- PR #1〜#16 すべてマージ済み。main は CI green を維持。
- 自動テスト規模（PHPUnit）: fullstack 54 / api 52 / laminas 68（IT 20 件を追加）。異常系（準正常系含む）が正常系を上回る配分（実測 約 1:1.3）。
- E2E 規模（Playwright）: fullstack 21 / laminas 16 / api 18 = 計 55 ケース（実環境に対して全 green を確認）。E2E は主要フローの通し確認が主眼のため正常系がやや多め（約 1:1）で、境界値・不正入力・所有者スコープの異常系は各アプリでカバー。
- #12（IT 導入）DONE: 粒度軸（単体/IT/E2E）を `docs/08` に定義。laminas に `TaskControllerIntegrationTest` / `AuthControllerIntegrationTest` を追加（`AbstractHttpControllerTestCase` で dispatch し、SQLite in-memory + 認証識別子注入）。Laravel の Feature は IT として分類明記（コード変更なし）。
- 未了フォロー（#8b）: カバレッジ計測（CI は現状 `coverage: none`）。#8a（所有者スコープの PHPUnit 化）は `TaskTableTest`（SQLite in-memory）で完了。
- #13（E2E 導入）DONE: `e2e/`（Playwright / TypeScript）を新設。実環境（compose・実 MySQL）に対し fullstack/laminas はブラウザ、api は HTTP(Bearer) で計 55 ケース（正常/準正常/異常）を実行し全 green を確認。`make e2e` / CI 専用 `e2e` ジョブ（compose 起動→migrate→playwright）を追加。実行毎ユニークユーザー + 所有者スコープでテストを独立させ、DB 全リセットはしない。laminas の実セッション永続はこの層でカバー（IT の穴を解消）。
- #14（Larastan 導入）DONE: 両 Laravel アプリに `larastan/larastan ^3` を追加し `phpstan.neon`（`level: max`）で型解析をゲート化（Laminas の Psalm 最厳格 + baseline と対をなす）。既存指摘は `phpstan-baseline.neon`（fullstack 23 / api 25）に記録し新規のみ CI 失敗。`composer analyse`（`--memory-limit=1G`）を追加し、CI の `lint` ジョブへ組み込み。付随して依存更新時の `composer audit` で検出された guzzle/psr7 の CVE を patch 更新で解消（`docs/06`）。モデルのリレーションは既に `BelongsTo<User, Task>` 等で注釈済み（max のテンプレート不変性由来の指摘は baseline 化）。
- テスト改善バックログ: #8b カバレッジ計測。
- 既知の妥協は `docs/06`（DB平文・flatpickr CDN の SRI 未設定）と `docs/10`（決定事項）に記録。
