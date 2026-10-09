# Home2Home — Cleanup & stabilization report

Date: 2026-10-09. Scope: direct code cleanup, Stored Procedure compliance, schema consistency and team Git hygiene; not a redesign or completion of every planned feature.

## Outcome and safety checkpoint

Baseline was clean on branch `test`, with 118 tracked files. Final inventory contains 126 files outside `.git`/dependencies: source, SQL, documentation, assets, safe environment example and one ignored runtime upload. All 66 PHP files were token-scanned/linted; all routine SQL scripts were reviewed; ERD/class/function diagrams were visually inspected. Per-file roles/issues/actions and all 81 routine consumers are recorded in [codebase-cleanup-audit.md](codebase-cleanup-audit.md).

No existing file was deleted, no shared table/routine was altered/dropped, and no live data was reseeded/reset. No Git staging, untracking, commit, merge, push, reset, clean or history rewrite. Only exact generated test fixtures/databases/uploads were cleaned by the tests that created them. An existing runtime PNG was preserved, even though its bytes match a design asset. Temporary independent source/Chrome profiles remain outside Git for inspection.

## Changes made this pass

| Files | Confirmed issue / change | Verification |
|---|---|---|
| `app/Models/Listing.php`, `Wishlist.php` | Wishlist existence check hydrated unused photos/amenities/reviews; optional lightweight public lookup preserves guards and default detail contract. Successful add reduced from 6 to 3 CALLs; remove path remains 2. No speculative cache. | Routine save/remove and actual Chrome favorite toggle/restore PASS |
| `app/Controllers/AdminController.php` | Shared strict roles validator for create/edit/roles; edit previously only checked array shape. Reject scalar/nested/unknown/oversized input before mutation, preserve non-secret fields. Catch missing listing visibility/delete errors and recover to Admin. | Expanded HTTP cases + validator test PASS |
| `app/Views/host/listing-form.php` | Removed unused hidden `amenities_present` marker. Full-project reference search found only its definition; no endpoint/JS reads it. `amenities[]`, CSRF, upload and form action unchanged. | Real Host save/upload/bad-input rollback PASS |
| `database/schema.sql` | Canonical initialization aligned to exact existing ERD DDL: 22 tables, types/nullability/defaults, 33 FKs, 60 indexes, 48 CHECK expressions. Strip generated sequence counters; no data dump. | Fresh schema exact DDL parity PASS |
| `database/import.php` | Prevent default initialization from reseeding an existing DB; explicit `--confirm-demo-seed` required with `--seed-only`. Metadata preflight is not business SQL. | Existing-schema/unconfirmed-seed refusal PASS |
| `database/procedures/install.php`, `database/install_procedures.php` | Installer no longer auto-runs historical DROP migration 040; removed unreachable prune execution branch. Preserve SQL history for separate reviewed execution. | Recording PDO: 75 production CREATE executions, 14 opt-in test definitions, zero DROP PASS |
| `.gitignore`, `.env.example` | Fix template exceptions, make safe setup example shareable; debug defaults false. Keep private env, keys, local credentials, logs, cache, IDE/OS metadata, uploads, exports and reinstallable dependencies ignored. | 16 private / 26 shared path assertions PASS |
| `database/tools/routine_catalog.php` | Read-only catalog includes defaults/nullability, FK/index and SHOW CREATE metadata; identifier validation before metadata SHOW. | Catalog and exact isolated schema comparison PASS |
| `database/tools/codebase_inventory.php` | Reproducible per-file inventory, token CALL references, method review candidates and byte-identical files. Candidate detection never authorizes deletion. | 126 files / 66 PHP / 106 CALLs inventoried |
| `tests/codebase_cleanup_test.php`, `fresh_setup_test.php`, `schema_test.php`, `http_audit.ps1`, `stored_procedure_audit_test.php` | Added cleanup/Git/installer safety, independent setup, exact DDL parity, Admin payload/error regressions; scoped metadata exceptions kept explicit. | Suites PASS |
| `README.md`, `docs/architecture.md`, `database-setup.md`, `gitignore-audit.md`, `stored-procedure-audit.md`, `testing-report.md`, `progress.md`, `user-test-matrix.md` | Correct dataflow/setup/seed/migration descriptions; annotate historical snapshots and record current limits. Three new cleanup/consistency reports. | Instructions executed locally; whitespace diff check PASS |

