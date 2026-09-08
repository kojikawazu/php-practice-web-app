# 06. セキュリティ仕様書（Security Specification）

認証・認可・データ保護など、セキュリティ要件を定義する。

## 認証

| アプリ | 方式 | 実装 |
| -------- | ------ | ------ |
| laravel-fullstack | セッション認証 | 自前 `AuthController`（`Auth::attempt` / `Auth::login`）、`auth`/`guest` ミドルウェア |
| laravel-api | トークン認証 | Laravel Sanctum（personal access token、`auth:sanctum`） |
| laminas | セッション認証 | `laminas-authentication`（identity を Session Storage に保持）+ bcrypt 照合 |

- パスワードは全アプリ bcrypt でハッシュ化（Laravel は `'password' => 'hashed'` キャスト、Laminas は `PasswordHasher`）。
- 登録時のパスワードは 8 文字以上を必須とする。

## 認可

- タスクは作成ユーザーに紐付き（`tasks.user_id`）、**ログインユーザー本人のタスクのみ**閲覧・更新・削除できる。
- 他人のタスクへの操作は存在を伏せて 404（Laravel）/ 一覧・削除対象から除外（Laminas）とする。
- ロールベースの権限制御は本サンプルの範囲外（学習課題として拡張可能）。

## 暗号化・データ保護

- パスワードは bcrypt でハッシュ化して保存（平文は保持しない）。
- API トークンは Sanctum がハッシュ化して保存し、平文は発行時のみ返却（一覧では非公開）。
- 通信の TLS 化・保存時暗号化は本番化時の対応事項（ローカル学習環境では未適用）。

## 脆弱性対策

- **XSS**: 出力エスケープ（Blade `{{ }}` / Laminas `escapeHtml`）。
- **CSRF**: セッション認証の 2 アプリ（fullstack / laminas）は全 POST フォームにトークンを必須とする（下記「CSRF 対策の方針」）。API は Cookie を使わない Bearer トークン認証のため対象外。
- **セッション固定攻撃**: セッション認証の 2 アプリは認証成功時にセッション ID を再生成し、未知の ID を採用しない（下記「セッション管理の方針」）。
- **SQL インジェクション**: Eloquent / Laminas TableGateway のバインドパラメータ経由でクエリを構築（生 SQL の文字列結合をしない）。
- **認可**: タスクは `user_id` でスコープし、他人のリソースは 404 / 対象外。
- **アップロード画像**: 公開ディレクトリ外（名前付きボリューム）に保存し、アプリ経由の所有者チェック付きルートでのみ配信（URL を知っても他人は閲覧不可）。`image`/`mimes`/`max:2048` で種別・サイズを検証。
- **CSP（Content-Security-Policy）**: 全レスポンスにヘッダーを付け、XSS が混入したときの被害をブラウザ側で抑える（下記「CSP の方針」）。
- **依存の脆弱性の検出**: 2 層で見る。片方だけでは今回の取りこぼし（issue #138）を防げない。
  - **Dependabot alerts**（リポジトリ設定・有効化済み）: 依存グラフ（`composer.lock` / `package-lock.json`）を常時照合し、**誰も依存を触っていなくても**新規アドバイザリを通知する。マージはブロックしない。
  - **CI の `composer audit`**（`lint` ジョブ・`deps == 'true'` のときのみ）: composer マニフェストが変わった PR で**マージをブロックする**。`.claude/rules/coding-standards.md` /  本書の「依存を更新したら `composer audit` を実行し、クリーンを維持する」を機械化したもの。`--abandoned=ignore` を付けているのは、abandoned パッケージ 4 件（laminas 側）があるだけで終了コードが 1 になり、ゲートとして機能しなくなるため。
  - 全 PR で `composer audit` を走らせない理由: **新しいアドバイザリが公開されただけで、依存を 1 行も触っていない PR が落ちる**ようになるため。その検出は alerts 側の役割とし、層を分けている。
