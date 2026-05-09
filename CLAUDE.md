## Pre-merge checks

Run `make check` before committing to `main` or opening a PR. It builds the release zip and runs Plugin Check (WP-CLI) against the built artefact, not the working tree.

## Agent skills

### Issue tracker

Issues live as GitHub issues at `donnchawp/media-picker-for-immich`, managed via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at repo root (created lazily by `/grill-with-docs`). See `docs/agents/domain.md`.
