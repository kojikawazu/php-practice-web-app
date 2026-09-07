# 08. テスト仕様書（Test Specification）

テストの戦略・ケース・品質目標を定義する。テスト分類（正常/準正常/異常）・原則は `.claude/rules/testing.md` 準拠。

## テストの粒度（単体 / IT / E2E）

「入力に対する期待結果」を見る**分類**（正常/準正常/異常）とは直交する軸として、**どこまでのレイヤを通すか**の粒度を定義する。

| 粒度 | 定義 | 本リポジトリでの実体 |
| ------ | ------ | ---------------------- |
| 単体（Unit） | 1 レイヤ/クラスの契約を隔離して検証 | Task モデル・PasswordHasher・InputFilter・**TaskTable（SQL 所有者スコープ）** |
| IT（統合／結合） | ルーティング → コントローラ → 認証 → データアクセス層 → 実 DB を横断で検証 | **Laravel の Feature テスト全般**（ミドルウェア認証→Eloquent→SQLite）／**laminas の `*IntegrationTest`**（`AbstractHttpControllerTestCase` で dispatch し、SQLite in-memory + 認証識別子を注入） |
| E2E | ブラウザ自動化で UI から通しで検証 | **`e2e/`（Playwright / TypeScript）**。compose で起動した実環境（実 MySQL・prefix 分離）に対し、fullstack / laminas は実ブラウザ、api は HTTP（Bearer）で 3 アプリ横断に検証（`docs/11 #13` で導入） |

> laminas の IT は、bootstrap 後に ServiceManager の `AdapterInterface` を SQLite in-memory へ、`AuthenticationService` を NonPersistent ストレージ（識別子を直接注入）へ差し替えて実現する。MySQL・実セッションに依存せず、コントローラの認可分岐・リダイレクト・DB 反映という「配線」を検証する。
>
> **E2E（Playwright）の位置づけ**: IT が SQLite in-memory で「配線」を検証するのに対し、E2E は `docker compose up` した**実環境・実 MySQL**に対してブラウザ/HTTP で通す唯一のレイヤ。`RefreshDatabase` は使わず、**実行毎ユニークなユーザー**を作り所有者スコープでテストを独立させる（共有 DB を全リセットしない方針を踏襲）。1 つの Playwright ランナーに 3 projects（fullstack / laminas / api）を同居させ、baseURL（`E2E_FS_URL` / `E2E_LAMINAS_URL` / `E2E_API_URL`）で切り替える。実行は `make e2e`（事前に `make setup`）。CI では専用 `e2e` ジョブが同じ `scripts/setup.sh` で環境を用意してから Playwright を実行する。

## テスト戦略

| アプリ | ツール | DB | 種別 |
| -------- | -------- | ---- | ------ |
| laravel-fullstack | PHPUnit（`php artisan test`） | SQLite in-memory（Unit は DB 不要） | Feature（HTTP）=**IT** + Unit（LinkPreviewService / SizeCappedSink / **規約ガード（`strict_types` 宣言）**） |
| laravel-api | PHPUnit（`php artisan test`） | SQLite in-memory（Unit は DB 不要） | Feature（JSON API）=**IT** + Unit（Task モデル / **規約ガード（`strict_types` 宣言）**） |
| laminas | PHPUnit（`vendor/bin/phpunit`） | IT・認可テストは SQLite in-memory（他はモデル/サービス/InputFilter 単体で DB 不要） | Unit（Task / PasswordHasher / InputFilter×3 / **TaskTable 所有者スコープ**）+ **IT（TaskController / AuthController を dispatch）**。実セッション永続は E2E がカバー |
| 3アプリ横断（E2E） | Playwright（`make e2e` / `npx playwright test`） | 実 MySQL（compose） | **E2E**: fullstack / laminas（ブラウザ）+ api（HTTP/Bearer）。登録→ログイン→CRUD→ログアウトの実フローと認可・境界値・不正入力を実環境で検証 |
| 接続先ガード（guard） | Playwright（`--project=guard`） | 不要（純関数の検証） | **Unit**: E2E の対象 URL を解決する `e2e/helpers/config.ts` の allowlist 検証。ブラウザも起動中のアプリも要らない |

