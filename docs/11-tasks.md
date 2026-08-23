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
| 15 | 共通ルール整備: `.claude/rules/` に横断ルール 6 件（github-issue / github-actions / security / static-analysis / duplication / dead-code）を追加し CLAUDE.md と同期 | DONE | - | - |
| 16 | **CI の発火制御を整備**: パスフィルタを除外リスト方式（fail-open 解消）に変更し、md 変更時のみ走る `markdown-lint` ジョブと `concurrency` を追加 | DONE | - | - |
| 17 | CI の発火条件を `test` / `lint` / `e2e` に分離（各ツールが実際に読む範囲に基づき、無関係な変更で片方を走らせない） | DONE | - | - |
| 27 | **2MB の画像をアプリの仕様どおり受け付ける**: nginx / PHP の上限を引き上げ、アプリのバリデーションを最終判定にする（issue #87）| DONE | - | - |
| 26 | **親 issue の進捗集約を GitHub のサブ issue 機能へ移行**: 手書きチェックリストを廃止し、進捗を自動集計させる（issue #109）| DONE | - | - |
| 25 | **fresh clone から 3 アプリを起動できる初期化手順**: `scripts/setup.sh`（冪等）を新設し `make setup` と CI の e2e ジョブで共有。ホスト側ポートも上書き可能にする（issue #84）| DONE | - | - |
| 24 | **api の部分更新でもタスク期間の整合性を検証**: 更新後に確定する開始日・終了日を組み立ててから「終了日 ≥ 開始日」を判定する（issue #83）| DONE | - | - |
| 23 | **laminas の認証成功時にセッション ID を再生成**: セッション固定攻撃対策。`use_strict_mode` と Cookie 属性（HttpOnly / SameSite=Lax）も併せて明示（issue #85）| DONE | - | - |
| 22 | **laminas に完了・未完了の切替を追加**: 要件 F-06 が未実装だった箇所を、#21 で整えた POST + CSRF の土台に載せて実装（issue #89）| DONE | - | - |
| 21 | **laminas の状態変更を POST + CSRF で保護**: ログアウト・複製・削除を POST 限定（GET は 405）にし、全 POST を一括で CSRF 検証（不正は 403）（issue #82）| DONE | - | - |
| 20 | **3 アプリへ CSP を導入**: nonce ベース（script-src に `'unsafe-inline'` を付けない）で全応答にヘッダーを付与し、ヘッダー内容と実挙動の両方をテストで固定（issue #90）| DONE | - | - |
| 19 | **画像ファイルと DB の整合性を担保**: 両 Laravel アプリで「ファイルは早く作り遅く消す」順序と補償削除を導入し、失敗注入テストを追加（issue #91）| DONE | - | - |
| 18 | **actionlint を CI に導入**: workflow 自身の静的解析（構文・式・shellcheck）を `.github/workflows/**` 変更時に強制。`make actionlint` で手元と CI を同一コマンドに揃える | DONE | - | - |

## 進捗メモ

> テスト規模（ケース数・正常/異常の比率）の正本はここに置く。各 PR 完了時点の実測値であり、テストを増減させた PR で更新する。`docs/08`（戦略・ケース一覧）と `docs/12`（読む順番）では件数を持たない。

