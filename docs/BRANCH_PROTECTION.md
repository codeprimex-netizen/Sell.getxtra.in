# Branch Protection & Review Policy (Req 23.2)

`main` is protected. Configuration is captured as code in `.github/settings.yml`
(applied via the Settings app) and summarized here.

## Rules on `main`
- **Pull request required** — no direct pushes.
- **1+ approving review**, including **Code Owner** review (`.github/CODEOWNERS`).
- **Stale reviews dismissed** on new commits.
- **Required status checks** (must be green, branch up to date):
  - `Syntax lint` (CI)
  - `Tests` (CI — all suites via `bin/run-tests.php`, PHP 8.2/8.3/8.4)
  - `Container build` (CI — production Docker image builds)
  - `Dependency audit` (Security — `composer audit`)
  - `SAST + lint` (Security — static analysis guard)
- **Linear history** required; **squash or rebase** merge only.
- **Admins included** in enforcement.
- **Branch auto-deleted** after merge.

## Advisory (ratcheting to required)
`Code style (PHPCS)` and `Static analysis (PHPStan)` run on every PR but are
currently advisory (`continue-on-error`). Once the PHPStan baseline is clean and
PSR-12 violations are cleared, remove `continue-on-error` and add them to the
required contexts above.

## Merge flow
1. Open a PR from a feature branch; fill in the PR template.
2. CI + security workflows run automatically.
3. A code owner reviews and approves.
4. Squash/rebase merge once all required checks pass.
5. `main` merges trigger the CD pipeline (staging → approval → production).