> テストを SQLite in-memory にしている理由: `RefreshDatabase` は `migrate:fresh`（全テーブル DROP）を行うため、共有 MySQL に対して実行すると他アプリのテーブルを巻き込む。テストは隔離された in-memory DB で実行し、prefix 動作は実 DB へのマイグレーションで確認する。
>
> `declare(strict_types=1)` の宣言を Unit で検証している理由: 宣言の有無は**実行時**の型変換を変えるが、Larastan（静的な型整合）も Pint（`pint.json` を置かない既定の Laravel プリセット）もこれを見ておらず、issue #66 の起票から着手までの間に未宣言ファイルが 18 → 30 件へ静かに増えていた。両 Laravel の `tests/Unit/StrictTypesDeclarationTest.php` が「**未宣言のファイル集合 = 雛形として明示的に許容した集合**」を完全一致で固定する。包含ではなく一致にすることで、雛形側に宣言が入った場合も落ちて除外リストの陳腐化を防ぐ（`.claude/rules/coding-standards.md` / `.claude/rules/testing.md`「ガード自体をテストする」）。
>
> E2E の対象 URL を allowlist で検証している理由: E2E は登録・作成・削除を繰り返すため、`E2E_FS_URL` 等がローカル以外を指すとその環境を書き換えてしまう。`e2e/helpers/config.ts` に解決を集約し、ホストが `localhost` / `127.0.0.1` / `::1` 以外なら**テストが 1 件も走る前に**落とす（`playwright.config.ts` が読み込み時に解決するため、config のロードで失敗する）。ガード自体は `guard` project で検証する（`.claude/rules/testing.md`「テスト対象・テスト DB の接続先（破壊防止）」）。

## テストケース（CRUD + 認証）

異常系（準正常系含む）が正常系を上回ることを目安とし、**認可・境界値・不正入力**を優先的にカバーする（数合わせのための水増しはしない）。分類・原則は `.claude/rules/testing.md` 準拠。ケース数・比率の実測は変動するため `docs/11` の進捗メモに置く。

