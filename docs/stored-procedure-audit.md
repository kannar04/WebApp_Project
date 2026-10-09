# Stored Procedure audit — Home2Home

Ngày kiểm tra: 2026-10-09. Trạng thái: **COMPLIANT trong phạm vi SQL nghiệp vụ PHP**, đã kiểm thử runtime; không phải chứng nhận production.

## Kết quả

- Trước refactor: **108 vị trí thực thi SQL nghiệp vụ trực tiếp**. Cách đếm: mỗi vị trí `prepare/query` là một site, kể cả query động hoặc cleanup chạy trong vòng lặp; không đếm từng lần thực thi.
- Phân bổ: 76 sites trong Model/Repository/BookingService; 4 trong image seeder; 26 trong test/HTTP fixtures; 2 kiểm tra seed trong schema test. Inventory ban đầu cũng ghi nhận 102 SQL string literals, nhưng đây là thước đo khác vì query builder có fragment và query nội suy.
- Sau refactor: **0 SELECT/INSERT/UPDATE/DELETE/REPLACE nghiệp vụ trực tiếp trong PHP**. 106 CALL literals được đối chiếu với 78 signatures trong database. Gateway từ chối mọi câu không phải CALL.
- Database thực tế: MariaDB **10.4.32**, XAMPP, `db_home2home`, 22 bảng. Không ALTER/drop/reset bảng, không thay credential/grants.
- Giữ đủ 16 routines của nhóm; tái sử dụng 12 routines trong runtime, 4 routines còn lại cũng được giữ và kiểm tra tương thích.
- Có **51 routines mới cho application/CLI**, tổng cộng 67 production definitions riêng biệt; 14 routines test riêng, chỉ cài bằng `--include-tests`. Local hiện có 81 routines. 89 definition executions khi cài cả test vì migration nâng cấp các routines cũ, không phải 89 tên khác nhau.
- Đã bỏ đúng 10 helper tạm **do refactor này tạo ra**, khi chuyển sang workflow nguyên tử. Không bỏ routine nào trong catalog gốc.

## Tài liệu và phạm vi đã đối chiếu

`skill_user.md`, `skill_web.md`, `skill_UIUX.md`, `File_Ideas/Functions.txt`, ERD và sơ đồ lớp/chức năng trong project; routes, toàn bộ PHP inventory, SQL schema và catalog thật. Giữ PHP OOP + MVC, PDO, Bootstrap 4.6.2, JSON response và session/CSRF hiện tại.

Những thay đổi đã có trong working tree trước khi bắt đầu được giữ lại. Không commit, push, reset hoặc untrack file.

## Mapping vi phạm trước đây → kết quả hiện tại

Query column mô tả nhóm SQL cũ; số site là số vị trí chuẩn bị/thực thi trong method, không phải số request. Controller/View trước đây không có business DML trực tiếp.

