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

## 進捗メモ

- PR #1〜#16 すべてマージ済み。main は CI green を維持。
- 自動テスト規模: fullstack 53 / api 41 / laminas 48。
- 未了フォロー（#8b）: カバレッジ計測（CI は現状 `coverage: none`）。#8a（所有者スコープの PHPUnit 化）は `TaskTableTest`（SQLite in-memory）で完了。
- 既知の妥協は `docs/06`（DB平文・flatpickr CDN の SRI 未設定）と `docs/10`（決定事項）に記録。
