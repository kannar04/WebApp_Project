# Home2Home — final verification / handoff
Date: 2026-10-09. Branch: `home2home/quality-core`. Local PHP 8.2.12 / MariaDB 10.4.32 / XAMPP / Chrome headless. No commit/push.

## Outcome and quality gate
Implementation before: 40 full / 21 partial / 33 absent; after: 67 IMPLEMENTED / 2 PARTIAL / 25 NOT_IMPLEMENTED. Verification: 65 PASS, 2 BLOCKED, 27 NOT_VERIFIED; last full regression exit 0 with no remaining reproduced failure in executed cases. This is **not** a 94/94 completion or production certification. See [all 94 groups](requirements-matrix.md).

Must-have: 38 implemented/PASS for the documented acceptance cases. Important: 25 PASS, Maps and share NOT_VERIFIED, two password-recovery groups BLOCKED by mail configuration. Nice-to-have: two participant phone alternatives PASS; other 25 deferred until Core gate is signed off. Groups count role-specific requirement bullets, not unique capabilities.

User confirmed: “Chưa có, ghi rõ đang chờ cấu hình”. Email recovery delivery is **đang chờ cấu hình**. No public token preview, simulated mail, secret request in chat, SMTP account creation or external subscription. Existing password change and local token consumption are tested separately. Notification requirements use their permitted in-app channel; email notification delivery is not claimed.

## Executed evidence
These are exact command/results observed during this working session, not inherited status from prior reports. Excerpts below are selected output, **not a fabricated complete log**.

| Code | Command / scope | Observed outcome |
|---|---|---|
| E1 | PHP `-l` over all 86 PHP files; `source_audit_test.php` via isolated runner | 0 syntax failures; all 61 handlers public/autoloadable |
| E2 | `tests/schema_test.php --compare-live` | 22 schema tables; repeat-safe seed in owned DB; exact SHOW CREATE TABLE parity (generated counters excluded); review eligibility/duplicates |
| E3 | booking_flow + source_audit via runner | quote→pending→confirm→cancel; immutable nights/policy/refund; blocked dates; unauthorized/future completion denied; Host/Admin past completion additionally E6 |
| E4 | procedure_flow + stored_procedure_audit via runner | 140 CALL sites / 108 signatures; zero runtime direct business SQL; 100 sequential calls/cursor draining; savepoints; two-process overlap: one created/one blocked |
| E5 | http_smoke + http_audit via runner | real registration/profile/Host enrollment; upload and invalid-upload rollback; request/duplicate/confirmation/cancel; Admin account/listing CRUD/status/roles; 401/403/404/419/422 recovery |
| E6 | quality_core_test via runner | calendar/phone privacy; detail/events/refund; photo reorder/removal/stale snapshot/rollback; private pending preview including amenities; complete/reject; rules/three refund policies; reviews/reports/catalog; own inbox/read; all four sorts; 13 approved pagination fixtures (12+1); real multipart avatar; password change and old-session revocation; recovery one-use/hash/expiry/throttle/HTTP consume |
| E7 | browser_audit via runner, real Chrome | 80 role/page/viewport checks (375/768/1024/1440), plus login, two detail viewports, three next-month clicks, AJAX/races/recovery, favorite toggle/restore, actual mobile table scrolling; no JS exceptions, broken images, duplicate IDs or page overflow |
| E8 | local runtime and controlled DB outage | production-only installer: 106 definitions, counts in all 22 shared tables unchanged; localhost:8090 smoke PASS; separate port/wrong DB child: HTML+JSON safe 500 |
| E9 | codebase_cleanup and Git checks | 16 ignored-private / 26 shareable probe paths; no DROP installer; environment/private uploads ignored, schema/skills/docs/source retained |

Reproduce full regression:
```powershell
C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression
C:\xampp\php\php.exe tests\schema_test.php --compare-live
powershell -NoProfile -ExecutionPolicy Bypass -File tests\db_outage_audit.ps1
```
The full runner uses `db_home2home_schema_test_<12 hex>` and its own ephemeral server, with MAIL_ENABLED=false. The first five legacy PHP suites, quality suite, HTTP smoke/audit and Chrome are invoked with that exact owned DB/server. Test-only routines are opt-in there. Cleanup drops only the verified owned schema and removes exact fixture uploads. Shared bookings/accounts/photos are not reset.