Files deleted: **none**. Confirmed unused code removed: one HTML marker and installer prune branch. Duplicate code removed: repeated Admin role payload checks. Public API/helper candidates (`Booking::findForUser`, `Database::reset`, global `old()`) remain `REVIEW_REQUIRED` because dynamic/external consumers are not disproved.

## Stored Procedure compliance and routine cleanup

- Direct business SQL found this pass: **0**; newly converted queries: **0**. The earlier 108→0 refactor is historical, not work claimed again here.
- 106 literal CALL sites / 78 distinct signatures checked against the actual DB. Controllers/Views contain no query execution, runtime contains no schema DDL/mysqli/debug dumps. CALL-only adapter binds values and drains/closes rowsets; 100 sequential routines with partially read caller results passed.
- Current production routines: 67 unique names; original 16 preserved, 12 reused in runtime. **No new routine created this pass**; 51 prior application/CLI routines retained. Local catalog additionally contains 14 opt-in test routines.
- Dependency closure: 56 runtime-PHP procedures, 3 internal-only helpers, 4 CLI seed routines, 14 test routines, 4 tested legacy compatibility routines. None dropped for lack of a PHP caller. The four legacy compatibility routines are retained pending any external-consumer review.
- Eight ordered redefinitions are intentional repair/projection upgrades, not duplicates to delete. Historical 040 lists ten superseded helpers already absent locally; retained but not automatically executed. Shared DROP requires separate review/confirmation.
- Model/Repository/Service dataflow stays `Controller → ProcedureConnection → PDO CALL → routine transaction/tables → rowsets → View/JSON`; role/ownership, locks, state machine, snapshots, events, notification and caller savepoints protected by regression.

Detailed routine mapping: [stored-procedure-audit.md](stored-procedure-audit.md), [inventory](codebase-cleanup-audit.md). Database/class/ERD reconciliation: [database-consistency-audit.md](database-consistency-audit.md).

## Git and secret hygiene

`git check-ignore --no-index -v` confirms `.env`/`.env.local` exclusions and `!` exceptions for `.env.example`/`.env.sample`/upload `.gitkeep`. Schema, procedure SQL, migrations, README, all skills, Functions.txt, diagrams, source/tests/docs/design/demo images and manifests/lockfiles remain shareable. No blanket `*.sql`/`docs/`/source-directory exclusion.

`git ls-files -ci --exclude-standard`: no tracked ignored files. `.env` is not tracked; safe `.env.example` was previously ignored and is now visible as an unstaged new Git path. No `git rm --cached` necessary. There is no Composer/npm build to remove; existing dependency ignore rules allow future reproducible installs. `.vscode` does not exist; revisit the local-settings rule if shared tasks/configuration are added.

Common private-key/AWS/GitHub/live-token patterns found no matches in eligible text files. History filename checks found no tracked env/private-key paths. This is not a guarantee against every custom secret format. Seed test credentials are intentionally public local fixtures, not production accounts. If any real credential is found in history, rotate/revoke it; `.gitignore` does not scrub history. No history rewrite performed.

## Executed regression matrix

Only PASS/FAIL/NOT_VERIFIED/BLOCKED are used for this report. Logs came from actual test execution, not invented screenshots or lint-only assumptions.