| File | Method | Query cũ / sites | Procedure thay thế | Trạng thái |
|---|---|---|---|---|
| `app/Models/User.php` | `findByEmail` | SELECT; 1 | `sp_user_get_by_email` | COMPLIANT |
| `app/Models/User.php` | `findWithRoles` | SELECT; 1 | `sp_user_get_with_roles` | COMPLIANT |
| `app/Models/User.php` | `findForAdmin` | SELECT; 1 | `sp_user_get_for_admin` | COMPLIANT |
| `app/Models/User.php` | `create` | INSERT; 2 | `sp_user_insert` | COMPLIANT |
| `app/Models/User.php` | `updateProfile` | UPDATE; 1 | `sp_user_update_profile` | COMPLIANT |
| `app/Models/User.php` | `grantHost` | INSERT; 1 | `sp_user_grant_host` | COMPLIANT |
| `app/Models/User.php` | `all` | SELECT; 1 | `sp_user_search` | COMPLIANT |
| `app/Models/User.php` | `setStatus` | UPDATE; 1 | `sp_user_set_status` | COMPLIANT |
| `app/Models/User.php` | `adminUpdate` | UPDATE; 1 | `sp_user_admin_update` | COMPLIANT |
| `app/Models/User.php` | `softDelete` | UPDATE; 1 | `sp_user_soft_delete` | COMPLIANT |
| `app/Models/User.php` | `replaceRoles` | DELETE/INSERT; 2 | `sp_user_roles_replace` | COMPLIANT |
| `app/Models/Listing.php` | `search` | SELECT; 1 | `sp_listing_search` | COMPLIANT |
| `app/Models/Listing.php` | `findPublic` | SELECT; 1 | `sp_listing_get_public` | COMPLIANT |
| `app/Models/Listing.php` | `findOwned` | SELECT; 1 | `sp_listing_get_owned` | COMPLIANT |
| `app/Models/Listing.php` | `hostListings` | SELECT; 1 | `sp_listing_get_by_host` | COMPLIANT |
| `app/Models/Listing.php` | `create` | INSERT; 1 | `sp_listing_create` | COMPLIANT |
| `app/Models/Listing.php` | `update` | UPDATE; 1 | `sp_listing_update` | COMPLIANT |
| `app/Models/Listing.php` | `addPhoto` | INSERT; 1 | `sp_listing_photo_add` | COMPLIANT |
| `app/Models/Listing.php` | `setAmenities` | DELETE/INSERT; 2 | `sp_listing_amenities_replace` | COMPLIANT |
| `app/Models/Listing.php` | `setVisible` | UPDATE; 1 | `sp_listing_set_visible` | COMPLIANT |
| `app/Models/Listing.php` | `setAvailability` | SELECT/INSERT; 3 | `sp_listing_availability_set` | COMPLIANT |
| `app/Models/Listing.php` | `availability` | SELECT; 1 | `sp_listing_availability_get` | COMPLIANT |
| `app/Models/Listing.php` | `types` | SELECT; 1 | `DST_sp_list_property_types` | COMPLIANT |
| `app/Models/Listing.php` | `policies` | SELECT; 1 | `LTP_sp_list_cancellation_policies` | COMPLIANT |
| `app/Models/Listing.php` | `allAmenities` | SELECT; 1 | `DST_ sp_list_amenities` | COMPLIANT |
| `app/Models/Listing.php` | `amenityIds` | SELECT; 1 | `sp_listing_amenity_ids` | COMPLIANT |
| `app/Models/Listing.php` | `photos` | SELECT; 1 | `LTP_sp_get_listing_photos` | COMPLIANT |
| `app/Models/Listing.php` | `amenities` | SELECT; 1 | `LTP_sp_get_listing_amenities` | COMPLIANT |
| `app/Models/Listing.php` | `reviews` | SELECT; 1 | `NTK_sp_get_listing_reviews` | COMPLIANT |
| `app/Models/Booking.php` | `forGuest` | SELECT; 1 | `NTK_sp_get_guest_bookings` | COMPLIANT |
| `app/Models/Booking.php` | `forHost` | SELECT; 1 | `NTK_sp_get_host_bookings` | COMPLIANT |
| `app/Models/Booking.php` | `findForUser` | SELECT; 1 | `sp_booking_find_for_user` | COMPLIANT |
| `app/Models/Booking.php` | `all` | SELECT; 1 | `sp_booking_get_all` | COMPLIANT |
| `app/Models/AdminRepository.php` | `stats` | SELECT; 5 | `sp_admin_count_users`, `sp_admin_count_hosts`, `sp_admin_count_listings`, `sp_admin_count_bookings`, `sp_admin_total_revenue` | COMPLIANT |
| `app/Models/AdminRepository.php` | `listings` | SELECT; 1 | `sp_admin_listings` | COMPLIANT |
| `app/Models/AdminRepository.php` | `moderate` | UPDATE/INSERT; 2 | `sp_admin_listing_moderate` | COMPLIANT |
| `app/Models/AdminRepository.php` | `audit` | INSERT; 1 | `sp_admin_audit_add` | COMPLIANT |
| `app/Models/AdminRepository.php` | `findListing` | SELECT; 1 | `sp_admin_listing_find` | COMPLIANT |
| `app/Models/AdminRepository.php` | `updateListing` | UPDATE; 1 | `sp_admin_listing_update` | COMPLIANT |
| `app/Models/AdminRepository.php` | `setListingVisibility` | UPDATE; 1 | `sp_admin_listing_visibility` | COMPLIANT |
| `app/Models/AdminRepository.php` | `softDeleteListing` | UPDATE; 1 | `sp_admin_listing_soft_delete` | COMPLIANT |
| `app/Models/HostRepository.php` | `stats` | SELECT; 1 | `sp_host_stats` | COMPLIANT |
| `app/Models/Wishlist.php` | `toggle` | SELECT/DELETE/INSERT; 3 | `sp_wishlist_contains`, `NMT_sp_remove_wishlist_item`, `NMT_sp_add_wishlist_item` | COMPLIANT |
| `app/Models/Wishlist.php` | `ids` | SELECT; 1 | `sp_wishlist_ids` | COMPLIANT |
| `app/Models/Wishlist.php` | `all` | SELECT; 1 | `NTK_sp_get_wishlist` | COMPLIANT |
| `app/Models/Review.php` | `create` | INSERT; 1 | `sp_review_create` | COMPLIANT |
| `app/Services/BookingService.php` | `create` | INSERT; 2 | `sp_booking_create` | COMPLIANT |
| `app/Services/BookingService.php` | `hostTransition` | SELECT/UPDATE; 3 | `sp_booking_transition` | COMPLIANT |
| `app/Services/BookingService.php` | `cancelByGuest` | UPDATE/INSERT; 2 | `sp_booking_cancel` | COMPLIANT |
| `app/Services/BookingService.php` | `adminTransition` | SELECT/UPDATE; 3 | `sp_booking_transition` | COMPLIANT |
| `app/Services/BookingService.php` | `validateRequest` | SELECT; 3 | `sp_booking_validate / sp_booking_quote / sp_booking_create` | COMPLIANT |
| `app/Services/BookingService.php` | `lockBooking` | SELECT; 3 | `sp_booking_create / sp_booking_transition / sp_booking_cancel` | COMPLIANT |
| `app/Services/BookingService.php` | `event` | INSERT; 1 | `sp_booking_create / sp_booking_transition / sp_booking_cancel` | COMPLIANT |
| `app/Services/BookingService.php` | `notify` | INSERT; 1 | `sp_booking_create / sp_booking_transition / sp_booking_cancel` | COMPLIANT |
| `database/seeds/seed_listing_images.php` | CLI seeder | SELECT/INSERT; 4 | `sp_demo_listing_lock`, `sp_demo_photo_paths`, `sp_demo_photo_orders`, `sp_demo_photo_insert` | COMPLIANT |
| `tests/source_audit_test.php` | Fixtures / assertions | SELECT/UPDATE/DELETE; 11 | `sp_test_*` trong testing.sql | COMPLIANT |
| `tests/http_audit_fixture.php` | inspect / cleanup | SELECT/DELETE, table-name interpolation; 8 | `sp_test_*`; fixed CALL, không còn table-name interpolation | COMPLIANT |
| `tests/booking_flow_test.php` | Lifecycle / cleanup | SELECT/DELETE, ID/table interpolation; 7 | `sp_test_booking_inspect`, `sp_test_cleanup_booking` | COMPLIANT |
| `tests/schema_test.php` | Seed assertions | SELECT counts; 2 | `sp_test_seed_counts` | COMPLIANT |

