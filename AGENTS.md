# Codex instructions

このリポジトリでは `.claude/rules/` が開発ルールの唯一の正本です。作業を始める前に、変更対象に応じて次のルールを読み、守ってください。ルール本文をこのファイルへ複製しないでください。

## 常に適用するルール

- `.claude/rules/shortcuts.md`
- `.claude/rules/workflow.md`
- `.claude/rules/quality-gate.md`
- `.claude/rules/documentation.md`
- `.claude/rules/lessons-learned.md`
- `.claude/rules/git.md`
- `.claude/rules/github-issue.md`
- `.claude/rules/pr-description.md`
- `.claude/rules/testing.md`
- `.claude/rules/security.md`
- `.claude/rules/production-data.md`
- `.claude/rules/static-analysis.md`
- `.claude/rules/duplication.md`
- `.claude/rules/dead-code.md`

## パス別ルール

より深いディレクトリの `AGENTS.md` がある場合は、ここに加えてその指示も適用します。

- `.github/workflows/**`: `.claude/rules/github-actions.md`
- `apps/**`: `apps/AGENTS.md`
- `apps/laravel-fullstack/**`: `apps/laravel-fullstack/AGENTS.md`
- `apps/laravel-api/**`: `apps/laravel-api/AGENTS.md`
- `apps/laminas/**`: `apps/laminas/AGENTS.md`

## ルール構成を変更するとき

`.claude/rules/` の本文が唯一の正本です。ルールファイルの追加・削除・改名・適用範囲変更時は、同一変更で `CLAUDE.md`、該当する `AGENTS.md`、README の「AI エージェント向けルール」表を同期してください。ルール本文だけの変更では、これらの入口ファイルを更新する必要はありません。

## ショートカットの扱い

`.claude/rules/shortcuts.md` の意図は守る。ただし Claude 固有のスキル名は、利用可能な Codex の機能・指示に読み替える。Codex は PR の承認・マージを実行しない。