| アプリ | 主な正常系 | 主な異常系 |
| -------- | ----------- | ----------- |
| fullstack Task | 一覧/作成/トグル/編集/複製/日付付き作成/画像アップロード・所有者閲覧・差替・複製コピー/2ステップ確認(確認表示・確定まで未作成)/ページネーション(5件)/タイトル検索 | guest→login / 他人タスク非表示 / 空title / 他人のtoggle・destroy・update(確認)・edit・duplicate・image 404 / 非画像422 / 検索ヒットなし / 終了日<開始日 / 不正日付 |
| fullstack 確認画面のキャンセル（**IT**・`TaskConfirmCancelTest`） | キャンセルで保留が破棄され、あとから確定 POST を投げても作成されない / 一時画像が削除される / 編集からのキャンセルは編集画面へ戻る | 保留が無い状態のキャンセルは無害（冪等）/ 消すのはセッションが指す 1 件だけで他の tmp ファイルは残る / ゲストはログインへ |
| fullstack 入口のリダイレクト（**IT**・`RootRedirectTest`） | `/` はタスク一覧へ送られる | ゲストが `/` を踏むと `/tasks` 経由でログインへ誘導される（行き止まりにしない） |
| fullstack Auth | 登録&自動ログイン / ログイン / ログアウト | 誤パスワード / メール重複 / 確認不一致 / 短パスワード |
| api Task | 一覧/201作成/更新/複製(201)/日付付き作成/画像アップロード(image_url返却)・所有者取得/ページネーション(meta)/per_page/検索 | guest 401 / title欠落 422 / 長すぎ 422 / 更新時空title 422 / 終了日<開始日 422 / 不正日付 422 / 非画像 422 / 他人タスク view・delete・update・duplicate・image 404 / 検索ヒットなし |
| api タスク期間の整合性（部分更新・`TaskApiTest`） | タイトルのみ更新で保存済みの日付が保たれる / 期間内への片側更新が通る / `null` で片側を消せる | 片側だけの更新で保存済みの相手側と矛盾すると 422（start_date 側・end_date 側の両方向）/ 両方送っての逆転も 422 / 相手側を同時に `null` 化すれば通る / 日付として不正な値は 422 |
| api Task model（単体・DB/アプリ不要） | title mass assign / done bool化 / 日付 Carbon 化(Y-m-d) / hidden・appends 設定 | done 既定false / '0'→false / 画像なしで image_url=null / toArray が user_id・image_path を隠す / 非fillable id は無視 |
| api Auth | register トークン / login トークン / logout | 誤パスワード 422 / メール重複 422 / 短パスワード 422 / token無し 401 |
| api Token | 一覧(ハッシュ非公開)/発行/期限付き発行/失効 | name必須422 / 不正expiry422 / 他人失効404 / guest401 / 期限切れトークン401 |
| laminas Task model | exchangeArray全項目 / getArrayCopy | 空配列デフォルト / '0'→false / 数値文字列→int / false→0 |
| laminas TaskTable（所有者スコープ・SQLite） | 本人タスクの取得/一覧/検索/作成/更新/削除 | 他人タスクの取得null / 他人タスクを削除しても消えない / user_id偽装updateが他人に及ばない / countが他人を除外 / 一覧が他人を除外 / 検索ヒットなし |
| laminas TaskController（**IT**・dispatch・SQLite） | 認証済み一覧が本人分のみ描画 / POST作成→302+user_idスコープ / 編集POST→更新 / **完了トグル（双方向）** / 複製→「（コピー）」作成 / 削除→消える | ゲストは index・作成・削除・**トグル**で /login へ / 他人タスクの編集・削除・複製・**トグル**が無効 / 空title は未作成で再描画 / 不在id編集は一覧へ |
| laminas 監査列（`TaskTableTest`・SQLite） | 作成で `created_at` / `updated_at` が同値で入る / 形式が `'Y-m-d H:i:s'`（MySQL の TIMESTAMP と SQLite の TEXT の双方が解釈できる）/ 更新で `updated_at` のみ前進し `created_at` は不変 / 複製は自分の `created_at` を持ち複製元は不変 | `user_id` 偽装の update は他人の監査列も動かさない（認可と監査列を同じ 1 本の UPDATE に載せていることの検証） |
| laminas 監査列（**IT**・`TaskControllerIntegrationTest`） | 作成 POST で監査列が入る（コントローラ・InputFilter は何も詰めない）/ 編集 POST で `created_at` 不変・`updated_at` 前進 | —（設定漏れは「保存はできるが日時だけ NULL」という静かな形で出るため、正常系で値の存在を固定する） |
| laminas UserTable（`UserTableTest`・SQLite） | 登録でユーザー名と bcrypt ハッシュが保存される / 監査列 `created_at` が入る / 形式が `'Y-m-d H:i:s'` / 後から作ったユーザーが既存行の `created_at` を引き継がない | 未登録のユーザー名を引くと `null` |
| laminas ユーザー監査列（**IT**・`AuthControllerIntegrationTest`） | 登録 POST で `created_at` が入る（コントローラ・InputFilter は何も詰めない） | —（登録が弾かれる異常系は同ファイルの既存ケースが担保する） |
| laminas AuthController（**IT**・dispatch・SQLite） | 登録→ユーザー作成+302 / 正パスワードでログイン→/tasks / ログアウト→/login | 重複ユーザー名は拒否（既存PW不変） / 短パスワードは未作成 / 誤パスワード拒否 / 未登録ユーザー拒否 |
| laminas セッション再生成（**IT**・`AuthControllerIntegrationTest`） | ログイン・登録で `regenerate()` が 1 回・**identity 書き込み前**に呼ばれる / ログアウトで `invalidate()` が呼ばれる | 誤パスワード・未登録ユーザー・短パスワード・ユーザー名重複・CSRF 403・GET ログアウト 405・ログイン済みでの /login 再訪では呼ばれない |
| fullstack / api 画像整合性（**失敗注入**・`TaskImageIntegrityTest`） | 差し替えは DB コミット後に旧画像を削除 / 削除でタスクと画像がともに消える | DB create 失敗で孤児ファイルを残さない（作成・複製）/ DB update 失敗で旧画像を失わない / DB delete 失敗でタスクと画像を残し再試行可能にする / ファイルの move・copy 失敗時にタスクを作らない |
| laminas CSRF（**IT**・`CsrfProtectionIntegrationTest`） | 正しいトークンで作成・削除・ログアウトが通る / 全 POST フォームにトークンが埋まる / 状態変更の導線が POST フォームである | GET での削除・複製・トグル・ログアウトは 405 で実行されない / トークン無し・不正トークンの POST は 403（作成・削除・トグル・ログイン・登録） |
| 3アプリ CSP（`CspHeaderTest` / `CspHeaderIntegrationTest`） | HTML 応答にヘッダーが付く / ヘッダーの nonce と HTML の nonce が一致 / nonce がリクエストごとに変わる / 実際に使う CDN だけを許可 | script-src に `'unsafe-inline'`・`'unsafe-eval'` が無い / ワイルドカードが無い / api は script-src を持たない / エラー応答にもヘッダーが付く |
| fullstack LinkPreview(SSRF) | public IP 許可 / title・og:image 抽出 | private・loopback・link-local・予約IP 拒否 / 非http拒否 / 内部ホスト拒否 / og:image非http除外 |
| fullstack 本文サイズ上限の実体（`SizeCappedSinkTest`） | 上限内の書き込みは全量受理 / 上限ちょうどは中断扱いにしない | 上限をまたぐ書き込みは**短い値を返す**（cURL に転送を中断させる合図）/ 上限到達後の書き込みは 0 を返す / 超過分を保持しない / 一度到達した状態は戻らない |
| fullstack プレビュー本文の切り詰め（**IT**・`LinkPreviewSizeLimitTest`） | 上限内の解析は従来どおり / 境界ちょうどは切り詰め扱いにしない | 上限を超えた位置の内容は解析に使われない / 切り詰めを info で記録 / 偽の `Content-Length` で上限を迂回できない |
| fullstack プレビュー失敗のログ（**IT**・`LinkPreviewLoggingTest`） | 通信エラー・2xx 以外・`Location` 欠落・リダイレクト上限のそれぞれで warning が出る（`reason` と切り分け情報つき）/ ログにホストのみを残し URL 全体を残さない | `\Error`（実装バグ）は握りつぶさず伝播する |
| laminas PasswordHasher | hash→verify / bcrypt形式 | 誤パスワード / 空 / 不正ハッシュ / ソルトで毎回異なる |
| laminas InputFilter | Task/Register/Login の有効入力通過・StringTrim 整形・日付任意通過 | 必須欠落 / 空 / 空白のみ / 長すぎ(255超) / 短パスワード(8未満) / 不正日付 / 終了日<開始日 |
| アップロードサイズ境界 E2E（**Playwright**・nginx 経由） | 1MB 超 2MB 以下の画像が api で 201・fullstack で確認画面へ進める（nginx 既定 1m だと 413 になる箇所） | 2MB 超は **Laravel の 422**（`errors.image` 付き。nginx/PHP ではなくアプリが判定）/ fullstack はエラー表示で確認画面へ進まない / nginx の上限超（5MB 超）は 413 |
| fullstack E2E（**Playwright**・実ブラウザ・実MySQL） | 登録→自動ログイン→一覧 / ログアウト→再ログイン / 作成(確認画面→確定) / 編集 / 複製 / 完了トグル / 削除 / 検索 / ページネーション / 日付付き作成 / 画像添付→所有者閲覧(200) | guest→/login誘導 / 誤パスワード / メール重複 / 確認不一致 / 短PW / 空title(確認へ進まず) / 終了日<開始日 / 確認画面キャンセルで未作成 / 他人タスクedit・image 404 |
| laminas E2E（**Playwright**・実ブラウザ・実MySQL） | 登録→自動ログイン→一覧 / ログアウト→再ログイン / 作成 / 編集 / 複製(「（コピー）」) / **完了トグル（取り消し線→戻す）** / 削除 / 検索 / ページネーション | guest→/login誘導 / 誤パスワード / username重複 / 短PW / 空title / 検索ヒットなし / 他人タスクは編集画面に入れず一覧へ / 他人タスクの削除・**トグル**は無効 |
| laminas CSRF E2E（**Playwright**・実ブラウザ・実セッション） | 全 POST フォームにトークンが埋まっている | 認証済みセッションでも GET の削除・ログアウトは 405 / トークン無し・不正トークンの POST は 403 で状態が変わらない |
| laminas セッション E2E（**Playwright**・実ブラウザ・実セッション） | ログイン前後・登録前後で `PHPSESSID` が変わる / Cookie が HttpOnly・SameSite=Lax | 攻撃者が仕込んだ ID は被害者ログイン後に使えない（セッション固定の再現）/ ログアウト後は旧 ID で認証状態に戻れない / 未知の ID を仕込んでも採用されない（`use_strict_mode`） |
| 3アプリ CSP E2E（**Playwright**・実ブラウザ） | ヘッダーが付く / Tailwind が適用される / nonce 付きインライン script が実行され flatpickr が初期化される / CSP 違反 0 件 | nonce 無しで注入したインライン script が実行されない（違反として記録される） |
| api E2E（**Playwright**・HTTP/Bearer・実MySQL） | register(201) / login(200) / logout(204→401) / CRUD / 複製(201) / 日付Y-m-d / per_pageクランプ / 検索 / 画像image_url→所有者取得(200) | 誤PW422 / メール重複422 / 短PW422 / token無し401 / 無効token401 / title欠落422 / title長すぎ422 / 終了日<開始日422 / **部分更新で片側だけ送っても保存済みの相手側と矛盾すれば422** / 他人タスクview・update・delete・duplicate・image 404 |

