---
description: GitHub Actions の発火ルール — 何を変更したときに何を動かすか
globs: ".github/workflows/**"
---

# GitHub Actions の発火ルール

**「変更した内容に関係のあるジョブだけを動かす」** を原則とする。ドキュメントやルールの更新でテスト・ビルド・デプロイを回さない（CI 時間・コストの浪費、キュー待ちによる他 PR のブロック、無意味なデプロイの発生を防ぐ）。

## トリガの基本形

| ワークフロー | トリガ | 補足 |
|---|---|---|
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
|---|---|---|---|---|
| `apps/**`（アプリケーションコード） | ✅ | ✅ | ✅（main マージ時） | — |
| テストコード（`apps/**/tests/`） | ✅ | ❌ | ❌ | — |
| `e2e/**` | ❌ | ✅ | ❌ | — |
| `compose.yaml`、`docker/**` | ❌ | ✅ | ✅ | — |
| `docs/**`、`*.md`、`README.md` | ❌ | ❌ | ❌ | markdown lint、リンク切れチェック |
| `.claude/**`（rules / skills） | ❌ | ❌ | ❌ | markdown lint |
| `.github/workflows/**` | ✅（自身の検証のため） | ✅ | ❌ | actionlint |
| 依存関係（`composer.lock` / `package-lock.json`） | ✅ | ✅ | ✅ | — |

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
