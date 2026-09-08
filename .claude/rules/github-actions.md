---
description: GitHub Actions のルール — ワークフローの静的解析（actionlint）と発火ルール
globs: ".github/workflows/**"
---

# GitHub Actions のルール

本ファイルは 2 本柱で構成する。

1. **ワークフローの静的解析**: ワークフロー自体の誤りを actionlint で機械的に潰す。
2. **発火ルール**: **「変更した内容に関係のあるジョブだけを動かす」**。ドキュメントやルールの更新でテスト・ビルド・デプロイを回さない（CI 時間・コストの浪費、キュー待ちによる他 PR のブロック、無意味なデプロイの発生を防ぐ）。

## ワークフローの静的解析（actionlint）

**ワークフローを追加・変更したら、[actionlint](https://github.com/rhysd/actionlint) による検証を CI で必須にする。** ワークフローの誤りは「push して実際に動かすまで気づけない」ため、CI 時間を溶かす前に機械で潰す。

検出できるもの:

| 検出内容 | 例 |
| --- | --- |
| ランナーラベルの誤り | `runs-on: ubuntu-lates`（typo）／未登録のセルフホストラベル |
| アクション入力名の誤り | `actions/checkout@v4` に `fetch-dept:`（正: `fetch-depth`） |
| 式・コンテキストの誤り | 存在しない `steps.<id>.outputs.*` の参照、型の不一致 |
| ジョブ依存の誤り | `needs:` が存在しないジョブ ID を指している |
| **スクリプトインジェクション** | `run: echo "${{ github.event.pull_request.title }}"` のように untrusted input を `run:` へ直接埋め込む（環境変数経由に直す） |
| シェルスクリプトの不備 | `run:` の中身（shellcheck 連携。クォート漏れ等） |
| cron 式・glob の誤り | `schedule` の cron 構文、`branches` のパターン |

**検出できないもの**（機械では判断できないため、レビューで見る）: ブランチ名・パスフィルタの内容が意図と合っているか、参照しているシークレットが実在するか、ジョブの実行順序が業務的に正しいか。

### 実行方法（手元と CI で同一コマンド）

**コマンドの定義は `Makefile` の `actionlint` ターゲット 1 箇所に置き、手元と CI の双方がそれを呼ぶ。**

```bash
make actionlint         # 手元（push する前に実行する）
```

```yaml
# ci.yml の actionlint ジョブ
- uses: actions/checkout@v4
- run: make actionlint
```

- **公式 Docker イメージ（`rhysd/actionlint`）をタグ固定で使う。** イメージには **actionlint 本体と shellcheck / pyflakes が同梱**されており、`run:` の中身まで必ず検査される。タグ固定で actionlint と shellcheck の**双方のバージョンが揃う**。
- **`brew install actionlint` / `go install` を使わない。** これらは **shellcheck を連れてこない**。shellcheck が PATH に無いと、`run:` の検査は**エラーにも警告にもならず、その層だけ静かにスキップ**され、終了コードは 0 のままになる。**検査が減ったことに気づけない**のが最悪で、レビューで最も見落とされる層（`run:` の中身）でそれが起きる。
- **バージョン文字列を 2 箇所に書き写さない。** `Makefile` と `ci.yml` の両方にタグを書くと、片方だけ上げても CI は緑のまま「手元と CI で違うものを検査している」状態になる。`ci.yml` から `make` を呼ぶことで**構造的に一致させる**（本リポジトリでは CI が `Makefile` を経由する唯一の lint ジョブ。そのぶん後述のパスフィルタに `Makefile` を含める）。
- **`actions/checkout` を先に置く。** actionlint は `.git` からリポジトリルートを判定するため、リポジトリ外で実行するとエラー終了する。
- **このジョブにシークレットを渡さず、`permissions: contents: read` に絞る。**
- バージョンを上げるときは `Makefile` を変更する（更新は依存更新として明示的に行う。`run:` 内のバージョンは Dependabot では更新されない）。

### 発火条件（本リポジトリの判断）

**`.github/workflows/**` または `Makefile`（コマンド定義）が変わったときだけ実行する。** 共通テンプレート（`my-custom-skills`）は「独立ワークフローで全 PR 常時実行」を推奨しており、本リポジトリはそれを採らない。理由:

- テンプレートのコスト論（「判定ジョブを新設するくらいなら常時実行のほうが安い」）は**判定ジョブを持たないプロジェクト向け**で、テンプレート自身が「**判定ジョブを既に持つプロジェクトでは出力を 1 行足すだけ**」と例外を明記している。本リポジトリは `changes` ジョブが他ジョブのために必ず走るため、フィルタの追加コストはゼロ。
- テンプレートが挙げるもう 1 つの理由「必須チェックにしたときパスフィルタ設計を誤ると PR がマージ不能になる」は、**ワークフローレベルの `paths` を使った場合**の事故である。本リポジトリは後述のとおり**ジョブレベル `if:`（skipped = 成功扱い）**で実装しており、この事故は起きない。
- 独立ワークフローにすると `concurrency` グループが 2 つに分かれ、将来の集約ゲート（必須チェック）設計も二重になる。

### 抑制と設定

抑制の作法は「**理由を書く・範囲を最小にする・増えたら設定自体を見直す**」（[static-analysis.md](./static-analysis.md)）に従う。actionlint 固有の手段は以下:

| 目的 | 手段 |
| --- | --- |
| セルフホストランナーのラベルを認識させる | `.github/actionlint.yaml` の `self-hosted-runner.labels` に登録する（`actionlint -init-config` で雛形を生成できる） |
| 特定のエラーメッセージを無視する | `-ignore <正規表現>`（繰り返し指定可）／`.github/actionlint.yaml` の `paths.<glob>.ignore` |
| shellcheck の特定ルールを無視する | 該当箇所の直前に `# shellcheck disable=SC2086` を書く（`run:` 内の対象行のみ） |

- **リポジトリ単位・ワークフロー単位での一括無効化をしない。** 無視するなら対象を絞り、設定ファイルに理由をコメントで残す。
- 設定ファイル（`.github/actionlint.yaml`）は**コミット対象**。ローカル固有設定に依存しない（本リポジトリは現在この設定ファイルを持たない。追加した場合も `changes` ジョブの `workflows` フィルタが拾う）。

## トリガの基本形

| ワークフロー | トリガ | 補足 |
| --- | --- | --- |
| CI（lint / test / e2e） | `pull_request`（対象: `main`）+ `push`（`main` のみ） | **全ブランチの push で回さない**。PR で回れば十分 |
| CD（デプロイ） | `push`（`main` のみ）または `release` | PR では動かさない |
| 手動運用（再デプロイ・ロールバック） | `workflow_dispatch` | 手動実行の口を必ず用意する |

- **`concurrency` を必ず設定する**。同一 PR で連続 push した際に古い実行をキャンセルする。

  ```yaml
  concurrency:
    group: ${{ github.workflow }}-${{ github.ref }}
    cancel-in-progress: true   # CD（デプロイ）では false にする（中断で不整合が起きるため）
  ```

- **`permissions` は最小権限**を明示する（既定の広い権限に依存しない）。読み取りだけなら `contents: read`。`dorny/paths-filter` を `pull_request` で使う場合は `pull-requests: read` も必要。

## 変更内容と実行対象

| 変更内容 | lint / test | e2e | デプロイ | 実行する軽量チェック |
| --- | --- | --- | --- | --- |
| `apps/**`（アプリケーションコード） | ✅ | ✅ | ✅（main マージ時） | — |
| テストコード（`apps/**/tests/`） | ✅ | ❌ | ❌ | — |
| `e2e/**` | ❌ | ✅ | ❌ | — |
| `compose.yaml`、`docker/**` | ❌ | ✅ | ✅ | — |
| `docs/**`、`*.md`、`README.md` | ❌ | ❌ | ❌ | markdown lint、リンク切れチェック |
| `.claude/**`（rules / skills） | ❌ | ❌ | ❌ | markdown lint |
| `.github/workflows/**` | ✅（自身の検証のため） | ✅ | ❌ | actionlint |
| 依存関係（`composer.lock` / `package-lock.json`） | ✅ | ✅ | ✅ | `composer audit`（composer マニフェスト変更時のみ） |
| `Makefile`（actionlint のコマンド定義） | ❌ | ❌ | ❌ | actionlint |

- **ドキュメント変更でも「何も動かさない」にはしない**。markdown lint・リンク切れ・必須ファイル（README.md / CLAUDE.md）の存在検証は軽量なので実行する。

## パスフィルタの実装（重要な落とし穴）

**ワークフローレベルの `paths` / `paths-ignore` を、required status check（ブランチ保護の必須チェック）と併用してはならない。**

- ワークフロー自体が起動しないと、必須チェックは **`pending` のまま永久に完了せず、PR がマージできなくなる**。
- 一方、**ジョブレベルの `if:` でスキップした場合は「skipped」となり、必須チェックとしては成功扱い**になる。

したがって、**必須チェックにするジョブは「常に起動し、中身をスキップする」形にする**。

```yaml
on:
  pull_request:
    branches: [main]

jobs:
  changes:                      # 変更範囲を判定する
    runs-on: ubuntu-latest
    outputs:
      code: ${{ steps.code.outputs.code }}
      docs: ${{ steps.docs.outputs.docs }}
    steps:
      - uses: actions/checkout@v4

      # 除外リスト（AND 評価）: ドキュメントだけの変更なら code=false になる
      - uses: dorny/paths-filter@v3
        id: code
        with:
          predicate-quantifier: every
          filters: |
            code:
              - '!docs/**'
              - '!**/*.md'
              - '!.claude/**'

      # 肯定リスト（既定の OR 評価）は別ステップに分ける
      - uses: dorny/paths-filter@v3
        id: docs
        with:
          filters: |
            docs:
              - '**/*.md'

  test:                         # 必須チェック。常に起動し、中身だけスキップする
    needs: changes
    if: needs.changes.outputs.code == 'true'
    runs-on: ubuntu-latest
    steps:
      - run: echo "run tests"
```

- 必須チェックにしないワークフロー（デプロイ等）は、ワークフローレベルの `paths-ignore` を使ってよい（起動そのものを止める方が安価）。
- **判定条件は「除外リスト」で書く**（`docs/**` 以外はアプリ変更とみなす）。「対象リスト」で書くと、**新しいディレクトリが増えたときに黙ってテストが走らなくなる**。安全側に倒す。

### 新しい設定ファイルを追加したときの見直し（必須）

**リポジトリに新しい設定ファイル・マニフェストを追加したら、同じ PR で `changes` ジョブのフィルタを更新する。** 除外リスト方式では、新しいファイルは定義上どの除外にも当たらないため**必ず「コード変更」と判定される**。fail-safe としては正しいが、**同時に肯定リスト（`docs` / `workflows`）にも入っていない**と、そのファイルを読む唯一のジョブが起動しない。結果として「無関係な重いジョブが走り、必要な軽量チェックは走らない」逆転が起きる。

- 除外リスト（`test` / `lint` / `e2e`）と肯定リスト（`docs` / `workflows`）の**両方**を見る。
- 実例: ルート `package.json` / `package-lock.json`（markdown lint 専用）、`.github/PULL_REQUEST_TEMPLATE/.markdownlint.jsonc`（入れ子の lint 設定）。いずれも追加時に取りこぼしかけた（`docs/lessons-learned.md` 2026-09-02）。

### 除外リストは `predicate-quantifier: every` が必須（落とし穴）

`dorny/paths-filter` は**パターンごとに picomatch を評価し、既定では結果を OR（`some`）で束ねる**。そのため否定パターンを並べただけでは**互いを打ち消して常に true になり、除外が一切効かない**。

- `'**'` + `'!docs/**'` のような「全部 + 除外」も**効かない**（`'**'` が先に真になる）。
- `predicate-quantifier: every`（AND 評価）にして**否定パターンのみを並べる**。「どの除外にも当たらない = コード変更」という意味になる。
- `every` はステップ単位の入力なので、**肯定形のフィルタ（`docs: '**/*.md'` 等）は別ステップに分ける**（`every` だと全パターン一致を要求して壊れる）。
- 除外リストを変更したら、**判定を実際に検証する**（対象ファイル名を並べて期待値と突き合わせる）。フィルタの誤りは「テストが黙ってスキップされる」形で現れ、CI が緑のまま見逃される。

## デプロイの発火

- **デプロイは `main` へのマージを唯一のトリガとする**。PR ブランチから本番へデプロイしない。
- **Environments（`environment:`）を使い、本番は承認ゲートを置く**。シークレットは Environment 単位で管理し、PR からは参照できないようにする。
- **fork からの PR で `pull_request_target` を安易に使わない**。`pull_request_target` は base リポジトリの権限とシークレットで動くため、fork のコードをチェックアウトして実行するとシークレットが漏洩する。
- デプロイ workflow には `concurrency.cancel-in-progress: false` を設定し、**デプロイ途中でのキャンセルによる不整合を防ぐ**。

## レビュー観点

- ドキュメント・ルールのみの PR で、テストやデプロイが起動していないか。
- 逆に、**アプリコードを変更したのに必要なジョブがスキップされていないか**（パスフィルタの書き漏れ）。
- 必須チェックにしているジョブが、ワークフローレベルの `paths` / `paths-ignore` で止められていないか（PR がマージ不能になる）。
- `permissions` が明示され、最小権限になっているか。
- **untrusted input（`github.event.*` のタイトル・ブランチ名等）を `run:` へ直接埋め込んでいないか**（環境変数経由にする）。actionlint も検出するが、抑制で通していないかを見る。
- actionlint の抑制（`-ignore` / `.github/actionlint.yaml` / `# shellcheck disable=`）に**理由が書かれ、範囲が最小になっているか**。
