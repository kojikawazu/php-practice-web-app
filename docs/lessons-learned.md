# 教訓ログ（Lessons Learned）

誤り・失敗・ハマりから得た教訓を蓄積する。**追記のみ・新しいものを上**。記録の運用（トリガー・フォーマット・ルールへの昇格）は [`.claude/rules/lessons-learned.md`](../.claude/rules/lessons-learned.md) を正本とする。

## 2026-09-02 ルート直下に置いた設定ファイルが、CI で「コード変更」と判定される

### 概要

CI の `changes` ジョブは除外リスト方式（どの除外にも当たらない = コード変更）で fail-safe に倒してある。そのため**新しく追加した設定ファイルは、放っておくと必ず「コード変更」側に落ちる**。ツール設定を足した PR で PHP のテストが全部走り、**本来走るべき軽量チェックだけが走らない**という逆転が起きる。

### 詳細

- 何が起きたか: 同じ日に 2 回踏んだ。1 回目は `markdownlint-cli2` を devDependency 化した際のルート `package.json` / `package-lock.json`（issue #114）、2 回目は PR テンプレート用の入れ子設定 `.github/PULL_REQUEST_TEMPLATE/.markdownlint.jsonc`（issue #103）。どちらも実装直後のセルフレビューで気づいたため main には入っていない。
- なぜ起きたか（根本原因）: 除外リストは「既知の無関係ファイル」を列挙する形なので、**新しいファイルは定義上どれにも当たらない**。fail-safe としては正しい挙動（未知のものはテストを走らせる）だが、**同時に肯定リスト（`docs`）にも入っていない**ため、そのファイルを読む唯一のジョブ（`markdown-lint`）が起動しない。「安全側に倒れている」ように見えて、**検査が 1 つ欠落する**のがこの構造の盲点。
- 教訓 / 次からどうする: **リポジトリに新しい設定ファイル・マニフェストを追加したら、同じ PR で `changes` ジョブのフィルタを更新する。** 除外リスト（`test` / `lint` / `e2e`）と肯定リスト（`docs` / `workflows`）の**両方**を見る。判定は picomatch で実際に突き合わせて検証する（フィルタの誤りは「CI が緑のまま検査が減る」形でしか現れない）。
- 関連: [`.claude/rules/github-actions.md`](../.claude/rules/github-actions.md)（2 回起きたためルールへ昇格した）、issue #114 / PR #117、issue #103 / PR #121

## 2026-09-02 `new URL('localhost:8001')` は例外を投げず、ホスト名が空文字で通る

### 概要

E2E の接続先 allowlist ガードを実装した際、スキームを省いた URL が `new URL()` で**例外にならずに解釈され**、`hostname` が空文字のまま検査に流れた。ガード自体は fail-closed で止まったが、失敗メッセージが「許可されていないホスト: （空）」となり原因が読めなかった。

### 詳細

- 何が起きたか: `resolveUrl('E2E_FS_URL', 'localhost:8001', ...)` が「URL として解釈できない」ではなく「許可されていないホスト」で落ちた。ガードのテストが期待メッセージ不一致で失敗して発覚した。
- なぜ起きたか（根本原因）: `new URL('localhost:8001')` は **`protocol: 'localhost:'` / `hostname: ''` として成功する**（`localhost:` をスキームと解釈する）。URL の妥当性を「`new URL()` が例外を投げるか」だけで判定すると、この形が漏れる。
- 教訓 / 次からどうする: **URL の検証はスキームを先に検査する**（`http:` / `https:` のみ許可）。`new URL()` の成否だけを妥当性の判定に使わない。あわせて、**ガードは「止まること」だけでなく「メッセージが原因を指すこと」までテストする**（止まっても原因が読めなければ、次の人は環境変数を手で書き換えて回避しようとする）。
- 関連: [`.claude/rules/testing.md`](../.claude/rules/testing.md)「テスト対象・テスト DB の接続先（破壊防止）」、`e2e/helpers/config.ts`、issue #105 / PR #118

## 2026-08-23 `session_config` を足すと `session_storage` も必須になり、全リクエストが 500 になる

### 概要

laminas でセッション ID 再生成（セッション固定攻撃対策）を入れる際、`session_config` を追加したところ、`SessionManager` の生成が例外で失敗し、1 画面ではなく**全リクエスト**が 500 になった。

### 詳細

- 何が起きたか: `module.config.php` に `session_config` を追加した直後、アプリ全体が 500。`Module::onBootstrap` の CSRF リスナーが `SessionManager` を解決するため、**セッションを使わない画面まで巻き込まれる**。
- なぜ起きたか（根本原因）: `SessionManagerFactory` は `session_config` を見つけると、対になる **`session_storage` も必須で要求する**（欠けると `ServiceNotCreatedException`）。「設定を 1 つ足しただけ」に見えて、**ファクトリが要求する設定キーの組が変わる**のが原因。
- 教訓 / 次からどうする: **Laminas でコンテナ経由の設定キーを足すときは、そのファクトリが連鎖して要求する他のキーを先に確認する**（`SessionManagerFactory` なら `session_config` + `session_storage` の対）。ブートストラップで解決されるサービスは影響範囲が全リクエストに及ぶため、追加後は必ず 1 画面ではなく**トップページと認証不要画面の両方**で疎通を確認する。
- 関連: `apps/laminas/module/Application/config/module.config.php`（理由をコメントで記録済み）、[`.claude/rules/php.md`](../.claude/rules/php.md)「DI」、issue #85 / PR #101

## 2026-07-25 `dorny/paths-filter` の否定パターンは、既定の OR 評価で互いを打ち消す

### 概要

CI のパスフィルタを「除外リスト方式」で書いたが、否定パターンを並べただけでは**除外が一切効かず、常に true**になっていた。「ドキュメントだけの変更でテストが走らない」はずが、全部走っていた。

### 詳細

- 何が起きたか: `- '!docs/**'` `- '!**/*.md'` のように否定パターンを並べたフィルタが、md だけの変更でも `true` を返した。`'**'` + `'!docs/**'`（全部 + 除外）の形も同様に効かない。
- なぜ起きたか（根本原因）: `dorny/paths-filter` は**パターンごとに picomatch を評価し、既定では結果を OR（`some`）で束ねる**。`docs/x.md` は `'!docs/**'` には一致しないが `'!**/*.md'`… のような別の否定パターンには一致するため、**どれか 1 つでも真になれば全体が真**になる。否定を並べるほど真になりやすい。
- 教訓 / 次からどうする: **除外リストを書くときは `predicate-quantifier: every`（AND 評価）を指定し、否定パターンだけを並べる**（「どの除外にも当たらない = コード変更」の意味になる）。肯定形のフィルタは `every` だと壊れるので**別ステップに分ける**。そして**フィルタを変更したら判定を実測する**（対象ファイル名を並べて期待値と突き合わせる）。誤りは「テストが黙ってスキップされる／無関係なジョブが走る」形でしか現れず、CI は緑のままになる。
- 関連: [`.claude/rules/github-actions.md`](../.claude/rules/github-actions.md)「パスフィルタの実装（重要な落とし穴）」、`docs/09-architecture-specification.md`、PR #65
