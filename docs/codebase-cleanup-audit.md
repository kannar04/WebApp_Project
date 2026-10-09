# Home2Home — Codebase cleanup inventory

Audit: 2026-10-09. Baseline: clean branch `test`, 118 tracked files. Final inventory: 126 workspace files outside .git/vendor/node_modules (including this audit, the final report, database consistency report and one ignored runtime upload); 66 PHP sources token-scanned. Inventory is not a claim of full product/production certification.

## Method and scope

Read all three project skills, File_Ideas/Functions.txt, README/config/routes/runtime source and SQL definitions. Visually inspected the ERD/class/function diagrams. Enumerated hidden workspace files, git status/branch/ls-files, read-only information_schema/SHOW CREATE TABLE, static CALL signatures and routine dependencies. Every PHP file is read by the inventory/token scanner; each route's handler/autoload is exercised. No deletion decision relies on a filename or absence of a literal PHP CALL.

Reproduce: `php database/tools/codebase_inventory.php` and `php database/tools/routine_catalog.php`. The catalog emits structure/routine metadata only, not application rows. Keep exports local; do not include credentials or user dumps.

## File inventory

“Used” distinguishes runtime entrypoints/dependencies, opt-in CLI/test consumers and documents/assets that the team must share. Documents are not dead code because no PHP include points to them. Source review plus tests cover implemented journeys; static references alone are not proof that every branch was exercised.