Representative observed excerpts:
```text
PASS installer: 106 production definitions, 16 opt-in test definitions; zero DROP
PASS 140 CALL sites / 108 routine signatures against the live database
PASS two-process overlapping booking with stale snapshots: one created, one blocked
PASS QA authorized Host/Admin historical completion, Host counts and rejection notification to both participants
PASS QA Host check-in/out and all rule flags; flexible/intermediate/strict snapshot refund preview equals recorded refund; caller rollback
PASS QA real HTTP multipart avatar/name/phone, rendered image and preserve-on-no-upload; exact fixture file cleaned
PASS QA recovery hashed token/one-use/expiry/issuance throttle/HTTP consume; mail delivery explicitly BLOCKED, not simulated
PASS QA 13 approved fixtures: 12+1 HTTP results, page totals and navigation preserves location/sort/price/all amenities
PASS mobile Admin table scrolling without page overflow
PASS no browser JavaScript exceptions
PASS exact owned test database cleanup; shared database untouched
PASS local routine installation: 106 definitions; all 22 table row counts unchanged; no seed/reset/test-routine installation.
```
106 is SQL definition count (some earlier routines intentionally redefined), not 106 unique features/procedures. 108 signature references include testing/legacy names and must not be conflated with production definition count.

## Dataflow / dependency map
All business DB calls remain Model/Service → ProcedureConnection → literal CALL → stored procedure; views/controllers contain no SQL. Server/SQL recheck data and ownership regardless of UI state. Calendar display/quote are advisory; booking create/transition rechecks availability inside transaction. A successful UI redirect is not treated as persistence proof; E5/E6 read saved values via CALL.

