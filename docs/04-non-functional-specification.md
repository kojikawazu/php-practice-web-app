# 04. 非機能仕様書（Non-Functional Specification）

機能以外の品質特性（性能・可用性など）を定義する。学習用ローカル環境前提のため、目標は実用的な範囲にとどめる。

## パフォーマンス

- 学習用途のため厳密な SLA は設けない。ローカルで体感的に快適（一覧表示が 1 秒以内程度）であればよい。
- 一覧は 5 件/ページのページネーションで件数増加時の表示コストを抑える。
- テストは高速であること（SQLite in-memory により 3 スイート計 1〜2 秒で完了）。

## スケーラビリティ

- 本番スケールは対象外。設計上の余地として、各アプリは独立コンテナ（php-fpm）で水平分割可能な構成。
- DB は共有 MySQL 1 インスタンス。アプリ別プレフィックスで名前空間を分離しており、将来は DB 分割へ移行可能。

## 可用性

- ローカル開発用のため稼働率目標は定めない。
- MySQL は healthcheck 付きで、依存アプリは DB 起動後に開始する（`compose.yaml`）。

## 信頼性・保守性

- **データ整合性**: タスクは `user_id` で所有者に紐づき、本人のみ操作可能。Laravel は FK 制約（cascade delete）。
- **テスト**: 各アプリにユニット/Feature テストを用意し、CI（GitHub Actions）で push/PR ごとに自動実行。
- **再現性**: Docker により環境を固定（PHP 8.3）。依存は `composer.lock` で固定し、`composer audit` クリーンを維持。
- **保守性**: フレームワーク標準構成・規約に従い、検証は仕組み化（Laravel: FormRequest 相当の `validate`、Laminas: InputFilter）。
- **統合テスト（IT）**: レイヤ横断フローは Laravel の Feature テスト、および laminas の `*IntegrationTest`（`dispatch` + SQLite in-memory + 認証識別子注入）で担保する（`docs/08` / `docs/11 #12`）。laminas の認可（所有者スコープ）は SQL レベル（`TaskTableTest`）とコントローラ横断（`TaskControllerIntegrationTest`）の 2 段で検証する。
- **E2E テスト**: 3 アプリの UI/HTTP を通した実フローは Playwright（`e2e/`）で **実環境・実 MySQL** に対して検証する（`docs/08` / `docs/11 #13`）。laminas の実セッション永続（Cookie を跨いだ保護維持）もこの層でカバーする。
- **既知の限界**: カバレッジは未計測（`docs/11 #8b`）。E2E は実 MySQL に実行毎ユニークなユーザーの残渣が少量たまる（DB 全リセットはしない方針）。