- **SSRF 対策（URL プレビュー）**: ユーザー登録 URL をサーバーが取得する機能（`LinkPreviewService`）は次で防御する:
  - スキームを `http`/`https` に限定（`url:http,https` ＋ サービス側で再確認）
  - ホストを DNS 解決し、public IP 以外（private/loopback/link-local/予約：例 `127.0.0.1`・`10/8`・`192.168/16`・`169.254.169.254`）は拒否
  - 検証した IP に接続をピン留め（`CURLOPT_RESOLVE`）して DNS リバインディングを防止
  - リダイレクトは自動追従せず、各ホップを再検証
  - 接続/読み込みタイムアウトと、**受信そのものを打ち切る**本文サイズ上限（512KB。下記「本文サイズ上限の効かせ方」）
  - 取得 HTML はそのまま出さず `title`/`og:image` だけ抽出し、表示時にエスケープ（`og:image` は http(s) のみ採用）
  - セキュリティの核 `isPublicIp()` は単体テストで担保
  - **取得失敗は握りつぶさずログに残す**（下記「URL プレビュー失敗時のログ」）
- **シークレットの Git 混入**: CI の `secret-scan` ジョブが、`.env` 系・秘密鍵（`*.key` / `*.pem` / `id_rsa` 等）・証明書・サービスアカウント鍵が Git の追跡対象に入っていないかを**全 PR / push で検査**する（`docs/09`）。`.gitignore` は未追跡ファイルにしか効かず「混入させない」側しか担保できないのに対し、こちらは「**追跡された時点で落とす**」検出側にあたる。一度 push した秘匿ファイルは追跡除外しても履歴に残り、対処は鍵・トークンのローテーションしかない（不可逆）ため、混入前に止めることに価値がある。**ファイル名のみの検査**で中身は読まないため、ソースコードへ直書きされた認証情報は検出できない（下記「既知の注意点」の DB 認証情報の平文はこれに該当し、本ジョブでは検出されない）。

### 本文サイズ上限の効かせ方

プレビュー取得は本文を 512KB (`MAX_BYTES`) までしか受信しない。**上限は「解析に使う長さを切る」ことではなく「それ以上受信しない」ことで担保する。**

**実体は `sink`（応答本文の書き込み先）に渡す `SizeCappedSink`。** cURL は書き込み関数が渡されたバイト数より少ない値を返すと**転送そのものを中断する**（`CURLE_WRITE_ERROR`）。上限に達したら短い値を返すことで、残りを受信せずに打ち切る。

- **`Content-Length` を上限判定の根拠にしない。** あの値は取得先が自己申告するもので、偽った値を送るだけで迂回できる。上限は「受け取る側が受け取るのをやめる」ことでのみ成立し、この方式なら `Content-Length` を持たない chunked 応答でも同じ理屈で守れる。
- **`stream => true`（本文の遅延受信）は使わない。** このオプションを付けると Guzzle はリクエストを cURL ではなく `StreamHandler`（PHP のストリームラッパー）へ回すため（`GuzzleHttp\Handler\Proxy::wrapStreaming()`）、**`CURLOPT_RESOLVE` による接続先 IP のピン留めが黙って無効になる**（上記 SSRF 対策の 3 点目が失われる）。サイズ上限のために SSRF 対策を落とすことはしない。
- **2 層で守る。** `SizeCappedSink` が「転送を止める」層、`LinkPreviewService::readCapped()` が「解析に使う長さを保証する」層。後者は sink を通らない経路（テストのフェイク等）でも成立する。
- 上限を超えた場合は**切り詰めて解析を続ける**（失敗にしない）。`title` / `og:image` は `<head>` にあるため先頭 512KB で足りるのが通常であり、巨大なページでもプレビューが付く方がユーザーには有益なため。切り詰めが起きたことは `info` で記録し、「プレビューの内容がおかしい」と言われたときに切り詰めを疑えるようにする。

> リダイレクト応答の本文は読まない（`Location` を検証して次のホップへ進むだけ）。**本文を読むのはリダイレクトでない最終応答 1 つだけ**なので、リダイレクトを最大 3 ホップ（`MAX_REDIRECTS`）追従しても、1 回のプレビュー取得で受信する本文は 512KB を超えない。

**検証の限界**: 「実際に転送が止まること」は自動テストで確認できない。`Http::fake` はハンドラ（cURL）を通らず、`sink` も Laravel がエミュレートする（`PendingRequest::sinkStubHandler` はスタブ本文を全量読んでから書き出す）。実サーバーを立てて転送量を測る検証は、SSRF ガードが `localhost` を拒否するため本アプリでは行えない。そのため**テストで固定するのは `SizeCappedSink` の契約**（上限に達したら短い値を返す）とし、その契約が cURL に転送を中断させるという前提は本ドキュメントに記録して担保する。

