# PHP Practice Web App

PHP の学習を目的とした練習用 Web アプリケーション

## Rules

明示的な指示がなくても、`.claude/rules/` 内のルールを常に守ってください。

| ファイル | スコープ | 内容 |
| --------- | --------- | ------ |
| shortcuts.md | 全体 | 指示ショートカット（PR出して、PR承認しました 等） |
| workflow.md | 全体 | 開発フロー（ブランチ運用・テスト必須） |
| quality-gate.md | 全体 | 品質ゲート（セルフレビュー・設計/実装レビュー） |
| documentation.md | 全体 | ドキュメント更新ルール |
| git.md | 全体 | GitHub Flow・ブランチ命名・push 禁止物 |
| github-issue.md | 全体 | GitHub issue 運用（ブランチと対で起票・open/close で進捗管理・サブ issue） |
| pr-description.md | 全体 | PR 本文の必須セクション（変更種別ごとの項目・3 アプリ影響の明示・テンプレートとの関係） |
| github-actions.md | .github/workflows/** | GitHub Actions のルール（actionlint による静的解析・変更内容に応じたジョブ実行・パスフィルタ・デプロイ） |
| testing.md | 全体 | テスト分類・原則 |
| security.md | 全体 | セキュリティ最低線（認証・通信・インジェクション対策・シークレット管理） |
| production-data.md | 全体 | データ保護（共有 MySQL への破壊的操作の禁止・接続先確認・AI エージェント制約） |
| static-analysis.md | 全体 | 静的解析の運用（役割分担・CI 必須・baseline・抑制コメント） |
| duplication.md | 全体 | 重複と共通化の判断基準（3 アプリ間は共通化しない） |
| dead-code.md | 全体 | デッドコード禁止（削除対象・フレームワーク規約の例外・検出手段） |
| coding-standards.md | apps/**（PHPコード） | PHP 8.3 / PSR-12 / PHPDoc / Pint・phpcs・Psalm / Composer |
| error-handling.md | apps/**（PHPコード） | バリデーション・HTTP ステータス・所有者スコープ・ログ |
| php.md | apps/**（PHPコード） | Laravel / Laminas のスタック別作法（所有者スコープ・DB・監査列・認証・DI） |