Các methods facade mới gọi `transition()` hoặc routine workflow thay cho các helper `event/notify/lockBooking/validateRequest` cũ. `adminCreate()` và Controller coordinate các CALL liên quan trong một transaction khi cần; không có SQL bảng trong PHP.

## Routines hiện hữu

| Routine gốc | Xử lý |
|---|---|
| `DST_ sp_list_amenities` | Tái sử dụng tên có khoảng trắng, CALL dùng backticks; không đổi tên |
| `DST_sp_list_property_types` | Tái sử dụng |
| `DST_sp_update_profile` | Tái sử dụng bên trong wrapper khóa user, giữ avatar cũ khi request không upload ảnh |
| `DST_sp_get_user_profile` | Giữ nguyên; kiểm tra profile/NULL avatar bằng routine này |
| `LTP_sp_list_cancellation_policies` | Tái sử dụng |
| `LTP_sp_get_listing_photos`, `LTP_sp_get_listing_amenities` | Tái sử dụng public/active-host predicates |
| `LTP_sp_search_listings_by_city` | Giữ exact-city behavior, sửa collation tham số; không thay search nhiều bộ lọc bằng routine này vì thiếu date/capacity/price/type/sort/pagination/address/title predicates |
| `NMT_sp_add_wishlist_item`, `NMT_sp_remove_wishlist_item` | Tái sử dụng; sửa identifier tham số có khoảng trắng cuối |
| `NMT_sp_get_user_notifications`, `NMT_sp_mark_notification_read` | Giữ và test; sửa khoảng trắng cuối của notification parameter; chưa thêm UI notification mới |
| `NTK_sp_get_guest_bookings`, `NTK_sp_get_host_bookings` | Giữ tên/arity, mở rộng projection thêm fields View đang cần; giữ active-user/host-role predicates |
| `NTK_sp_get_listing_reviews` | Giữ tên/arity, thêm guest_name/host_response và public predicates; sửa JOIN alias trong regression test |
| `NTK_sp_get_wishlist` | Giữ tên/arity, thêm image/type/price aliases; giữ active-user/host + public predicates |