### URL プレビュー失敗時のログ

プレビュー取得の失敗はユーザー操作を止めない（`null` を返して継続する）。ただし**ログにも残さないと、壊れたこと自体に誰も気づけない**。`.claude/rules/error-handling.md` の「安全に失敗させる」と「スタックトレースを含めてログ出力する」は両立させる。

| 失敗 | `reason` | 併記する情報 |
| --- | --- | --- |
| 通信エラー（タイムアウト・接続失敗など） | `request_failed` | 例外オブジェクト（スタックトレース付き） |
| 2xx 以外の応答 | `http_error` | HTTP ステータス |
| リダイレクトなのに `Location` が無い | `redirect_without_location` | HTTP ステータス |
| リダイレクト上限超過 | `too_many_redirects` | 到達した深さ |

- **level は warning に揃える**。「プレビューが付かなかった」という 1 つの事象を 1 回の検索で拾えるようにし、原因は `reason` で判別する。
- **URL 全体は記録せず、ホストのみ**を記録する。ユーザー入力の URL には認証情報（`user:pass@`）やクエリ文字列中のトークンが含まれ得るため（`.claude/rules/error-handling.md`「センシティブ情報はログに含めない」）。原因の切り分けにはホストと `reason` で足りる。
  - 通信エラー時に記録する例外メッセージには要求 URI が含まれる（`cURL error ... for <URI>`）が、**Guzzle 側でパスワードが `***` に伏せられ、クエリ文字列も除去された形**になる（`CurlFactory::sanitizeCurlError()` / `Psr7\Utils::redactUserInfo()`。例: `http://alice:s3cret@example.com/p?token=xxx` → `http://alice:***@example.com/p`）。残るのはユーザー名とパスで、いずれも `tasks.url` として DB に平文保存しているのと同じユーザー入力である。**この伏せ字は Guzzle の実装に依存する**ため、依存を更新したときは挙動が変わっていないか確認する。
- **捕捉するのは `\Exception` までとし、`\Error` は伝播させる**。`\Throwable` を捕まえると `TypeError` などの実装バグまで「プレビューが付かないだけ」に化けて、ログにも残らず発見が遅れる。SSRF 対策の `BlockedUrlException` は `try` の外で送出され、`TaskController` が 422 に変換する（この catch の役割は通信起因の失敗に限定する）。

## CSRF 対策の方針

セッション認証は Cookie が自動送信されるため、外部サイトが仕込んだリクエストでも認証が通ってしまう。2 段で防ぐ。

### 1. 状態を変える操作は POST に限定する

GET で状態が変わると、`<img src="http://host/tasks/delete/1">` を含むページを開かせるだけで操作が成立する（トークン以前の問題）。

| アプリ | ログアウト | 複製 | 削除 | 確認画面のキャンセル |
| --- | --- | --- | --- | --- |
| laravel-fullstack | POST | POST | DELETE | POST |
| laminas | POST | POST | POST | —（確認画面なし） |

laminas は POST 以外を **405 Method Not Allowed**（`Allow: POST` 付き）で拒否する。実装は `Application\Controller\RequiresPostTrait`。

### 2. 全 POST に CSRF トークンを要求する

| アプリ | 発行 | 検証 |
| --- | --- | --- |
| laravel-fullstack | Blade の `@csrf` | `VerifyCsrfToken` ミドルウェア（フレームワーク標準・419） |
| laminas | ビューヘルパー `$this->csrfInput()` | `Module::onBootstrap` の `MvcEvent::EVENT_ROUTE` リスナー（**403**） |

**laminas は個々のアクションで検証しない。** 各アクションに書く形にすると、新しい POST を足したときの書き忘れがそのまま無防備になるため、ルーティング後に全 POST を一括で検証する（`security.md`「ミドルウェアを付け忘れたら公開になる実装にしない」）。

### トークンの実装（laminas）

`Application\Service\CsrfGuard` が同期トークンパターンを持つ。

- 生成: `bin2hex(random_bytes(32))`（256 bit・CSPRNG）
- 保存: laminas-session のコンテナ（認証の identity と同じセッション）
- 比較: `hash_equals()` による定数時間比較
- 寿命: セッション内で不変（毎回変えると複数タブ・ブラウザバックで壊れる）。Laravel の `_token` と同じ方針