> **CSRF の IT** は実セッション（`session_start`）に依存させず、`AbstractIntegrationTestCase` が配列ストレージのセッションコンテナを注入した `CsrfGuard` を ServiceManager へ差し替える。**検証ロジック自体は本物をそのまま通す**（モックしない）。テストからは `withCsrf()` で正規トークンを付けた POST を送る。
>
> **失敗注入テスト**（`TaskImageIntegrityTest`）は、ファイルと DB という 2 つの保存先の失敗境界を検証する。DB 失敗は Eloquent のモデルイベント（`Task::creating` 等）で例外を投げて注入し（モックではなく実フック）、ファイル失敗は `Storage` ファサードを差し替えて注入する（`.claude/rules/testing.md` が許可する外部 I/O のモック）。守る不変条件と操作順序は `docs/05`。
>
> laminas の認証フロー（register→login→logout）は、`AuthControllerIntegrationTest` で **IT として PHPUnit 化済み**（コントローラ→InputFilter→UserTable→DB→bcrypt 照合を通しで検証）。**実セッションの永続**（Cookie を跨いだ保護ページ維持）は **E2E（Playwright）が実ブラウザでカバー**（ログアウト→再ログイン等）。認証ロジックの核（bcrypt）は `PasswordHasherTest` でも単体保証する。
> **認可（所有者スコープ）**は 2 段で担保する: SQL レベルは `TaskTableTest`（単体）、コントローラを通した横断フローは `TaskControllerIntegrationTest`（IT）で、他人タスクの編集・削除・複製が弾かれることを実データで検証する。