| File | Vai trò | Có được sử dụng? | Vấn đề | Đề xuất | Mức độ an toàn |
|---|---|---|---|---|---|
| `.env.example` | Configuration | Setup/runtime configuration | Previously ignored; debug enabled | Share safe template; debug off | Keep / tested scoped change |
| `.gitignore` | Team Git rules | Setup/runtime configuration | Templates wrongly ignored | Fixed exceptions; private paths retained | Keep / tested scoped change |
| `app/Controllers/AdminController.php` | HTTP controller | Runtime dependency/route | Duplicate roles checks/edit gap; uncaught missing listing errors | Shared validator; friendly recovery; HTTP tests | Keep / tested scoped change |
| `app/Controllers/AuthController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/BookingController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/HomeController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/HostController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/ListingController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/ProfileController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Controllers/WishlistController.php` | HTTP controller | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Models/AdminRepository.php` | CALL model/repository | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Models/Booking.php` | CALL model/repository | Runtime dependency/route | findForUser has no current method caller | REVIEW_REQUIRED ownership API; routine still tested; retained | REVIEW_REQUIRED — retain |
| `app/Models/HostRepository.php` | CALL model/repository | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Models/Listing.php` | CALL model/repository | Runtime dependency/route | Full detail hydration during existence check | Optional lightweight mode; default detail contract retained | Keep / tested scoped change |
| `app/Models/Review.php` | CALL model/repository | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Models/User.php` | CALL model/repository | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Models/Wishlist.php` | CALL model/repository | Runtime dependency/route | Three unused child CALLs per add | Use public existence projection without child queries | Keep / tested scoped change |
| `app/Services/BookingService.php` | Business/upload service | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Services/UploadService.php` | Business/upload service | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/admin/dashboard.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/admin/listing-edit.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/admin/user-edit.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/auth/login.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/auth/register.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/bookings/index.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/errors/403.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/errors/404.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/errors/500.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/home.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/host/availability.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/host/dashboard.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/host/listing-form.php` | MVC view/layout | Runtime dependency/route | Unused amenities_present input; only reference was this definition | Removed marker; keep amenities[]/CSRF/POST route | Keep / tested scoped change |
| `app/Views/layouts/auth.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/layouts/main.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/listings/show.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/listings/wishlist.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `app/Views/profile/show.php` | MVC view/layout | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `config/app.php` | Configuration | Setup/runtime configuration | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `config/database.php` | Configuration | Setup/runtime configuration | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Auth.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/bootstrap.php` | Shared runtime core | Runtime dependency/route | Global old() has no current literal caller | REVIEW_REQUIRED public template helper; retained | REVIEW_REQUIRED — retain |
| `core/Controller.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Csrf.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Database.php` | Shared runtime core | Runtime dependency/route | reset has no current source caller | REVIEW_REQUIRED public utility; retained | REVIEW_REQUIRED — retain |
| `core/ProcedureConnection.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/ProcedureStatement.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Router.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Session.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `core/Validator.php` | Shared runtime core | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/import.php` | CLI database installation | CLI install/seed/metadata or routine dependency | Initialization could reseed existing data | Metadata preflight; explicit seed confirmation | Keep / tested scoped change |
| `database/install_procedures.php` | CLI database installation | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/001_legacy.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/005_legacy_repairs.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/010_models.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/011_queries.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/020_booking.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/030_atomic_writes.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/procedures/040_prune_transition_helpers.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | Historical destructive migration; external dependencies unknown | REVIEW_REQUIRED; retained and not run | REVIEW_REQUIRED — retain |
| `database/procedures/install.php` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | Default auto-DROP migration; obsolete prune execution branch | Skip 040; keep migration for separate reviewed execution | Keep / tested scoped change |
| `database/procedures/testing.sql` | Routine definition/migration/loader | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/schema.sql` | Canonical initialization schema | CLI install/seed/metadata or routine dependency | Existing ERD database and init DDL drift | Align structure only; exact isolated parity test | Keep / tested scoped change |
| `database/seed.sql` | Local demo seed (upserts) | CLI install/seed/metadata or routine dependency | Idempotent record count is NOT non-destructive reseeding | Retain local seed; guard importer and document upsert risk | Keep / tested scoped change |
| `database/seeds/listing_images.php` | Safe demo image seeder | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/seeds/seed_listing_images.php` | Safe demo image seeder | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/tools/codebase_inventory.php` | Read-only audit utility | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/tools/routine_catalog.php` | Read-only audit utility | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `database/tools/routine_inventory.php` | Read-only audit utility | CLI install/seed/metadata or routine dependency | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/architecture.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/codebase-cleanup-audit.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/codebase-cleanup-report.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/database-consistency-audit.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/database-setup.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/demo-image-sources.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/gitignore-audit.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/implementation-plan.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/progress.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/requirements-matrix.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/screenshots/home-mobile.png` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/screenshots/home.png` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/screenshots/listing.png` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/screenshots/login.png` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/stored-procedure-audit.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/syntax-audit-report.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/team-evidence-checklist.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/testing-report.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/user-journey-audit.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `docs/user-test-matrix.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `File_Ideas/31241020025_Nguyen_Tuan_Khoi.txt` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `File_Ideas/31241020790_DoSonThanh` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `File_Ideas/Functions.txt` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `File_Ideas/Hoshiyomi's Idea.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `File_Ideas/Phong_Function_Suggested` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `image/erd-home2home.drawio.png` | Source diagram/design evidence | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `image/Home2Home_UI.png` | Source diagram/design evidence | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `image/so-do-lop_web.png` | Source diagram/design evidence | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `image/so-do-phan-ra-chuc-nang_web.png` | Source diagram/design evidence | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/.htaccess` | Web entry/rewrite | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/css/app.css` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | Breakpoint overrides are intentional, not identical dead rules | Keep responsive design and browser-check | Keep / tested scoped change |
| `public/assets/images/demo/1449158743715-0a90ebb6d2d8.jpg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/images/demo/1499793983690-e29da59ef1c2.jpg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/images/demo/1600566753086-00f18fb6b3ea.jpg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/images/demo/1600585154340-be6161a56a0c.jpg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/images/demo/1600607687920-4e2a09cf159d.jpg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/images/placeholder.svg` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/assets/js/app.js` | Frontend/design/demo asset | View/CSS/JS or safe demo asset reference | Single initialize; guarded AJAX revision/abort and submit state | Keep; V8/AJAX regression verified | Keep / tested scoped change |
| `public/index.php` | Web entry/rewrite | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/router.php` | Web entry/rewrite | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/uploads/.gitkeep` | Runtime upload directory placeholder | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `public/uploads/listing-33-ccc249bb36d505741cd0c2f1.png` | Runtime upload directory placeholder | Runtime/private; not shareable | Exact copy of a design asset; user upload is not dead source | Preserve local file; ignore in Git | Keep / tested scoped change |
| `README.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `routes/web.php` | Dynamic route registry | Runtime dependency/route | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `skill_UIUX.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `skill_user.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `skill_web.md` | Shared team documentation | Shared team reference/evidence | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/booking_flow_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/browser_audit.ps1` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/codebase_cleanup_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/db_outage_audit.ps1` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/fresh_setup_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/http_audit_fixture.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/http_audit.ps1` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/http_smoke.ps1` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/procedure_flow_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/schema_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/source_audit_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |
| `tests/stored_procedure_audit_test.php` | Regression test/fixture | Opt-in regression test/fixture | No confirmed removal candidate | Keep | Keep / tested scoped change |

## Dependency/dead-code findings

- Removed only hidden input `amenities_present`: full-project search found only its definition, no controller/JS/routine consumer; amenities[] handling and empty-selection behavior remain intact. Actual Host form/upload/rollback tests cover the affected route.
- Duplicate roles validation replaced with one private Admin validator for create/edit/role update. Invalid scalar/nested/unknown/oversized input is checked before mutation; passwords never enter old input.
- Public `Booking::findForUser`, `Database::reset`, global `old()` remain REVIEW_REQUIRED. Dynamic/external consumers cannot be ruled out by reference search alone.
- No runtime debug dump/console.log/mysqli or request-time CREATE/ALTER/DROP table/database found. Legitimate redirect/json/CLI `exit` is retained.
- No route/view/controller/asset was deleted. Router dispatch is dynamic; 40 actual handlers passed autoload/public visibility checks. All 18 views/layouts/error templates remain.
- SHA-256 identified a design PNG duplicated into a runtime upload. It is not duplicate PHP/source and is preserved. No material file removed; no recovery action needed.
- Admin dashboard's five stat CALLs and defensive overlapping SQL guards are REVIEW_REQUIRED optimizations, not removal candidates without measured performance and correctness evidence. No speculative cache/batch redesign introduced.

## Routine inventory and dependency closure

81 live procedures: 56 called by runtime PHP, 3 consumed internally, 4 CLI/demo-seed routines, 14 test routines and 4 legacy compatibility routines tested but not used by app runtime. Counts are mutually exclusive by primary consumer. All 81 have a known source/consumer; this does not rule out external SQL/team consumers. No routine created or dropped during this cleanup.

Internal edges: sp_booking_quote/create → sp_booking_validate; sp_user_update_profile → DST_sp_update_profile; sp_admin_audit_add/listing_update/listing_visibility/listing_soft_delete → sp_admin_assert_role. Source upgrades intentionally redefine 8 legacy names (4 repairs, 4 projection extensions) in ordered scripts; these are not competing implementations to delete.

| Procedure | Primary consumer | Signature arguments | Known references / internal parents | Action |
|---|---|---|---|---|
| `DST_ sp_list_amenities` | Runtime PHP | 0 | app/Models/Listing.php, tests/stored_procedure_audit_test.php | Retain |
| `DST_sp_get_user_profile` | Test/compatibility coverage | 1 | tests/procedure_flow_test.php | Retain legacy; REVIEW_REQUIRED before any removal |
| `DST_sp_list_property_types` | Runtime PHP | 0 | app/Models/Listing.php | Retain |
| `DST_sp_update_profile` | Routine dependency | 4 | routine:sp_user_update_profile | Retain |
| `LTP_sp_get_listing_amenities` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `LTP_sp_get_listing_photos` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `LTP_sp_list_cancellation_policies` | Runtime PHP | 0 | app/Models/Listing.php, tests/stored_procedure_audit_test.php | Retain |
| `LTP_sp_search_listings_by_city` | Test/compatibility coverage | 1 | tests/stored_procedure_audit_test.php | Retain legacy; REVIEW_REQUIRED before any removal |
| `NMT_sp_add_wishlist_item` | Runtime PHP | 2 | app/Models/Wishlist.php | Retain |
| `NMT_sp_get_user_notifications` | Test/compatibility coverage | 1 | tests/stored_procedure_audit_test.php | Retain legacy; REVIEW_REQUIRED before any removal |
| `NMT_sp_mark_notification_read` | Test/compatibility coverage | 2 | tests/stored_procedure_audit_test.php | Retain legacy; REVIEW_REQUIRED before any removal |
| `NMT_sp_remove_wishlist_item` | Runtime PHP | 2 | app/Models/Wishlist.php | Retain |
| `NTK_sp_get_guest_bookings` | Runtime PHP | 1 | app/Models/Booking.php | Retain |
| `NTK_sp_get_host_bookings` | Runtime PHP | 1 | app/Models/Booking.php | Retain |
| `NTK_sp_get_listing_reviews` | Runtime PHP | 1 | app/Models/Listing.php, tests/schema_test.php | Retain |
| `NTK_sp_get_wishlist` | Runtime PHP | 1 | app/Models/Wishlist.php | Retain |
| `sp_admin_assert_role` | Routine dependency | 1 | routine:sp_admin_audit_add, routine:sp_admin_listing_soft_delete, routine:sp_admin_listing_update, routine:sp_admin_listing_visibility | Retain |
| `sp_admin_audit_add` | Runtime PHP | 5 | app/Models/AdminRepository.php | Retain |
| `sp_admin_count_bookings` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_admin_count_hosts` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_admin_count_listings` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_admin_count_users` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listings` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listing_find` | Runtime PHP | 1 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listing_moderate` | Runtime PHP | 4 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listing_soft_delete` | Runtime PHP | 2 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listing_update` | Runtime PHP | 9 | app/Models/AdminRepository.php | Retain |
| `sp_admin_listing_visibility` | Runtime PHP | 3 | app/Models/AdminRepository.php, tests/procedure_flow_test.php | Retain |
| `sp_admin_total_revenue` | Runtime PHP | 0 | app/Models/AdminRepository.php | Retain |
| `sp_booking_cancel` | Runtime PHP | 3 | app/Services/BookingService.php | Retain |
| `sp_booking_create` | Runtime PHP | 5 | app/Services/BookingService.php | Retain |
| `sp_booking_find_for_user` | Runtime PHP | 3 | app/Models/Booking.php, tests/procedure_flow_test.php | Retain |
| `sp_booking_get_all` | Runtime PHP | 0 | app/Models/Booking.php | Retain |
| `sp_booking_quote` | Runtime PHP | 4 | app/Services/BookingService.php | Retain |
| `sp_booking_transition` | Runtime PHP | 4 | app/Services/BookingService.php, tests/procedure_flow_test.php | Retain |
| `sp_booking_validate` | Routine dependency | 4 | routine:sp_booking_create, routine:sp_booking_quote | Retain |
| `sp_demo_listing_lock` | CLI seed | 3 | database/seeds/seed_listing_images.php | Retain |
| `sp_demo_photo_insert` | CLI seed | 4 | database/seeds/seed_listing_images.php | Retain |
| `sp_demo_photo_orders` | CLI seed | 1 | database/seeds/seed_listing_images.php | Retain |
| `sp_demo_photo_paths` | CLI seed | 1 | database/seeds/seed_listing_images.php, tests/http_audit_fixture.php | Retain |
| `sp_host_stats` | Runtime PHP | 1 | app/Models/HostRepository.php | Retain |
| `sp_listing_amenities_replace` | Runtime PHP | 3 | app/Models/Listing.php, tests/procedure_flow_test.php | Retain |
| `sp_listing_amenity_ids` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `sp_listing_availability_get` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `sp_listing_availability_set` | Runtime PHP | 5 | app/Models/Listing.php | Retain |
| `sp_listing_create` | Runtime PHP | 18 | app/Models/Listing.php | Retain |
| `sp_listing_get_by_host` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `sp_listing_get_owned` | Runtime PHP | 2 | app/Models/Listing.php | Retain |
| `sp_listing_get_public` | Runtime PHP | 1 | app/Models/Listing.php | Retain |
| `sp_listing_photo_add` | Runtime PHP | 4 | app/Models/Listing.php | Retain |
| `sp_listing_search` | Runtime PHP | 11 | app/Models/Listing.php | Retain |
| `sp_listing_set_visible` | Runtime PHP | 3 | app/Models/Listing.php | Retain |
| `sp_listing_update` | Runtime PHP | 19 | app/Models/Listing.php | Retain |
| `sp_review_create` | Runtime PHP | 4 | app/Models/Review.php, tests/schema_test.php | Retain |
| `sp_test_booking_ids` | Test/compatibility coverage | 1 | tests/http_audit_fixture.php | Retain |
| `sp_test_booking_inspect` | Test/compatibility coverage | 1 | tests/booking_flow_test.php, tests/procedure_flow_test.php, tests/source_audit_test.php | Retain |
| `sp_test_cleanup_booking` | Test/compatibility coverage | 1 | tests/booking_flow_test.php, tests/http_audit_fixture.php, tests/procedure_flow_test.php, tests/source_audit_test.php | Retain |
| `sp_test_cleanup_listing` | Test/compatibility coverage | 1 | tests/http_audit_fixture.php, tests/procedure_flow_test.php, tests/source_audit_test.php | Retain |
| `sp_test_cleanup_user` | Test/compatibility coverage | 1 | tests/http_audit_fixture.php, tests/procedure_flow_test.php, tests/source_audit_test.php | Retain |
| `sp_test_historical_booking` | Test/compatibility coverage | 2 | tests/schema_test.php | Retain |
| `sp_test_listing_approve` | Test/compatibility coverage | 1 | tests/procedure_flow_test.php, tests/source_audit_test.php | Retain |
| `sp_test_listing_ids` | Test/compatibility coverage | 2 | tests/http_audit_fixture.php | Retain |
| `sp_test_listing_inspect` | Test/compatibility coverage | 1 | tests/http_audit_fixture.php | Retain |
| `sp_test_local_photos` | Test/compatibility coverage | 0 | tests/source_audit_test.php | Retain |
| `sp_test_moderation_actions` | Test/compatibility coverage | 1 | tests/source_audit_test.php | Retain |
| `sp_test_orphan_photos` | Test/compatibility coverage | 0 | tests/source_audit_test.php | Retain |
| `sp_test_seed_counts` | Test/compatibility coverage | 0 | tests/schema_test.php | Retain |
| `sp_test_user_id` | Test/compatibility coverage | 1 | tests/http_audit_fixture.php | Retain |
| `sp_user_admin_update` | Runtime PHP | 5 | app/Models/User.php | Retain |
| `sp_user_get_by_email` | Runtime PHP | 1 | app/Models/User.php, tests/procedure_flow_test.php, tests/schema_test.php, tests/stored_procedure_audit_test.php | Retain |
| `sp_user_get_for_admin` | Runtime PHP | 1 | app/Models/User.php | Retain |
| `sp_user_get_with_roles` | Runtime PHP | 1 | app/Models/User.php | Retain |
| `sp_user_grant_host` | Runtime PHP | 1 | app/Models/User.php | Retain |
| `sp_user_insert` | Runtime PHP | 4 | app/Models/User.php | Retain |
| `sp_user_roles_replace` | Runtime PHP | 2 | app/Models/User.php, tests/procedure_flow_test.php | Retain |
| `sp_user_search` | Runtime PHP | 1 | app/Models/User.php | Retain |
| `sp_user_set_status` | Runtime PHP | 2 | app/Models/User.php | Retain |
| `sp_user_soft_delete` | Runtime PHP | 1 | app/Models/User.php | Retain |
| `sp_user_update_profile` | Runtime PHP | 4 | app/Models/User.php | Retain |
| `sp_wishlist_contains` | Runtime PHP | 2 | app/Models/Wishlist.php | Retain |
| `sp_wishlist_ids` | Runtime PHP | 1 | app/Models/Wishlist.php | Retain |

The 10 names in historical 040 migration are absent in the live catalog and superseded by atomic operations; external history is not assumed safe. Script retained, default execution removed, no DROP issued. See [database consistency](database-consistency-audit.md) and [final report](codebase-cleanup-report.md).