laminas-validator / laminas-session の `Csrf` バリデータは使わない。**双方とも 3.0 で削除予定の非推奨 API**（`getHash()` を含む）で、抑制コメントを重ねることになるため。実体は「乱数 + 定数時間比較」だけなので自前で持つ。

### 拒否時の応答

| 状況 | 応答 |
| --- | --- |
| 状態変更を GET で叩く | 405 Method Not Allowed |
| CSRF トークンが無い / 不正 | 403 Forbidden |

**リダイレクトで隠さない。** 他人のリソースを 404 で隠すのは「存在を教えない」ためだが、CSRF の拒否は逆に**攻撃が失敗したことを明示的に記録**したい。リダイレクトだと成功と区別できず、テストでも運用ログでも検知できない。

Laravel が CSRF 不一致に 419（フレームワーク独自）を返すのに対し、laminas は標準的な 403 を使う。

## セッション管理の方針

セッション認証は「Cookie の値を知っている人＝本人」として扱う。ID を攻撃者に握られた時点で認証が意味を失うため、**ID のライフサイクル**を明示的に管理する。対象は fullstack / laminas の 2 アプリ（api は Cookie を使わない Bearer トークン認証のため対象外）。

### セッション固定攻撃（Session Fixation）への対策

攻撃者があらかじめ用意した ID を被害者のブラウザに持たせ、被害者がその ID のままログインすると、同じ ID で認証済みセッションに相乗りできる。**仕込ませない**対策と**仕込まれた後に無効化する**対策の 2 段で塞ぐ。

| 段 | 対策 | 実装 |
| --- | --- | --- |
| 1. 仕込ませない | 未知のセッション ID を採用せず、常にサーバーが発行した ID だけを使う | `session.use_strict_mode = 1` |
| 2. 無効化する | 認証に成功した瞬間に ID を振り直し、旧 ID のセッションを削除する | 下表「再生成のタイミング」 |

### 再生成のタイミング

| 契機 | 動作 |
| --- | --- |
| ログイン成功 | ID を再生成（データは引き継ぐ）→ その後に identity を書き込む |
| 登録＝自動ログイン | 同上（登録直後の自動ログインも「認証成功」として扱う） |
| ログアウト | セッションの中身を破棄し、ID も作り直す（CSRF トークンも失効する） |

**identity を書き込む前に再生成する。** この順序なら「認証済み状態は、必ず新しい ID の下でだけ存在する」と言い切れる。再生成では旧 ID のデータを削除し（`deleteOldSession = true` 相当）、仕込まれた ID が生き残らないようにする。

### アプリごとの実装

| アプリ | 実装 |
| --- | --- |
| laravel-fullstack | フレームワーク任せ。`Auth::attempt()` / `Auth::login()` が内部で `SessionGuard::updateSession()` → `session()->migrate(true)` を実行する。ログアウトは `session()->invalidate()` + `session()->regenerateToken()` |
| laminas | `Application\Service\AuthSessionInterface`（本番実装 `AuthSession`）に集約し、`AuthController` から呼ぶ。実体は laminas-session の `SessionManager::regenerateId(true)` |

**laminas だけ自分で書く必要がある。** laminas-authentication は「認証」だけを担い、セッション管理は laminas-session の担当で、両者を繋ぐのはアプリの責務だから。フルスタックとマイクロフレームワークの責務境界の差がそのまま現れる箇所。

CSRF のように `Module::onBootstrap` のリスナーで一括処理はしない。「認証に成功した瞬間」は横断的に判定できずコントローラにしか分からないため、リスナー化しても判定を渡すだけの間接化になる。

### セッション Cookie の属性（laminas）

`module/Application/config/module.config.php` の `session_config` で指定する。

| 設定 | 値 | 理由 |
| --- | --- | --- |
| `use_strict_mode` | `true` | 未知の ID を採用しない（上記 1 段目） |
| `cookie_httponly` | `true` | JavaScript から読めなくし、XSS でのセッション持ち出しを防ぐ |
| `cookie_samesite` | `Lax` | 外部サイト起点のリクエストに Cookie を載せない（CSRF の多層防御） |
| `cookie_secure` | 未設定 | ローカルが HTTP のため。本番では有効化が必須（下記「既知の注意点」） |

