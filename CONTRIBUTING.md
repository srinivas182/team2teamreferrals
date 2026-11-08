# How we work

## Sprints

- Work is delivered sprint by sprint, as planned in the PRD sprint breakdown. Each sprint gets a detailed specification, approved before work starts.
- Every sprint is merged to `main` with CI green before the next sprint begins.
- Before each run of sprint work: status check of `main`, open pull requests and CI.

## Branches and pull requests

- `main` is always releasable. No direct pushes once CI exists (from Sprint 3).
- One branch per sprint or sub-sprint: `sprint-<n>-<short-name>`, e.g. `sprint-1-brand-design`.
- Open the pull request as a draft while working; mark it ready for review when complete.
- Commit messages: imperative, present tense, with the sprint prefix, e.g. `S1: add design tokens`.

## CI and GitHub Actions minutes

- Check locally before pushing: code style, static analysis, new and affected tests.
- Draft pull requests run only the quick lint and static-analysis job.
- The full test suite runs once, when the pull request is marked ready.
- No repeat full run on `main` after merge; superseded runs are cancelled automatically.
- Unaffected parts are skipped (docs-only and front-end-only changes run reduced pipelines).
- Target: CI passes or fails within about 10 minutes.

## Quality bar

- Laravel and PSR-12 conventions, PHPStan at the strictest practical level, Pest tests.
- Thin controllers; business logic in services or domain classes.
- Every UI change passes the design-review gate against the approved designs and `docs/design/accessibility-and-motion.md`.
- No secrets in the repository. Configuration through environment variables only.