- PR #1〜#16 すべてマージ済み。main は CI green を維持。
- 自動テスト規模（PHPUnit）: fullstack 71 / api 74 / laminas 103（IT 55 件・画像整合性の失敗注入 19 件・CSP 16 件・CSRF 14 件を含む）。異常系（準正常系含む）が正常系を上回る配分（実測 約 1:1.3）。
- E2E 規模（Playwright）: fullstack 26 / laminas 32 / api 23 = 計 81 ケース（実環境に対して全 green を確認）。E2E は主要フローの通し確認が主眼のため正常系がやや多め（約 1:1）で、境界値・不正入力・所有者スコープの異常系は各アプリでカバー。
- #12（IT 導入）DONE: 粒度軸（単体/IT/E2E）を `docs/08` に定義。laminas に `TaskControllerIntegrationTest` / `AuthControllerIntegrationTest` を追加（`AbstractHttpControllerTestCase` で dispatch し、SQLite in-memory + 認証識別子注入）。Laravel の Feature は IT として分類明記（コード変更なし）。
- 未了フォロー（#8b）: カバレッジ計測（CI は現状 `coverage: none`）。#8a（所有者スコープの PHPUnit 化）は `TaskTableTest`（SQLite in-memory）で完了。
- #13（E2E 導入）DONE: `e2e/`（Playwright / TypeScript）を新設。実環境（compose・実 MySQL）に対し fullstack/laminas はブラウザ、api は HTTP(Bearer) で計 55 ケース（正常/準正常/異常）を実行し全 green を確認。`make e2e` / CI 専用 `e2e` ジョブ（compose 起動→migrate→playwright）を追加。実行毎ユニークユーザー + 所有者スコープでテストを独立させ、DB 全リセットはしない。laminas の実セッション永続はこの層でカバー（IT の穴を解消）。
- #14（Larastan 導入）DONE: 両 Laravel アプリに `larastan/larastan ^3` を追加し `phpstan.neon`（`level: max`）で型解析をゲート化（Laminas の Psalm 最厳格 + baseline と対をなす）。既存指摘は `phpstan-baseline.neon`（fullstack 23 / api 25）に記録し新規のみ CI 失敗。`composer analyse`（`--memory-limit=1G`）を追加し、CI の `lint` ジョブへ組み込み。付随して依存更新時の `composer audit` で検出された guzzle/psr7 の CVE を patch 更新で解消（`docs/06`）。モデルのリレーションは既に `BelongsTo<User, Task>` 等で注釈済み（max のテンプレート不変性由来の指摘は baseline 化）。
- #15（共通ルール整備）DONE: `.claude/rules/` を 9 → 15 ファイルに拡張。テンプレをそのまま置かず既存ルールとの分担を明記（例: `security.md` は SQL バインド・`.env`・所有者スコープを `php.md` / `coding-standards.md` / `error-handling.md` へ委譲）。`duplication.md` には 3 アプリ間の意図的な重複実装を共通化対象外とする例外を追記。併せて過去 PR #1〜#31 に対応する issue を後付け起票（#32〜#62）し履歴を整備。
- #16（CI 発火制御）DONE: `changes` ジョブのパスフィルタを対象リスト → **除外リスト**方式に変更（新しいトップレベルディレクトリ追加時にテストが黙ってスキップされる fail-open を解消）。`dorny/paths-filter` は既定 OR 評価のため否定パターンが打ち消される点に対処し `predicate-quantifier: every` を使用、肯定形の `docs` 判定は別ステップに分離。md 変更時のみ走る `markdown-lint` ジョブ（`markdownlint-cli2` + `.markdownlint-cli2.jsonc`）と `concurrency` を追加。既存 md の指摘 279 件は「文体系ルールを無効化（理由を設定ファイルに明記）+ 整形崩れ 28 件を修正」で警告ゼロにした。フレームワーク雛形の md（Laravel デフォルト README / Laminas の LICENSE・COPYRIGHT）は lint 対象外。
- #17（発火条件の分離）DONE: `changes` ジョブの出力を `code` 単一から `test` / `lint` / `e2e` に分割。各ツールが実際に読む範囲を設定ファイルで確認（Larastan=`app` のみ / Pint=全体 / Psalm=`module`+`config` / phpcs=`config`+`module`）し、**そのジョブが読まないと確認できたファイルだけ**を除外した。結果、静的解析の設定のみの変更 → `lint` だけ、`phpunit.xml` のみ → `test` だけ、`compose.yaml`・`docker/**`・`e2e/**` → `e2e` だけが走る。除外リスト方式は維持（未知のファイルは全ジョブ発火）。判定は picomatch で 34 ケース検証。既知のトレードオフとして `Makefile` のみの変更ではどのジョブも走らない（CI が Makefile を経由しないため検査手段がない・`docs/09` に明記）。
- #18（actionlint 導入）DONE: `.claude/rules/github-actions.md` が要求しながら CI に存在しなかった actionlint を `actionlint` ジョブとして追加（issue #92）。取得は公式 Docker イメージのバージョン固定タグ（`rhysd/actionlint:1.7.12`）で、`make actionlint` と CI が同一コマンド。`changes` ジョブに肯定リストの `workflows` フィルタを追加し `.github/workflows/**` 変更時のみ発火させる。導入時に既存 `ci.yml` の shellcheck 指摘 2 件（SC2034: `for i in $(seq ...)` のループ変数が未使用）を検出し `for _` に修正した。
- #27（アップロードサイズの整合）DONE: nginx に `client_max_body_size` の指定が無く**既定の 1m** が先に効くため、アプリが許可している 1MB 超〜2MB の正当な画像が 413 で弾かれ、Laravel の検証に到達しなかった（issue #87）。設計の要点は「**アプリのバリデーションを最終判定にする**」こと。前段（nginx / PHP）で弾くと統一エラー形式（`{message, errors}`）を返せないため、各層の上限を **nginx（5m）< PHP（5M / 6M）** の順に並べ、nginx を通ったリクエストは必ず Laravel が判定する構成にした。PHP 側は `docker/php/uploads.ini` を `conf.d/` へ配置して既定（2M / 8M）を上書きする（既定の `upload_max_filesize=2M` は Laravel の `max:2048` とちょうど同値で境界に張り付いていた）。laminas はアップロード機能を持たないため既定の 1m のままとし、不要に大きな body を受け付けない。境界（≤2MiB=成功 / >2MiB=422 / >5MiB=413）を `docs/07` に表で明文化。E2E 5 件を追加し、実 HTTP で 201 / 201（2MiB ちょうど）/ 422 / 413 を確認した。修正前は 4 件が失敗する（413 のケースは上限 1m でも 5m でも 413 のため弁別しないが、「外側のガードが存在すること」を守るテストとして残した）。任意サイズの有効な PNG を作る `pngOfSize()` を追加（ランダムなバイト列だと `image` ルールで落ちてサイズ検証にならないため、tEXt チャンクを詰めて画像として妥当なまま太らせる）。付随して、E2E 失敗時に生成される `e2e/test-results/**` の md が `make md-lint` を落としていたため lint の対象外に追加した。
- #26（サブ issue 機能への移行）DONE: 親 issue の本文に手書きしたチェックリスト（`- [ ] #<番号>`）が実態とずれる問題を解消（issue #109）。`Closes #<番号>` で子 issue は自動クローズされるのに、**親本文のチェックボックスは誰も更新しない**ため、#94 は 12 件中 9 件がクローズ済みなのに全項目が未チェックのまま放置されていた。「`pr-approved` の手順に親 issue 更新を足す」案は人が手順を守る前提になるため採らず、#82（CSRF の一括リスナー）・#84（初期化手順の一本化）と同じく**構造で壊れなくする**方針にした。親 issue 3 件（#94 / #70 / #106・子 20 件）に GitHub のサブ issue を紐付け、本文からチェックリストを削除。優先度・深刻度・着手順はサブ issue 機能で表現できないため本文に残した。実装上の落とし穴として、API の `sub_issue_id` は issue 番号ではなく**内部 id**（`#82` → `5024698109`）を要求する点を `.claude/rules/github-issue.md` に記録した。紐付け後は本文のチェックリスト件数とサブ issue 件数を突き合わせて検証している（取りこぼしは「進捗が少なく見える」形でしか現れない）。
- #25（fresh clone の初期化手順）DONE: README の手順（`cp .env.example .env` → `make up` → `make migrate`）では **fresh clone から 3 アプリが起動しなかった**（issue #84）。compose の bind mount がイメージ側の `vendor/` を隠すため、コンテナ内での `composer install` が要る。加えて Laravel の `.env` / `APP_KEY` とフレームワークの書き込み先も未整備だった。**正しい手順を知っていたのは CI の e2e ジョブだけ**という「手順書が実在しない」状態だったので、README へ書き足すのではなく `scripts/setup.sh` を正本にして `make setup` と CI が同じものを実行する形にした（乖離を構造的に潰す）。CI からそのまま持ち込むと壊れるのが **APP_KEY** で、CI は常に fresh なので `key:generate --force` を無条件に実行しているが、ローカルで同じことをすると再実行のたびに鍵が変わり既存セッション・暗号化データが復号できなくなる。未設定時のみ生成する形にした。ポート衝突だけで fresh clone が起動できなくなるのを避けるため、ホスト側の公開ポートも `.env`（`MYSQL_PORT` / `FS_PORT` / `API_PORT` / `LAMINAS_PORT`）で上書き可能にした（既定値は変更なし・アプリ間は compose ネットワーク経由のため動作に影響しない）。検証は追跡ファイルのみをコピーした fresh clone 相当の環境で実施し、`make setup` → 3 アプリ応答（200/200/401）→ E2E 76 件全通過、再実行で `.env`・APP_KEY・DB データが不変であることを確認した。副次的に、`Makefile` のみの変更が CI で検査されないトレードオフ（`docs/09`）のうち初期化手順の部分は解消された（`scripts/setup.sh` は e2e ジョブが実行する）。
- #24（api の部分更新と期間整合性）DONE: `after_or_equal:start_date` が**比較対象をリクエストの中から探す**ため、片側だけの PATCH では対象が消えてルールが素通りし、`end_date < start_date` のタスクを保存できた（issue #83）。「更新後に確定する開始日・終了日を組み立ててから検証する」形に変更し、リクエストに無い側は保存済みの値で補う（`resolveDate()`）。エラーはクライアントが送ったフィールドに載せる（送っていない側に返しても直せないため）。日付として解釈できない入力は比較へ持ち込まず `date` ルールに委ね、同じ誤りを二重報告しない。3 アプリ影響: **api のみ**。fullstack は確認画面のペイロードで `$validated['start_date'] ?? null` と両方を必ず書くため片側だけが残らず、laminas も編集フォームが両方を送るため部分更新が存在しない。Feature 9 件・E2E 1 件を追加し、修正前に Feature 2 件が失敗することを確認済み（「タイトルのみ更新」「両方送信」「null 化」が修正前から通ることで、欠陥が片側送信の 1 点に限定されることも確認）。
- #23（laminas のセッション ID 再生成）DONE: ログイン・登録の成功時にセッション ID を振り直していなかったセッション固定攻撃の穴を解消（issue #85）。3 アプリで laminas だけが穴だったのは、Laravel が `Auth::attempt()` / `Auth::login()` の内部で `session()->migrate(true)` を呼ぶのに対し、laminas-authentication は認証だけを担い laminas-session との繋ぎ目がアプリ責務だから。`AuthSessionInterface`（本番実装 `AuthSession`）に ext/session への依存を閉じ込め、`AuthController` から「再生成 → identity 書き込み」の順で呼ぶ。ログアウトは `clearIdentity()` + `invalidate()`（CSRF トークンも失効）。併せて `session_config` を追加し `use_strict_mode`（未知の ID を採用しない）・`cookie_httponly`・`cookie_samesite=Lax` を明示、`SessionManager` をコンテナ経由の単一インスタンスにして CsrfGuard・認証ストレージ・再生成が同じセッションを見るようにした（`session_config` を足すと `SessionManagerFactory` が `session_storage` も必須で要求する点に注意）。検証は層で分担: ID が実際に変わることは実セッションが必要なため E2E 6 件（攻撃者が仕込んだ ID を被害者に持たせる固定化の再現を含む）、「認証成功の経路でだけ・identity 書き込み前に呼ばれる」ことは IT 11 件。修正を外すと E2E 4 件・IT 3 件が失敗することを確認済み（設定層で担保される Cookie 属性・`use_strict_mode` の 2 件は落ちないのが正しい）。`cookie_secure` はローカルが HTTP のため未設定とし `docs/06` の妥協へ記録した。
- #22（laminas の完了切替）DONE: 要件 F-06（`docs/02`）が laminas だけ未実装だったのを解消（issue #89）。#21 で整えた土台（`RequiresPostTrait` + `EVENT_ROUTE` の一括 CSRF 検証）に載せたため、`toggleAction` を足すだけで POST 限定・CSRF・所有者スコープが自動的に効く。**新エンドポイントが既定で保護される**という fail-closed 設計の効果がそのまま出た形。IT 5 件・E2E 3 件を追加。
- #21（laminas の CSRF 対策）DONE: ログアウト・複製・削除が GET で実行できた問題を解消（issue #82）。状態変更は POST 限定（`RequiresPostTrait` で 405 + `Allow: POST`）、CSRF は `Module::onBootstrap` の `EVENT_ROUTE` リスナーで**全 POST を一括検証**（書き忘れが無防備に直結しない設計）。トークンは `CsrfGuard` の同期トークンパターン（`random_bytes(32)` + `hash_equals`）を自前実装した。laminas-validator / laminas-session の `Csrf` は**双方 3.0 で削除予定の非推奨 API**（`getHash()` 含む）のため採用せず、抑制コメントを増やさない方を選んだ。拒否はリダイレクトで隠さず 405/403 で明示する（成功と区別できるようにする）。既存 IT・E2E も GET 前提だったため新仕様へ更新した。
- #20（CSP 導入）DONE: 3 アプリの全応答に `Content-Security-Policy` を付与（issue #90）。`script-src` はリクエストごとの nonce のみで `'unsafe-inline'`/`'unsafe-eval'` を付けない。実装層はアプリで異なり、Laravel ×2 はグローバルミドルウェア、laminas は `MvcEvent::EVENT_FINISH` リスナー + ServiceManager 共有サービス（nonce をヘッダーと PHTML で一致させるため）。nginx では設定しない（nonce を作れず、二重付与でポリシーの積になるため・`docs/09`）。`style-src` の `'unsafe-inline'` は Tailwind Play CDN が実行時に `<style>` を注入するため必須で、実ブラウザで確認のうえ既知の妥協として `docs/06` に記録した。laminas の nonce は hex（base64 だと `escapeHtmlAttr` が実体参照化して生 HTML と一致しない）。
- #19（画像と DB の整合性）DONE: `update` が旧画像を先に削除していたため、保存・DB 更新が失敗すると旧画像が復旧不能になる問題を解消（issue #91）。不変条件「DB の `image_path` は必ず実在する」を `docs/05` に明文化し、作成・複製・更新は「ファイル作成 → DB → 旧ファイル削除」、削除は「DB → ファイル」の順に統一。`Storage` の `move`/`copy` と `UploadedFile::store()` の戻り値を検査し、DB 失敗時は作成済みファイルを補償削除する。失敗注入テスト `TaskImageIntegrityTest` を両アプリに追加（api 9 / fullstack 10）。修正前コードで 11 件が失敗することを確認済み。セルフレビューで、モデルイベントによる中断（`update()`/`delete()` が例外を投げずに false を返す経路）では DB 未更新のまま旧画像を消す穴が残ることを発見し、失敗として扱うガードとテスト 4 件を追加した。Larastan baseline を 2 件返済（api の `storeImage` 戻り値 / fullstack の `deleteImage` 引数）。
- テスト改善バックログ: #8b カバレッジ計測（issue #63 で追跡）。
- 既知の妥協は `docs/06`（DB平文・flatpickr CDN の SRI 未設定）と `docs/10`（決定事項）に記録。