`SessionManager` はコンテナ経由の単一インスタンスとし、CSRF トークン・identity・ID 再生成のすべてが同じセッションを見るようにする。既定の `Container::getDefaultManager()` に任せると、設定の効いていない暗黙のインスタンスを掴む。

### テストでの担保

ID が実際に変わることは ext/session の挙動なので、**E2E（`e2e/tests/laminas/session.spec.ts`）で担保する**。攻撃者が仕込んだ ID を被害者に持たせてログインさせ、攻撃者側が認証済みにならないことまで再現する。IT では「認証に成功した経路でだけ・identity を書く前に再生成が走る」ことを検証する（`docs/08`）。

## CSP の方針

XSS 対策の出力エスケープが 1 箇所漏れても即被害にならないよう、多層防御としてすべての HTML/JSON 応答に `Content-Security-Policy` を付与する。

### 適用箇所（アプリごとに実装層が違う）

| アプリ | 実装 | 理由 |
| --- | --- | --- |
| laravel-fullstack | `App\Http\Middleware\ContentSecurityPolicy`（グローバルミドルウェア） | リクエストごとに nonce を発行し `View::share` で Blade へ渡す |
| laravel-api | `App\Http\Middleware\ContentSecurityPolicy`（グローバルミドルウェア） | JSON のみのため nonce を持たない |
| laminas | `Application\Service\ContentSecurityPolicy` + `Module::onBootstrap` の `MvcEvent::EVENT_FINISH` リスナー | ミドルウェア層が無いため MVC ライフサイクル終端で付与する。nonce は ServiceManager の共有インスタンスで、ヘッダーと PHTML の値を一致させる |

**nginx では設定しない。** nonce はリクエストごとにアプリが生成して HTML へ埋め込む必要があり、nginx 側で同じ値を作れない。両方で設定するとヘッダーが重複し、ブラウザは全ポリシーの積を適用するため意図が読めなくなる。

### ポリシー

fullstack / laminas（HTML）:

| ディレクティブ | 値 | 意図 |
| --- | --- | --- |
| `default-src` | `'self'` | 既定は自ホストのみ |
| `script-src` | `'self' 'nonce-<リクエストごと>' https://cdn.tailwindcss.com https://cdn.jsdelivr.net` | インライン script は nonce でのみ許可。`'unsafe-inline'`・`'unsafe-eval'` は付けない |
| `style-src` | `'self' 'unsafe-inline' https://cdn.jsdelivr.net` | Tailwind Play CDN が実行時に `<style>` を注入するため（下記の妥協） |
| `img-src` | fullstack=`'self' data: https:` / laminas=`'self' data:` | fullstack のみ OGP プレビュー画像が任意の外部ホストから来る |
| `object-src` / `frame-ancestors` | `'none'` | プラグイン埋め込みとクリックジャッキングを禁止 |
| `base-uri` / `form-action` | `'self'` | ベース URL 書き換えと送信先すり替えを禁止 |

api（JSON・画像バイナリのみ）: `default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`。

フレームワーク雛形の `/`（Laravel 既定のランディングページ）もこの対象になるが、本アプリの成果物ではないため、そのページの見た目のために API のポリシーを緩めない。

### nonce の生成

- Laravel: `Str::random(24)`（英数字）
- Laminas: `bin2hex(random_bytes(16))`（16 進数）。base64 だと `+` `/` `=` を含み、PHTML の `escapeHtmlAttr()` が実体参照へ変換するため、生 HTML と値が一致しなくなる（ブラウザは復号するので動作はする）

### 検証

- ヘッダー内容: `CspHeaderTest`（Laravel ×2）/ `CspHeaderIntegrationTest`（laminas）。`script-src` に `'unsafe-inline'` が混入する退行を検出する（CSP は nonce/hash があると `'unsafe-inline'` を無視するため、混入しても静かに壊れる）
- 実挙動: `e2e/tests/*/csp.spec.ts` で、実ブラウザでの CSP 違反ゼロ・Tailwind 適用・flatpickr 初期化・nonce 無しインライン script が実行されないことを確認する

### 開発環境と本番の差

現状は HTTP（compose）で動かすため `upgrade-insecure-requests` や `Strict-Transport-Security` は設定していない。本番化時は HTTPS 必須化（本書「通信」）とあわせてこれらを追加する。また `report-uri` / `report-to` による違反レポート収集も未導入で、CSP の破れは E2E でのみ検出している。