Không tạo tên mới trùng chức năng của routine catalog phù hợp. Projection extensions không bỏ field cũ. `001_legacy.sql` cài routine thiếu bằng IF NOT EXISTS; các repairs/extensions được quản lý bằng migration OR REPLACE, giữ tên, vị trí tham số và các output cũ.

## Danh sách 51 routines mới

| Routine | SQL source |
|---|---|
| `sp_user_get_by_email` | `database/procedures/010_models.sql` |
| `sp_user_get_with_roles` | `database/procedures/010_models.sql` |
| `sp_user_get_for_admin` | `database/procedures/010_models.sql` |
| `sp_user_grant_host` | `database/procedures/010_models.sql` |
| `sp_user_set_status` | `database/procedures/010_models.sql` |
| `sp_user_soft_delete` | `database/procedures/010_models.sql` |
| `sp_listing_get_public` | `database/procedures/010_models.sql` |
| `sp_listing_get_owned` | `database/procedures/010_models.sql` |
| `sp_listing_get_by_host` | `database/procedures/010_models.sql` |
| `sp_listing_create` | `database/procedures/010_models.sql` |
| `sp_listing_update` | `database/procedures/010_models.sql` |
| `sp_listing_set_visible` | `database/procedures/010_models.sql` |
| `sp_listing_availability_get` | `database/procedures/010_models.sql` |
| `sp_listing_amenity_ids` | `database/procedures/010_models.sql` |
| `sp_booking_get_all` | `database/procedures/010_models.sql` |
| `sp_admin_count_users` | `database/procedures/010_models.sql` |
| `sp_admin_count_hosts` | `database/procedures/010_models.sql` |
| `sp_admin_count_listings` | `database/procedures/010_models.sql` |
| `sp_admin_count_bookings` | `database/procedures/010_models.sql` |
| `sp_admin_total_revenue` | `database/procedures/010_models.sql` |
| `sp_admin_listings` | `database/procedures/010_models.sql` |
| `sp_admin_audit_add` | `database/procedures/010_models.sql` |
| `sp_admin_listing_find` | `database/procedures/010_models.sql` |
| `sp_host_stats` | `database/procedures/010_models.sql` |
| `sp_wishlist_contains` | `database/procedures/010_models.sql` |
| `sp_wishlist_ids` | `database/procedures/010_models.sql` |
| `sp_review_create` | `database/procedures/010_models.sql` |
| `sp_demo_listing_lock` | `database/procedures/010_models.sql` |
| `sp_demo_photo_paths` | `database/procedures/010_models.sql` |
| `sp_demo_photo_orders` | `database/procedures/010_models.sql` |
| `sp_demo_photo_insert` | `database/procedures/010_models.sql` |
| `sp_user_search` | `database/procedures/011_queries.sql` |
| `sp_user_admin_update` | `database/procedures/011_queries.sql` |
| `sp_booking_find_for_user` | `database/procedures/011_queries.sql` |
| `sp_listing_search` | `database/procedures/011_queries.sql` |
| `sp_booking_validate` | `database/procedures/020_booking.sql` |
| `sp_booking_quote` | `database/procedures/020_booking.sql` |
| `sp_booking_create` | `database/procedures/020_booking.sql` |
| `sp_booking_transition` | `database/procedures/020_booking.sql` |
| `sp_booking_cancel` | `database/procedures/020_booking.sql` |
| `sp_user_insert` | `database/procedures/030_atomic_writes.sql` |
| `sp_user_roles_replace` | `database/procedures/030_atomic_writes.sql` |
| `sp_user_update_profile` | `database/procedures/030_atomic_writes.sql` |
| `sp_listing_amenities_replace` | `database/procedures/030_atomic_writes.sql` |
| `sp_listing_photo_add` | `database/procedures/030_atomic_writes.sql` |
| `sp_listing_availability_set` | `database/procedures/030_atomic_writes.sql` |
| `sp_admin_listing_moderate` | `database/procedures/030_atomic_writes.sql` |
| `sp_admin_assert_role` | `database/procedures/030_atomic_writes.sql` |
| `sp_admin_listing_update` | `database/procedures/030_atomic_writes.sql` |
| `sp_admin_listing_visibility` | `database/procedures/030_atomic_writes.sql` |
| `sp_admin_listing_soft_delete` | `database/procedures/030_atomic_writes.sql` |