| Workflow | UI / route / handler | Model/service and routines | DB / dependency / acceptance |
|---|---|---|---|
| AUTH | auth views; /register,/login,/logout → AuthController + Auth | User; sp_user_insert/get_by_email/get_with_roles | users,user_roles; active account, session renewal, role redirects; E5/E7 |
| PROFILE | /profile, /profile/become-host → ProfileController | User + UploadService; sp_user_update_profile/grant_host | users/user_roles, exact owned upload; preserve existing avatar without new upload; E4/E5/E6 |
| LISTING | Host form/dashboard, /host/listings/* → HostController; /listings/{id} → ListingController | Listing; sp_listing_create/update/amenities_replace/photo_add; sp_host_photos_manage/listing_delete | listings/photos/amenities; ownership, atomic upload/save, complete photo snapshot and re-moderation; soft deletion keeps bookings/files; E3/E5/E6 |
| CALENDAR | components/calendar + Host availability, /listings/{id}?month= | Listing + Calendar; sp_listing_calendar/availability_set | availability + pending/confirmed bookings; default-open, half-open checkout, privacy and blocked-confirmed guard; E3/E6/E7 |
| BOOKING | /bookings/{id}, booking forms, Host/Admin transition | Booking + BookingService; sp_booking_* and sp_booking_detail/nights/events/refund_preview/admin_booking_search | bookings/nights/events/cancellations; frozen totals/policy, SQL locks/savepoints, authorized lifecycle/refund; E3/E4/E5/E6 |
| RECOVERY | /profile/password, /forgot-password, /reset-password → ProfileController/AuthController | User + PasswordReset + PasswordRecoveryService; sp_user_password_change/password_reset_issue/consume | users,password_reset_tokens; SHA256 token hash, bcrypt password, expiry/one-use/throttle, revoke old tokens/sessions; mail transport pending; E6 |
| REVIEW | public detail/Guest booking review, /host/reviews, /admin/reviews → BookingController/ReviewController | Review; legacy review insert/read + sp_host_reviews/review_reply/admin_reviews/review_moderate | reviews+completed bookings; one review per booking including hidden review; soft moderation and own Host reply; E2/E6 |
| INBOX | /notifications,/notifications/{id}/read → NotificationController | Notification; sp_notifications_list/notification_read; booking routine event inserts | notifications within lifecycle transaction; own scope, new/confirmed/rejected/cancelled, persistent read; E3/E6 |
| HOST_STATS | /host → HostController | HostRepository; sp_host_stats/host_monthly_revenue | owned listings/bookings, only completed revenue grouped by check-out month, deleted-history retained; E6/E7 |
| CONTACT | participant booking detail, Host booking table | Booking detail; corrected NTK_sp_get_host_bookings | SQL returns NULL phones before confirmation; confirmed/completed participant tel links only; E6 |
| SEARCH | home filter/pagination, /api/listings → HomeController | Listing::searchPage; sp_listing_search_filtered | approved visible active listings; all selected amenities, stable order, limit/offset+total within CALL, preserve query; E5/E6/E7 |
| MAP | listing detail external link | address or coordinates from Listing projection; no new DB mutation | Google Maps URL contract; not interactive map or location verification; external result NOT_VERIFIED |
| SHARE | listing data-share → app.js | native share/clipboard fallback; no DB write | source exists; real clipboard/native external share NOT_VERIFIED |
| WISHLIST | /wishlist,/api/wishlist/{id} → WishlistController | Wishlist + reused wishlist routines | wishlist_items; own save/remove, public listing guard, actual Chrome toggle/restoration; E4/E5/E7 |
| ADMIN_USER | /admin/users/* → AdminController | User/AdminRepository; sp_user_admin_*/roles/status/soft_delete + audit | users/user_roles/admin_audit_logs; strict role arrays, self-demotion guard, atomic profile/roles/audit; E4/E5 |
| ADMIN_LISTING | /admin/listings/{id}/preview and manage actions | Listing/AdminRepository; sp_listing_admin_preview/photos_for_actor/amenities_for_admin + admin listing routines | private pending photo/amenity detail, Admin-only, preview cannot book, moderation reason and soft removal; E3/E5/E6/E7 |
| ADMIN_STATS | /admin → AdminController | AdminRepository; sp_admin_count_*/total_revenue | real user/Guest/Host/listing/booking counts, completed revenue; E6/E7 |
| CATALOG | /admin/catalog → CatalogController | Catalog; sp_admin_catalog/catalog_save | property_types/amenities + audit; add/edit/deactivate/reactivate, no FK deletion; E6 |
| REPORT | /reports and /admin/reports → ReportController | Report; sp_report_create/reports_for_user/admin_reports/report_resolve | reports + audit; own reports, active public listing or own booking, resolution note/closed time; E6 |
| GUARD | shared requireAuth/requireRole + route/SQL guards | Auth, ProcedureConnection, SQL actor assertions | no client “is_admin” trust, CSRF/auth/ownership negative cases; E3/E4/E5/E6 |
| AUDIT | authorized Admin actions | AdminRepository::audit + atomic SQL writes | admin_audit_logs; profile/roles/moderation/booking/catalog/report changes; E3/E4/E5/E6 |
| OPTIONAL | no implementation route | no fabricated model/routine/UI | payment/chat/Instant Book/KYC/promotions/news/etc.; see each of 25 rows |