## 既知の注意点（学習用の妥協）

- **Laravel の依存にセキュリティアドバイザリ（解消済み）**: 初期構築時は Laravel 11 系全バージョンが advisory（CVE-2026-48019: デフォルト email ルールの CRLF インジェクション）該当で、`--no-security-blocking` で暫定導入していた。本 CVE は Laravel 11 系に修正版が存在しない（修正は 12.60.0+ / 13.10.0+）ため、**Laravel 12.61.1 へアップグレードして解消**した。両 Laravel アプリで `composer audit` がクリーンであることを確認済み。今後も依存更新時は `composer audit` を実行すること。
- **guzzle 依存の CVE（解消済み）**: Larastan 導入（依存更新）時の `composer audit` で `guzzlehttp/guzzle`（CVE-2026-55767 / CVE-2026-55568）・`guzzlehttp/psr7`（CVE-2026-55766）が検出されたため、両 Laravel アプリで guzzle 7.14+ / psr7 2.12+ へ更新して解消した（`composer audit` クリーンを確認）。guzzle は `laravel/framework` の依存。
  - **2026-09-08 に再発（解消済み）**: 同じ `guzzlehttp/guzzle` で新たに 6 件（うち high 1: CVE-2026-69246 「Noncanonical host can bypass host-based checks」）、`league/commonmark` で 10 件（high 8 / medium 2）が検出された（issue #138）。guzzle 7.15.5 / psr7 2.13.1 / commonmark 2.10.1 へ更新して解消。**この high は上記「SSRF 対策」のホスト判定を迂回できる欠陥**であり、単なるバージョン遅れではなかった。再発の根本原因は「解消済み」と書いただけで**再発を検知する仕組みが無かった**こと（発見も laminas の依存追加のついでだった）。対策として上記「依存の脆弱性の検出」の 2 層を導入した。
- **phpcs の CVE（解消済み）**: laminas に `slevomat/coding-standard` を追加（issue #135）した際の `composer audit` で、**既存の** `squizlabs/php_codesniffer` 3.13.5 が CVE-2026-67434（OS コマンドインジェクション・high / 影響版 `<3.13.6`）に該当していたことが判明した。`^3.7` の制約内で 3.13.6 へ更新して解消（`composer audit` クリーンを確認）。**検出が依存追加のついでになった**のが問題で、3 アプリの `composer.json` は Dependabot に未登録のため（`docs/09` の「依存更新の追跡範囲」）、誰かが依存を触るまで気づけない構造になっている。
- **DB 認証情報の平文**: 学習用のため `.env` / Laminas `global.php` に開発用認証情報（app/secret）を記載。公開・本番では秘密情報をリポジトリ管理外（local.php・シークレットストア）へ移すこと。
- **CSRF / セッション**: Laravel web・Laminas はセッション認証（フォームは CSRF 前提）。API は Sanctum のステートレストークン。
- **セッション Cookie の `Secure` 属性が未設定**: ローカルは HTTP（compose）で動かすため `cookie_secure` を有効にしていない（有効にすると平文 HTTP では Cookie が送られず、ログインできなくなる）。本番化時は HTTPS 必須化（本書「通信」）とあわせて `cookie_secure = true` を設定すること。
- ~~API 認証なし~~（解消済み）: `laravel-api` に Sanctum トークン認証を導入済み。
- **CSP の `style-src` に `'unsafe-inline'` が必要**: Tailwind CSS（Play CDN）は実行時に `<style>` 要素を DOM へ注入するため、nonce が付かない。CSP は `style-src` に nonce/hash があると `'unsafe-inline'` を無視する仕様のため、両立できない（実ブラウザで確認済み: `style-src 'self'` のみだと画面が完全に無スタイルになる）。`script-src` は nonce のみで運用し `'unsafe-inline'` を付けていない。Tailwind を CLI/Vite でビルドして self-host すれば `style-src` からも外せる。
- **フロントを CDN 読み込み（SRI 未設定）**: 日付ピッカー flatpickr（jsDelivr 固定版 @4.6.13）と Tailwind CSS（Play CDN）をブラウザから読み込んでいる。学習用のため Subresource Integrity（`integrity`）は付けていない（Tailwind Play CDN は動的スクリプトのため SRI 非対応）。本番化時は flatpickr に SRI 付与、Tailwind は CLI/Vite でビルドして self-host することが望ましい。