| Check / feature priority | Evidence executed | Result |
|---|---|---|
| PHP syntax | All 66 files `php -l` | PASS |
| Runtime architecture / Git paths / installer | `tests/codebase_cleanup_test.php`: no request DDL/debug/mysqli/Controller/View queries; 16 ignored / 26 shareable paths; fake PDO records zero DROP | PASS |
| SQL signatures/cursor/result handling | `tests/stored_procedure_audit_test.php`: 0 raw business SQL, 106 CALL sites, 78 signatures, 100 sequential routines | PASS |
| Schema/fresh SQL/seed/review | `tests/schema_test.php --compare-live`: 22 exact DDL structures, repeated seed, completed review and duplicate/ownership rejection; own DB dropped | PASS |
| Must: quote/book/confirm/cancel/pricing/events/calendar | `booking_flow_test.php`, `source_audit_test.php`, `procedure_flow_test.php` | PASS |
| Transaction, ownership and concurrent overlap | Atomic invalid JSON rollback, caller savepoint rollback, two independent stale-snapshot workers: one succeeds/one rejects | PASS |
| Must: Auth/profile/roles/Host/Admin CRUD | Real `http_audit.ps1` registration/login/profile/Host enrollment, role denial, listing upload/moderation, user changes and actual booking state reads | PASS |
| Admin edit role payload and missing listing recovery | HTTP scalar/unknown/nested roles keep form; nonexistent visibility/delete safely return to Admin | PASS |
| Important: wishlist and review | Actual Chrome favorite add/remove/restore; isolated review eligibility checks | PASS |
| JavaScript/CSS/UI responsive | Chrome V8 parse, CSS declaration validation, no JS exceptions; 48 page/viewport checks at 375/768/1024/1440 | PASS |
| AJAX quote loading/stale/error recovery | Actual live quote and deliberate stale-response/error/clear-input browser cases | PASS |
| HTML/JSON DB outage and smoke | Separate failure server, safe 500 without SQL/secrets; real home/detail/quote smoke | PASS |
| Image seed / asset references | Dry-run 0 inserted / 5 skipped; 5 existing local demo paths/FKs valid | PASS |
| Independent new-machine-style source setup | Git-eligible files copied into separate TEMP workspace, no `.git`/private `.env`/user uploads; isolated schema/seed/routines/server, home/detail/assets/quote and Guest/Host/Admin login/logout; own DB removed | PASS |
| Git whitespace and data preservation | Diff check; no destructive Git/shared DB changes; owned fixture cleanup and preserved user upload | PASS |
| Remote GitHub clone/access and teammate Apache installation | Local eligible copy only; no remote writes or other-machine access | NOT_VERIFIED |
| Must: full Host deletion/image removal/reordering, full visible availability calendar | Missing/incomplete before cleanup; preserved implemented flows without adding new feature scope | NOT_VERIFIED |
| Important: reset/change password, full filters/pagination, map, reports, category/review management, inbox/email, Host reply/monthly stats | Missing/incomplete features remain; no fabricated E2E coverage | NOT_VERIFIED |
| Optional payments/messages/promotions/Instant Book/news/verification/i18n/dark mode | Not implemented by stabilization | NOT_VERIFIED |
| Full keyboard/screen reader, large-load/deadlock stress, production grants/Oracle MySQL/non-Vietnam zones | Not executed/deployed | NOT_VERIFIED |

## Remaining work and handoff

No reproduced failing case remains in the regression suites run here. This does **not** mean the entire Functions.txt scope is complete. Retained public method/helper and external routine consumer questions are `REVIEW_REQUIRED`; planned but absent feature workflows, production deployment/accessibility/load testing remain `NOT_VERIFIED`.

Use `.env.example` for each member's local `.env`. For an existing DB install routines only; never use initialization to repair a missing routine. Do not run historical DROP or demo reseed on shared data without confirmation. Before the team commits, review the unstaged diff/new files; no commit/push was performed automatically.

App local URL: http://127.0.0.1:8090/. Reproducible commands and database setup: [README.md](../README.md), [database-setup.md](database-setup.md). User journey details: [user-test-matrix.md](user-test-matrix.md).
