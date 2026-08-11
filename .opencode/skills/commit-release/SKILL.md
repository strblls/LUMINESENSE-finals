---
name: commit-release
description: Commit and release hygiene for LumineSense. Use when preparing git commits, merges, tags, or releases. Enforces conventional commits, secret/artifact safety, and worktree hygiene. Only commit when the user explicitly asks.
---

# LumineSense Commit & Release

## Before committing

1. `git status` and `git diff` — review exactly what will be staged.
2. `git log --oneline -10` — match the existing message style.
3. Never stage generated/secret files:
   - `.env`, `.env.local`
   - `logs/`, `*.log`
   - `uploads/` (real ID photos)
   - `.kilo/`, `vendor/`, `node_modules/`, `composer.lock` build noise per existing practice
4. Stage only the files relevant to the change — no `git add -A` unless verified.

## Commit message

Conventional Commits:

```
<type>: <short imperative summary>

<optional body: why, not what>
```

Types: `feat`, `fix`, `refactor`, `docs`, `chore`, `style`, `perf`, `test`, `security`.

- Single line unless a body adds real context.
- If a commit is rejected by hooks, fix the issue and create a NEW commit — do not amend a failed commit.

## Pushing

- Only push when the user asks.
- If the working repo tracks `origin/main`, push the intended branch and nothing else. No force-push.

## Release/tagging

- Tag semantic versions (`v1.2.3`) when asked for a release. Summarize notable changes since the last tag in the release notes.
- Mention the `docs/PROJECT_DOCUMENTATION.md` "Table Tracking" and README "Backend Needs" so docs stay in sync with released features.

## .kilo note

- `.kilo/` holds bulk media/output artifacts (large videos, zips) and is excluded from normal commits; keep it out of releases.