## 実行方法

```bash
make test          # 3アプリ一括
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas

make coverage      # 3アプリのカバレッジ計測 + 下限判定（CI と同じスクリプトを使う）
```

E2E（Playwright・実環境に対して実行）:

```bash
make setup                # 実環境を起動（.env・vendor・APP_KEY・migrate まで一括・冪等）
make e2e                  # e2e/ で npm ci → chromium 導入 → playwright test（guard + 3 projects）
# 個別実行例: cd e2e && npx playwright test --project=api
# 接続先ガードのみ: cd e2e && npx playwright test --project=guard（アプリの起動は不要）
```

## カバレッジ目標

学習用のためサンプル機能（Task CRUD）を最低限カバーする。新機能追加時も上のケース表へ追記し、**異常系（準正常系含む）が正常系を上回る**配分を維持する（比率の数値目標は置かない。`.claude/rules/testing.md` 準拠）。

### 計測と下限

| 項目 | 内容 |
| --- | --- |
| 計測ドライバ | **pcov**（手元は `docker/php/Dockerfile` で導入、CI は `setup-php` の `coverage: pcov`）。Xdebug ではなく計測専用で軽い pcov を使う |
| 計測対象 | 各 `phpunit.xml` の `<source>`（Laravel = `app/`、Laminas = `module/*/src`）。テストコード自体は対象外 |
| 下限 | **行カバレッジ 90%**。判定は `scripts/coverage-threshold.php` が Clover を読んで行う |
| 実行 | 手元 = `make coverage` / CI = `test` ジョブ（3 アプリの matrix で並行） |

