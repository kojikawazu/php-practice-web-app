---
description: ドキュメント更新・設計書管理ルール（影響マップ + opt-out の完了条件）
globs:
---

# ドキュメント

コード変更がドキュメント（CLAUDE.md / README.md / docs/）と乖離しないことを構造的に担保する。

## 完了条件（opt-out）

変更は、下記「影響マップ」の対応ドキュメントを**同一 PR 内で更新する**ことを完了条件とする。

- 更新不要と判断した場合は、**PR 説明にその理由を明記する**（省略＝未対応とみなす）。
- この乖離チェックは `/self-review` と `/pr-create` の確認対象に含まれる。

## 影響マップ（変更種別 → 更新必須ドキュメント）

「どのドキュメントだっけ？」を考えさせないための逆引き表。3 アプリ（laravel-fullstack / laravel-api / laminas）共通の仕様書を対象とする。

| 変更種別 | 更新必須ドキュメント |
| --- | --- |
| 業務要求・スコープの変更 | docs/01-business-requirements.md |
| 要件（やること/やらないこと）の変更 | docs/02-requirements-specification.md |
| 機能の挙動・ユーザーフロー・UI/UX・バリデーションの変更 | docs/03-functional-specification.md |
| 性能・可用性・スケーラビリティなど非機能の変更 | docs/04-non-functional-specification.md |
| DB スキーマ・テーブルプレフィックス・ER・データフローの変更 | docs/05-data-specification.md |
| 認証・認可・SSRF 対策など、セキュリティの変更 | docs/06-security-specification.md |
| API エンドポイント・入出力・トークン・エラー仕様の変更 | docs/07-api-specification.md |
| テスト戦略・ケース・品質目標の変更 | docs/08-test-specification.md（分類・原則は .claude/rules/testing.md） |
| アーキテクチャ・モノレポ構成・Docker/nginx 構成の変更 | docs/09-architecture-specification.md |
| 上記に収まらない運用・補足事項の変更 | docs/10-miscellaneous-specification.md |
| 開発タスク・マイルストーン・進捗の変化 | docs/11-tasks.md |
| ルートの構成・セットアップ・起動手順の変更 | README.md |
| 規約本文の変更（`.claude/rules/`） | 原則不要（正本のルールファイルのみ） |
| 規約ファイルの追加・削除・改名・適用範囲変更 | CLAUDE.md / AGENTS.md 群 / README.md（AI エージェント向けルール表） |

該当する変更がない場合はスキップする。

## 補足

- **設計書の管理**: タスクごとに設計書を新規作成しない。既存の番号付き仕様書（docs/01〜11-*.md）に追記・更新する。
- 仕様変更は 3 アプリ（laravel-fullstack / laravel-api / laminas）への影響有無を確認し、アプリ差分は各仕様書内に明記する。
- **AI エージェント向け入口の同期**: `.claude/rules/` はルール本文の唯一の正本とする。規約ファイルの構成・名称・適用対象を変更した場合は、Claude Code 向けの `CLAUDE.md`、Codex 向けの該当 `AGENTS.md`、README の対応表を同一 PR で同期する。本文だけの変更では、各入口の更新は不要。
