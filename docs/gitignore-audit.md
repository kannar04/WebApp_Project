# Home2Home Git ignore audit

Audit date: 2026-10-09.

Cleanup recheck: `.env.example`/`.env.sample` đã được sửa lại thành ngoại lệ `!` vì snapshot trước đó không còn khớp cấu hình hiện tại. `codebase_cleanup_test.php` kiểm chứng 16 đường dẫn riêng tư bị ignore và 26 đường dẫn chia sẻ không bị ignore. Không có tracked ignored files; `.env.example` an toàn hiện đủ điều kiện add vào Git nhưng không được tự stage/commit. Chưa phát hiện credential sản xuất; seed credentials chỉ dùng local. Các kết quả lịch sử dưới đây không thay thế recheck này.

## Scope and stack

Inspected the complete workspace inventory (including hidden files outside `.git`),
the previous `.gitignore`, `git status`, `git ls-files`, `skill_web.md`, environment
loading, database configuration and the upload service. The project uses PHP MVC,
PDO/MySQL, XAMPP, HTML/CSS/JavaScript and Bootstrap 4.6.2 via CDN. Composer and npm
manifests/dependencies are not currently present; ignore rules accommodate future
reinstallable dependencies without excluding manifests or lockfiles.

## Rules applied

The authoritative rule list is the root `.gitignore`.

- Secrets: `.env` and variants, local database configuration, Composer `auth.json`,
  `secrets/`, `credentials/`, private keys and certificate bundles. Safe
  `.env.example` and `.env.sample` remain shareable.
- IDE/OS: `.idea/`, `.vscode/`, IntelliJ modules, editor workspace files and OS metadata.
  `.vscode/` does not currently exist, so no shared editor configuration is excluded.
  Revisit this rule if the team adds shared tasks/extensions/settings.
- Logs/temp: log files, temporary editor files and explicitly scoped runtime
  directories at the root or under `storage/`. No blanket nested `cache/` rule.
- Dependencies/generated tests: root `vendor/`, `node_modules/`, Composer binary,
  coverage and PHPUnit cache. Keep dependency manifests and lockfiles.
- Runtime uploads: `public/uploads/*`, with `public/uploads/.gitkeep` retained.
- Database: dedicated backup/dump/export directories and dump/compressed backup
  extensions. Plain SQL is shareable by default; private SQL exports must be placed
  in `database/backups/`, `database/dumps/` or `database/exports/`.
- Optional local installations: root `xampp/` and `phpmyadmin/`.

Removed blanket exclusions for `*.sql`, `graph/`, `build/` and `dist/`. These could
hide migrations, procedures, team diagrams or required deliverables. There is no
build pipeline in the current project that warrants excluding build directories.

## Files preserved

PHP source under `app/`, `config/`, `core/`, `routes/`, `public/` and `tests/`;
CSS/JS/demo assets; `.htaccess`; `database/schema.sql`, `database/seed.sql`, database
import/setup scripts, future migrations/procedures; README; all three skill files;
`File_Ideas/Functions.txt`; group idea documents; ERD, class and functional diagrams
under `image/`; `graph/`; architecture/database setup reports and screenshots under
`docs/`; `.env.example`; future `.env.sample`; upload `.gitkeep`.

Preserved means eligible for Git, not automatically staged or committed. Existing
uncommitted work was left intact.

## Verification and tracking

- `git check-ignore --no-index -q`: PASS, 34 excluded paths and 35 shareable paths.
- `git check-ignore --no-index -v`: confirmed exact rules for `.env`, `.env.local`,
  both safe environment templates, runtime uploads, `.gitkeep` and private SQL exports.
- `git ls-files -ci --exclude-standard`: no tracked ignored files.
- Untracked files with `git rm --cached`: none; no such command was necessary.
- `git diff --check`: PASS.
- Existing modified/untracked source and documentation remain visible in Git status.

## Sensitive information and remaining considerations

`config/database.php` reads `DB_PASSWORD` from the environment and has an empty
local fallback; `.env.example` contains no password. Seed account hashes and the
documented `Password123!` are intentional test credentials, not production secrets.
Do not use those accounts/passwords on a public production deployment.

Scanned all 31 reachable commits for common private-key, AWS access-key, GitHub-token,
live-token and environment-password patterns: no matches. This pattern check is not
a guarantee that every possible secret format has been detected. Arbitrary private
exports or custom secret filenames require content review before staging.

If a previously committed credential is discovered, `.gitignore` cannot remove it
from history: revoke/rotate it first, then coordinate any required history cleanup
with the team. No history rewrite, local file deletion, commit or push was performed.