## Transaction và dataflow

`Browser → CSRF/auth/role + validation → Controller → Model/Service → PDO CALL → routine → tables → drained result → View/JSON`.

- Create booking: lock listing → current locking overlap/availability reads → validate → price/policy snapshot → booking + booking_nights + event + notification → commit.
- Host/Admin transition: lookup listing → lock listing → lock booking → actor/role/state/date/calendar checks → status + event + notification (+ Admin audit) → commit.
- Guest cancellation: lock listing → lock booking → owner/state checks → snapshot timezone/cutoff/fee refund → cancellation + status/event/notification → commit.
- Calendar writes lock the same listing and cannot block a confirmed night.
- User create/default role, role replacement, amenities replacement, photo ordering, moderation and Admin listing CRUD use atomic workflows.
- If a caller transaction exists, routines use named savepoints, roll back their own work on failure and **never commit the caller's transaction**. Controller-level coordination of multiple CALLs shares the exact same PDO connection.
- Parameters use BIGINT UNSIGNED, INT, DECIMAL, DATE, TIME, BOOL and bounded strings/TEXT as appropriate. Text comparisons explicitly use the existing utf8mb4_unicode_ci collation rather than ALTER TABLE.
- SQL contains no user-supplied identifiers or dynamic SQL. Listing sorting is static CASE; PHP binds null/int/bool/string, with decimal inputs bound as strings.
- Adapter materializes results, drains all nextRowset results and closes the cursor in finally. Mutation metadata avoids relying on PDO lastInsertId after CALL.
- Business SIGNAL 45000 becomes DomainException; controllers show safe messages. Unexpected SQL errors stay out of HTML/JSON.
- PHP still hashes passwords, authorizes the session and validates CSRF/input. Routine EXECUTE does not replace these controls.