Google Maps link uses the official [Maps URLs contract](https://developers.google.com/maps/documentation/urls/get-started). Addresses without coordinates are explicitly labelled search results requiring confirmation.

## Repairs / refactoring / database changes
Eight partial Must-have groups implemented: H05/H06/H07/H09/G05/G09/A04/A07. Added Host photo management and safe soft-delete, complete calendars, authorized booking detail/history/refund snapshots, Admin pending preview/search. Important flows include advanced filters/pagination, reply/moderation, report lifecycle, catalogs, inbox, monthly revenue, Guest stats and password change/recovery mechanics.

No baseline PHP syntax failure was reproduced; therefore no invented “syntax bug fixed”. New and modified PHP passed lint. Reproduced runtime/journey defects are in [defect register](defect-register.md): collation mismatch; private photo/amenity preview; ignored test BaseUrl; mobile filters and long-name Admin table overflow; month navigation; premature phone exposure.

No table/ERD change, ALTER, reset, import or shared seed. Added 31 production routine definitions in 050/055/060/065 (17+1+11+2); updated existing lifecycle notifications, projections, stats, and password/token atomicity. Installer processes 106 definitions, preserves historical routines and skips destructive 040 migration. Two test-only fixture routines were added and restricted to owned test schemas. Local installation used production-only mode; all 22 table counts unchanged. Counts are a row-count check, not an assertion that arbitrary concurrent external edits could not occur.

New code separated into small Controllers/Models/services, shared calendar and detail projections, SQL savepoints and trusted server validation. No unproven dead-code deletion. Existing old compact methods were not mass-reformatted. No business SELECT/INSERT/UPDATE/DELETE was moved out of SP into PHP; the new work introduced CALL-only paths and retained the existing guard. The three project skills influenced isolated testing, database-first authorization and a minimal warm responsive UI, not a full redesign.

## UI evidence
Actual generated Chrome screenshots with isolated demo data:
- [Guest home 375](screenshots/guest-home-375.png), [1440](screenshots/guest-home-1440.png).
- [Guest booking 375](screenshots/guest-booking-375.png), [1440](screenshots/guest-booking-1440.png).
- [Host calendar 375](screenshots/host-calendar-375.png), [1440](screenshots/host-calendar-1440.png).
- [Admin 375](screenshots/admin-dashboard-375.png), [1440](screenshots/admin-dashboard-1440.png).

Manually inspected four 375px screenshots: readable labels/status, wrapped detail content, monthly calendar visible, Admin stats/actions accessible. Automated viewport checks also verify image load, duplicate IDs, document overflow and JavaScript. No screenshot-diff, all-device, screen-reader or complete keyboard certification.

## Remaining work and risks
- BLOCKED: choose/configure PHP mail transport/service privately, set local MAIL_FROM/PASSWORD_RESET_BASE_URL/MAIL_ENABLED, verify real delivery+bad-address/transport-failure recovery. No credentials in shared template/chat.
- NOT_VERIFIED implemented: G15 external Maps opening/address accuracy; G19 real native share/clipboard. Local handler/source checks are not end-to-end external success.
- NOT_IMPLEMENTED/NOT_VERIFIED: 25 Nice-to-have groups, including payment gateways, chat, promotions/pricing, Instant Book, KYC, badges, arrival instructions, reminders, news, recommendation/map-drag search and language/dark mode.
- Production deployment/least-privilege EXECUTE grants, HTTPS/mail deliverability, CDN/network outage fallback, larger dashboards, load/concurrency stress beyond two workers, full accessibility remain NOT_VERIFIED.
- Existing sessions without password fingerprint require one fresh login; password changes invalidate other sessions.
- Photo removal is reference removal, not filesystem deletion. Physical orphan-file cleanup/retention policy requires separate agreed maintenance.
- Recovery uses PHP mail transport, not a fully configured SMTP SDK. MAIL_ENABLED=true alone does not demonstrate delivery.
- No reset of shared data, no deletion of teammates' source/uploads, no Git untrack/history rewrite. Private .env unchanged; .env.example contains disabled/blank safe placeholders only. No leak was printed or newly committed; no comprehensive historical secret-scan certification claimed.

## Git / changed files / run
All changes are unstaged on `home2home/quality-core`; no automatic commit, push or merge. Changed: route/controller/model/views above; core Auth/Calendar; config/mail.php and safe .env.example; CSS; 010/011/020 and new 050/055/060/065 SQL; testing fixtures; regression/browser harness and new quality suite; README and quality/audit/progress docs. `git status --short` gives the exact live inventory.

With MySQL running in XAMPP and local .env configured:
```powershell
C:\xampp\php\php.exe database\install_procedures.php
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public\router.php
```
Open http://127.0.0.1:8090. The existing server on 8090 was smoke-checked; do not start a second process on that same port. Use README demo accounts locally only. Existing DB: never run import/seed to apply these routine changes. No entire-94 release declaration until blocked/unverified gates are cleared.