- **下限値は `scripts/coverage-threshold.php` の 1 箇所にだけ置く。** 手元（`Makefile`）と CI（`ci.yml`）の双方が同じスクリプトを呼ぶため、数値を書き写して「手元と CI で違う基準を見ている」状態にならない（actionlint のバージョン固定と同じ考え方）。
- **PHPUnit には fail-under 相当の機能がない。** Laravel の `test --coverage --min=` は使えるが laminas に同等の手段が無く、アプリごとに基準や計算方法が変わるのを避けて自前スクリプトへ統一している。
- **レポートが無い場合も失敗させる。** 計測ドライバが入っていないと PHPUnit は計測を黙ってスキップするため、「検査できなかった」を合格として扱うと、計測が壊れた日から**ずっと緑**になる（`.claude/rules/testing.md`「ガード自体をテストする」）。
- 下限を下回ったらテストを追加する。下限そのものを下げる場合は、スクリプトを変更したうえで**理由を PR に書く**（静的解析の baseline と同じ扱い）。

計測時点の実測値（`docs/11` の進捗メモが件数の正本、ここは下限を決めた根拠として残す）:

| アプリ | 行カバレッジ |
| --- | --- |
| laravel-fullstack | 94.57% (261/276) |
| laravel-api | 98.05% (151/154) |
| laminas | 95.66% (419/438) |

### 未カバー行はデッドコードの候補として読む

`.claude/rules/dead-code.md` のとおり、カバレッジ 0% の行は「使われていないコード」の手がかりになる。`make coverage` の出力（Laravel はファイル別、Laminas はクラス別）を、機能追加時だけでなく**棚卸しの入口**としても使う。

- ただし**フレームワーク規約で呼ばれるコードは 0% に見える**（Eloquent のリレーションメソッド、`*Factory` 経由で生成されるクラス、PHTML から呼ばれるビューヘルパー等）。同ルールの「例外」と突き合わせてから判断する。
- 実例: 両 Laravel の `Task::user()` リレーションはどこからも呼ばれていない（所有者スコープを `$user->tasks()` 側から張っているため）。これは上記の例外に当たるため削除せず、3 アプリの読み比べ用に残す（issue #69 に記録）。