Transaction behavior was checked against [MariaDB START TRANSACTION](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction) and [SAVEPOINT](https://mariadb.com/docs/server/reference/sql-statements/transactions/savepoint), then verified on the installed server.

## REVIEW_REQUIRED exceptions

| PHP file | Allowed SQL purpose |
|---|---|
| `database/import.php` | CLI schema/seed SQL script loader, not HTTP business logic |
| `database/install_procedures.php`, `database/procedures/install.php` | Local CLI routine DDL migration loader; explicit pruning only of newly created superseded helpers |
| `database/tools/routine_catalog.php` | Read-only information_schema routine/parameter/column metadata |
| `database/tools/routine_inventory.php` | Token-based source inventory; executes no business SQL |
| `tests/stored_procedure_audit_test.php` | Read-only information_schema signature audit |
| `tests/schema_test.php` | Schema/seed installation and metadata in a unique isolated database; drops only the database this run created |

Business seed data/DDL lives in SQL files. These exceptions do not permit adding table queries back to a Controller, Model, Service, endpoint or test fixture.

## Files changed in this request

- All seven Models/Repositories listed in the mapping, `app/Services/BookingService.php`.
- `core/ProcedureConnection.php`, `core/ProcedureStatement.php`.
- `app/Controllers/AdminController.php`, `app/Controllers/HostController.php`; Admin listing/user forms preserve non-sensitive old input.
- `database/import.php`, `database/install_procedures.php`, procedure SQL/loader, metadata/inventory tools, image seeder.
- Existing booking/schema/source/HTTP/browser tests and two new tests: `procedure_flow_test.php`, `stored_procedure_audit_test.php`.
- `skill_web.md`, setup/progress/UX/test reports. Existing unrelated changes are not attributed to this request.

SQL scripts: `001_legacy.sql`, `005_legacy_repairs.sql`, `010_models.sql`, `011_queries.sql`, `020_booking.sql`, `030_atomic_writes.sql`, `040_prune_transition_helpers.sql`, `testing.sql`.

## Verification results

| Check actually run | Result / evidence |
|---|---|
| PHP lint | PASS — 63 PHP files |
| Business SQL scan + live signatures | PASS — 0 direct business SQL; 106 CALL sites / 78 signatures |
| Consecutive CALLs / partially consumed caller data | PASS — 100 sequential routines, no pending-result error |
| Fresh schema + repeated seed | PASS — 22 tables; routines reinstall; isolated database cleanup |
| Completed-stay review / duplicate / ownership / projection | PASS — isolated historical fixture, no live data backdating |
| Booking lifecycle, snapshots, fee refund, events/notifications, Admin transition | PASS — booking_flow_test.php + source_audit_test.php |
| Two independent PHP processes with stale snapshots | PASS — one overlapping booking created, one rejected; no duplicate |
| Direct routine role/ownership/NULL-admin/invalid-admin guards | PASS — procedure_flow_test.php |
| Routine rollback / outer caller rollback | PASS — roles, amenities, booking savepoint assertions |
| Profile avatar preservation, account status/soft-delete | PASS — procedure_flow_test.php |
| Admin listing CRUD / visibility / audit and immutable booking price | PASS — procedure_flow_test.php |
| Real HTTP user journeys / upload / booking / bad-input recovery | PASS — http_audit.ps1 |
| HTTP smoke + controlled DB outage | PASS — http_smoke.ps1, db_outage_audit.ps1 |
| Chrome responsive + JS/CSS + live/stale/recovered quotes + favorite | PASS — browser_audit.ps1; 48 viewport/page checks + login at 375/768/1024/1440 |
| Image seeder idempotence | PASS — dry-run and apply both 0 inserted / 5 skipped |
| Large load, deadlock recovery stress, screen reader/full keyboard | NOT_VERIFIED |
| Production EXECUTE-only user and non-Vietnam timezone tables | NOT_VERIFIED — grants/server timezone tables were not changed |

Local test runs cleaned their own generated IDs and validated upload paths. Final live counts: 3 users, 3 listings, 10 photos (5 original + 5 previous demo images), 0 bookings, 0 audit/http fixture users. `public/uploads` contains only .gitkeep. No existing user data, source, or original routine was removed.

## Remaining scope / limitations

No reproduced P0–P2 remains in the tested implemented journeys. This is a local academic/demo verification, not a production security/load certification.

- Routines target the **actual MariaDB 10.4** installation (`OR REPLACE`, `@@in_transaction`); deployment to Oracle MySQL needs a compatibility migration, not an untested claim of portability.
- Vietnam timezone is resolved with fixed UTC+07 where XAMPP named-zone tables are absent. Other named zones require timezone data; cancellation safely fails rather than guessing if the zone cannot be resolved.
- Configure a separate production web DB user with EXECUTE on runtime routines only. Do **not** grant it test/seed/migration access or use root. No shared grants were changed here.
- Test-only routines are opt-in. Historical fixture creation additionally requires the exact isolated schema-test database naming pattern.
- Reset-password/email delivery/payments/maps and other features without current routes are not implemented by this task. High-load testing and full accessibility remain NOT_VERIFIED.

## Run / reproduce

Existing local database: install routines only, without importing/reseeding tables:

```powershell
C:\xampp\php\php.exe database\install_procedures.php
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public/router.php
```

Open http://127.0.0.1:8090/. MySQL must be running in XAMPP and local .env configured. For fixture tests install opt-in helpers:

```powershell
C:\xampp\php\php.exe database\install_procedures.php --include-tests
C:\xampp\php\php.exe tests\stored_procedure_audit_test.php
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
C:\xampp\php\php.exe tests\source_audit_test.php
C:\xampp\php\php.exe tests\procedure_flow_test.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests\http_audit.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests\browser_audit.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tests\db_outage_audit.ps1
```
