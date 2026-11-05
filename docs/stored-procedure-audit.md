# Stored Procedure audit — Home2Home

Ngày kiểm tra: 2026-10-09. Trạng thái: **COMPLIANT trong phạm vi SQL nghiệp vụ PHP**, đã kiểm thử runtime; không phải chứng nhận production.

Cập nhật 2026-10-13: routine của 5 chức năng Nice-to-have (53 mới, 4 đổi tham số) ở mục [Nice-to-have routines — 2026-10-13](#nice-to-have-routines--2026-10-13).

Cleanup follow-up: [codebase-cleanup-report.md](codebase-cleanup-report.md). Số liệu refactor bên dưới là lịch sử; installer hiện không chạy migration DROP `040` tự động, schema khởi tạo đã khớp DDL 22 bảng, và các số đếm dữ liệu cũ không phải invariant của app đang sử dụng.

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
| `app/Models/User.php` | `hostRoleRemoved` | SELECT; 1 | `sp_user_host_role_removed` | COMPLIANT |
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
| `app/Models/Listing.php` | `catalogChoices` | SELECT; 1 | `sp_listing_catalog_choices` | COMPLIANT |
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
| `sp_user_host_role_removed` | `database/procedures/010_models.sql` |
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

## Routine lõi chất lượng, khôi phục mật khẩu và khuyến mãi

Các routine production này có từ trước nhưng chưa được ghi trong các bảng khác của tài liệu. Không đổi code; `tests/stored_procedure_audit_test.php` nay đối chiếu tên trong tài liệu với các file SQL.

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_host_listing_delete` | `050_quality_core.sql` | Host xóa mềm và ẩn tin của mình; từ chối khi còn booking `pending` hoặc `confirmed` | Transaction/savepoint; khóa listing → booking; chủ tin (`sp_quality_assert_host`) | procedure_flow_test, quality_core_test |
| `sp_host_photos_manage` | `050` | Sắp xếp lại và gỡ ảnh theo danh sách id; từ chối khi danh sách ảnh đã đổi so với lúc mở form (`p_expected`); chỉ gỡ tham chiếu, không xóa tệp | Transaction/savepoint; khóa listing; chủ tin | quality_core_test |
| `sp_booking_detail_nights` | `050` | Giá từng đêm cho trang chi tiết booking | Chỉ đọc; Guest của booking, Host của tin hoặc Admin (`sp_quality_assert_booking`) | quality_core_test, feature_payment_test |
| `sp_booking_detail_events` | `050` | Lịch sử trạng thái cho trang chi tiết booking | Chỉ đọc; cùng quyền `sp_quality_assert_booking` | quality_core_test, feature_payment_test |
| `sp_listing_photos_for_actor` | `050` | Ảnh của tin cho form quản lý và trang xem trước, kể cả tin chưa công khai | Chỉ đọc; chủ tin có vai trò Host hoặc Admin, tài khoản active | quality_core_test |
| `sp_listing_admin_preview` | `050` | Dữ liệu trang xem trước của Admin, kể cả tin chưa duyệt | Chỉ đọc; Admin (`sp_admin_assert_role`) | quality_core_test |
| `sp_listing_amenities_for_admin` | `050` | Tiện nghi của tin trong trang xem trước của Admin | Chỉ đọc; Admin | quality_core_test |
| `sp_admin_count_guests` | `050` | Đếm tài khoản có vai trò Guest chưa xóa cho thống kê Admin | Chỉ đọc, không tham số; quyền Admin kiểm tra ở controller | quality_core_test, feature_booking_extras_test |
| `sp_notifications_list` | `050` | 200 thông báo trong ứng dụng mới nhất của người dùng | Chỉ đọc; thông báo của chính người gọi, tài khoản active | quality_core_test, procedure_flow_test |
| `sp_notification_read` | `050` | Đánh dấu một thông báo đã đọc; gọi lại không đổi thời điểm đọc | Một câu UPDATE; thông báo của chính người gọi, tài khoản active | quality_core_test |
| `sp_host_reviews` | `060_quality_community.sql` | Đánh giá đang hiển thị trên các tin của Host | Chỉ đọc; Host active | quality_core_test, feature_trust_test |
| `sp_host_review_reply` | `060` | Host trả lời đánh giá đang hiển thị, 1 đến 5000 ký tự | Một câu UPDATE; chủ tin có vai trò Host, tài khoản active | quality_core_test |
| `sp_admin_review_moderate` | `060` | Ẩn hoặc hiện đánh giá, giữ dòng làm bằng chứng cho báo cáo | Transaction/savepoint; khóa review; Admin; audit `moderate_review` | quality_core_test, feature_trust_test |
| `sp_reports_for_user` | `060` | Báo cáo do chính người dùng gửi | Chỉ đọc; tài khoản active | quality_core_test, procedure_flow_test |
| `sp_admin_report_resolve` | `060` | Đổi trạng thái báo cáo; `resolved` và `dismissed` bắt buộc có kết luận | Transaction/savepoint; khóa report; Admin; audit `resolve_report` | quality_core_test, procedure_flow_test |
| `sp_admin_catalog` | `060` | Danh sách loại chỗ ở hoặc tiện nghi cho trang danh mục | Chỉ đọc; Admin | quality_core_test, procedure_flow_test |
| `sp_admin_catalog_save` | `060` | Thêm, sửa hoặc tắt một mục danh mục, trả id | Transaction/savepoint; khóa dòng khi sửa; Admin; audit `save_catalog` | quality_core_test, procedure_flow_test |
| `sp_user_password_change` | `060` | Đổi mật khẩu khi hash cũ còn khớp; đóng các token khôi phục chưa dùng | Transaction/savepoint; khóa user; chính chủ, tài khoản active | quality_core_test |
| `sp_password_reset_issue` | `065_password_recovery.sql` | Tạo token khôi phục (chỉ lưu hash, hạn 45 phút, tối đa một lần mỗi 5 phút), đóng token cũ | Transaction/savepoint; khóa user; tài khoản active | quality_core_test, source_audit_test |
| `sp_password_reset_consume` | `065` | Đặt mật khẩu mới theo token còn hạn và đóng mọi token của user | Transaction/savepoint; khóa user → token; tài khoản active | quality_core_test |
| `sp_admin_promotion_toggle` | `070_booking_extras.sql` | Bật hoặc tắt mã khuyến mãi | Transaction/savepoint; khóa promotion; Admin; audit `toggle_promotion` | feature_booking_extras_test |
| `sp_admin_promotion_delete` | `070` | Xóa mã chưa dùng; mã đã dùng cho booking chỉ được tắt | Transaction/savepoint; khóa promotion như khi tạo booking; Admin; audit `delete_promotion` | feature_booking_extras_test |

## Nice-to-have routines — 2026-10-13

Sau khi gộp 5 nhánh Nice-to-have, quy tắc “SQL nghiệp vụ chỉ qua routine” vẫn giữ nguyên. Gate sau gộp (`tests/stored_procedure_audit_test.php` trong `fresh_setup_test.php --quality-regression`) ghi nhận: installer **159 production definitions, 38 opt-in test definitions, zero DROP**; **236 CALL sites / 179 routine signatures** khớp với database (trước đó 106/16 và 140/108). Trên DB nâng cấp thử: 151 tên routine production khác nhau (trước 98), tức **53 routine mới**; không có `sp_test_*`.

Quy ước chung cho mọi routine ghi dữ liệu mới: tự `START TRANSACTION` nếu caller chưa mở transaction, ngược lại dùng SAVEPOINT có tên; `EXIT HANDLER` rollback phần việc của chính routine rồi `RESIGNAL`; lỗi nghiệp vụ là `SIGNAL SQLSTATE '45000'` với thông báo tiếng Việt. Quyền được kiểm tra trong routine (không chỉ ở controller), tái sử dụng `sp_admin_assert_role`, `sp_admin_audit_add`, `sp_quality_assert_host`, `sp_quality_assert_booking`, `sp_booking_validate` (không đổi). Ngày theo giờ Việt Nam (UTC+7).

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_booking_quote` | `020_booking.sql` | Thêm `p_promo` (5 tham số); giá tính bằng `sp_booking_extras_price` chế độ không khóa, mã sai trả về thông báo thay vì lỗi | feature_booking_extras_test, booking_flow_test |
| `sp_booking_create` | `020_booking.sql` | Thêm `p_promo` (6 tham số); giữ khóa listing `FOR UPDATE` + kiểm tra trùng lịch như cũ, sau đó gọi `sp_booking_extras_price` chế độ strict; ghi `booking_nights` theo giá từng đêm, `booking_price_breakdowns`, dòng `booking_payments` chưa thu; Instant Book → `confirmed` ngay, event hệ thống (actor NULL), thông báo `booking_confirmed` cho Guest và `booking_instant` cho Host; trả về `booking_id`, `booking_status` | feature_booking_extras_test (gồm hai process tranh chấp), booking_flow_test, procedure_flow_test |
| `sp_listing_create` | `010_models.sql` | Thêm `p_19`/`p_20` vĩ độ/kinh độ (20 tham số): phải có cả hai hoặc bỏ trống cả hai, vĩ độ −90..90, kinh độ −180..180 | feature_discovery_test |
| `sp_listing_update` | `010_models.sql` | Thêm `p_20`/`p_21` (21 tham số), cùng quy tắc tọa độ | feature_discovery_test |

### Routine mới

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_booking_extras_night_rate` | `070_booking_extras.sql` | Giá một đêm: ngày lễ > cuối tuần (đêm thứ Sáu/thứ Bảy) > giá gốc | Thuần tính toán | feature_booking_extras_test (qua quote/create) |
| `sp_booking_extras_price` | `070` | Tổng giá: giá từng đêm, giảm tuần/tháng (không cộng dồn), mã khuyến mãi, phí; làm tròn đến đồng | Strict (khi tạo booking): listing `FOR UPDATE`, cài đặt `LOCK IN SHARE MODE`, mã khuyến mãi `FOR UPDATE`, đếm lượt dùng `LOCK IN SHARE MODE`, mã sai → SIGNAL | feature_booking_extras_test |
| `sp_listing_booking_rules` | `070` | Tóm tắt quy tắc giá công khai cho trang chi tiết | Chỉ đọc, không dữ liệu riêng tư | feature_booking_extras_test |
| `sp_public_holidays_upcoming` | `070` | Ngày lễ sắp tới | Chỉ đọc; giới hạn 1..50 | feature_booking_extras_test |
| `sp_host_booking_settings_get` / `_save` | `070` | Đọc/lưu giá cuối tuần, ngày lễ, % giảm, Instant Book | `sp_quality_assert_host` (chủ chỗ ở); save khóa listing `FOR UPDATE`, % giảm 0–90; không gửi duyệt lại | feature_booking_extras_test |
| `sp_admin_holidays` / `sp_admin_holiday_save` / `sp_admin_holiday_delete` | `070` | Quản lý ngày lễ; lưu ngày đã có sẽ đổi tên và bỏ cờ demo | `sp_admin_assert_role`; `FOR UPDATE`; audit `save_holiday`/`delete_holiday` | feature_booking_extras_test |
| `sp_admin_promotions` / `sp_admin_promotion_save` / `_toggle` / `_delete` | `070` | Quản lý mã khuyến mãi; mã đã có booking không đổi tên/xóa được, chỉ tắt | `sp_admin_assert_role`; `FOR UPDATE`; audit `save_promotion`/`toggle_promotion`/`delete_promotion` | feature_booking_extras_test |
| `sp_booking_extras_detail` | `070` | Bảng giá chi tiết và trạng thái thanh toán của một booking | `sp_quality_assert_booking` | feature_booking_extras_test |
| `sp_booking_payment_list` | `070` | Trạng thái thanh toán theo phạm vi `guest` (booking của mình) hoặc `host` (booking chỗ ở của mình) | Chỉ đọc, lọc theo actor | feature_booking_extras_test |
| `sp_booking_payment_mark_paid` | `070` | Ghi nhận đã thu tiền khi nhận phòng | Host chủ chỗ ở hoặc Admin; booking `confirmed`/`completed`; từ ngày nhận phòng (giờ VN); chỉ một lần; khóa listing + booking + payment; tạo dòng payment nếu booking cũ chưa có; event + thông báo `payment_paid`; audit `mark_payment_paid` khi Admin | feature_booking_extras_test |
| `sp_booking_payment_refund` | `070` | Hoàn tiền | Chỉ Admin; ghi chú bắt buộc; `paid` → `refunded`; booking đã hủy hoàn đúng `booking_cancellations.refund_amount` (0đ thì từ chối), booking khác hoàn toàn bộ; thông báo `payment_refunded`; audit `refund_payment` | feature_booking_extras_test, feature_payment_test |
| `sp_admin_payments` / `sp_admin_payment_totals` | `070` | Danh sách thanh toán (lọc trạng thái/từ khóa, `#N` là đúng booking N, tối đa 200) và tổng tiền | `sp_admin_assert_role` | feature_booking_extras_test, feature_payment_test |
| `sp_identity_verification_submit` | `075_trust.sql` | Gửi xác minh họ tên, SĐT, CCCD | Khóa dòng user; SĐT `^0[35789]\d{8}`; CCCD 12 số; một yêu cầu chờ; không gửi lại sau khi đã duyệt | feature_trust_test |
| `sp_identity_verification_status` | `075` | Trạng thái của chính mình (CCCD đã che) | Chỉ actor | feature_trust_test |
| `sp_admin_identity_verifications` | `075` | Danh sách yêu cầu (tối đa 200); CCCD đầy đủ chỉ khi đang chờ | `sp_admin_assert_role` | feature_trust_test |
| `sp_admin_identity_verification_decide` | `075` | Duyệt/từ chối | Admin; `FOR UPDATE`; không tự duyệt chính mình; lý do từ chối ≥5 ký tự; che CCCD thành `********NNNN`; audit `approve_identity`/`reject_identity` không chứa CCCD; thông báo | feature_trust_test |
| `sp_trust_badges` | `075` | Huy hiệu “Đã xác minh” và “Host uy tín” (≥3 đánh giá hiển thị, TB ≥4,5, tỷ lệ Host từ chối/hủy ≤10% trong 12 tháng, chỉ xét từ 3 yêu cầu đã quyết định) | Chỉ đọc; số liệu chi tiết chỉ trả cho chính người đó hoặc Admin | feature_trust_test |
| `sp_trust_review_badges` | `075` | Đánh dấu người viết đánh giá đã xác minh | Chỉ đọc, chỉ đánh giá đang hiển thị | feature_trust_test |
| `sp_checkin_guide_for_host` / `sp_checkin_guide_save` | `075` | Đọc/lưu hướng dẫn nhận phòng của chỗ ở | Chủ chỗ ở; save `FOR UPDATE` | feature_trust_test |
| `sp_checkin_guide_for_booking` | `075` | Hướng dẫn hiển thị trên booking; mã cửa và Wi-Fi chỉ trả về khi booking `confirmed` và hôm nay từ 1 ngày trước nhận phòng đến hết ngày trả phòng (Host chủ chỗ ở luôn thấy) | Booking `confirmed`/`completed` và actor là Guest của booking, Host chủ chỗ ở hoặc Admin | feature_trust_test |
| `sp_booking_message_assert` / `sp_booking_message_state` | `080_conversation.sql` | Kiểm tra người tham gia (Guest, Host còn vai trò host) và trạng thái được gửi: `pending`/`confirmed` mở; `completed` mở đến 14 ngày sau trả phòng; `rejected`/`cancelled` chỉ xem | Chỉ đọc; SIGNAL nếu không tham gia | feature_conversation_test |
| `sp_booking_message_send` | `080` | Gửi tin 1–2000 ký tự | Booking `FOR UPDATE`; chống dồn: mỗi người nhận tối đa một thông báo tin nhắn chưa đọc/booking (`FOR UPDATE` trên notifications) | feature_conversation_test |
| `sp_booking_messages_thread` | `080` | Hội thoại (500 tin gần nhất), đánh dấu đã đọc | Người tham gia; tin bị ẩn trả nội dung NULL | feature_conversation_test |
| `sp_message_inbox` / `sp_message_unread_count` | `080` | Inbox (tối đa 200) và số tin chưa đọc | Chỉ của actor | feature_conversation_test |
| `sp_admin_messages_search` / `sp_admin_message_thread` | `080` | Admin tìm tin nhắn (LIKE theo chữ, tối đa 200) và xem hội thoại | `sp_admin_assert_role` | feature_conversation_test |
| `sp_admin_message_moderate` | `080` | Ẩn/hiện tin nhắn | Admin; `FOR UPDATE`; lý do 5–500 ký tự; audit `hide_message`/`unhide_message` | feature_conversation_test |
| `sp_checkin_reminders_run` | `080` | Tạo thông báo nhắc lịch cho booking `confirmed` nhận phòng trong `p_days` ngày (0..30); `p_user` NULL = mọi người (CLI), có giá trị = chỉ người đó (lượt tải trang) | Idempotent bằng `INSERT IGNORE` vào `booking_reminders` (khóa chính booking + người nhận + loại); trả về `created_count` | feature_conversation_test |
| `sp_discovery_map_search` | `085_discovery.sql` | Chỗ ở công khai trong khung bản đồ, hỗ trợ vượt kinh tuyến 180; trả `__total` | Chỉ đọc; limit 1–100 | feature_discovery_test |
| `sp_discovery_view_record` | `085` | Ghi lượt xem (một dòng/tài khoản/chỗ ở) | Bỏ qua chỗ ở của chính mình hoặc không công khai | feature_discovery_test |
| `sp_discovery_search_record` | `085` | Ghi lịch sử tìm kiếm, giữ 20 lần gần nhất | Khóa dòng user | feature_discovery_test |
| `sp_discovery_recommendations` | `085` | Gợi ý theo luật (thành phố đã xem, nơi đã tìm, loại chỗ ở, mức giá, đánh giá); có cờ `personalized` và lý do | Chỉ đọc; limit 1–24; không gợi ý chỗ ở của chính mình | feature_discovery_test |
| `sp_discovery_recent_views` / `sp_discovery_history_summary` | `085` | Đã xem gần đây (1–20) và tóm tắt lịch sử | Chỉ lịch sử của actor | feature_discovery_test |
| `sp_discovery_history_clear` | `085` | Xóa lịch sử xem + tìm kiếm | Khóa dòng user; chỉ xóa của actor | feature_discovery_test |
| `sp_article_public_list` / `sp_article_public_get` / `sp_article_public_related` | `090_content.sql` | Bài viết công khai, chi tiết theo slug, bài liên quan | Chỉ đọc, chỉ bài `published` chưa xóa | feature_content_test |
| `sp_admin_articles` / `sp_admin_article_find` | `090` | Danh sách/tìm bài cho Admin | `sp_admin_assert_role` | feature_content_test |
| `sp_admin_article_save` | `090` | Tạo/sửa bài; kiểm tra chuyên mục, tiêu đề 5–200, slug, tóm tắt, nội dung 20–50000; slug tự sinh thêm `-2`, `-3`… | Admin; `FOR UPDATE`; audit `create_article`/`update_article` | feature_content_test |
| `sp_admin_article_set_status` / `sp_admin_article_delete` | `090` | Xuất bản/ẩn, xóa mềm | Admin; `FOR UPDATE`; audit `publish_article`/`unpublish_article`/`delete_article` | feature_content_test |

Test fixture routines mới (25, chỉ cài bằng `--include-tests` trong DB test riêng): 10 `testing_booking_extras.sql`, 5 `testing_trust.sql`, 4 `testing_conversation.sql`, 4 `testing_discovery.sql`, 2 `testing_content.sql`.

Giới hạn giữ nguyên từ báo cáo các nhánh: VNPay/MoMo chưa tích hợp (đã bổ sung VietQR + VNPay ngày 2026-10-15, xem mục dưới); booking tạo trước tính năng chưa có dòng thanh toán cho đến khi được đánh dấu đã thu; gợi ý tính trực tiếp mỗi lần, không cache; huy hiệu Host uy tín tính trong SQL mỗi lần tải trang.

```powershell
C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression --no-screenshots
```

Lệnh trên tự chạy mọi `tests/feature_*_test.php` và `tests/feature_*_audit.ps1` trong DB/server tạm riêng.

## Online payment routines — 2026-10-15

Thanh toán online (VietQR chuyển khoản do Admin xác nhận, VNPay sandbox) thêm bảng `payment_transactions` (36 bảng) và file `095_online_payment.sql`. Gate (`fresh_setup_test.php --quality-regression --no-screenshots`): installer **167 production definitions, 41 opt-in test definitions, zero DROP**; **248 CALL sites / 190 routine signatures** khớp database; 277 dòng PASS, không có FAIL. Khóa bí mật VNPay chỉ nằm trong `.env`: PHP (`VnpayGateway::verify`) kiểm tra chữ ký HMAC-SHA512 và mã merchant **trước** khi gọi routine, routine chỉ nhận dữ liệu đã xác thực. Thứ tự khóa trong mọi routine ghi: listing → booking → `booking_payments` → `payment_transactions`, giống `sp_booking_payment_mark_paid`.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_booking_extras_detail` | `070_booking_extras.sql` | Trả thêm `can_pay_online` (chỉ Guest của booking confirmed, chưa thu, VND) và giao dịch VietQR đang mở (`open_transfer_*`) | feature_payment_test |
| `sp_booking_payment_list` | `070` | Trả thêm `can_pay_online`, `transfer_submitted` cho trang Chuyến đi | feature_payment_test (HTTP) |

### Routine mới

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_payment_checkout` | `095_online_payment.sql` | Dữ liệu trang chọn phương thức: số tiền, được thanh toán online không, chuyển khoản đang mở | Chỉ đọc; chỉ Guest của booking, tài khoản active | feature_payment_test |
| `sp_payment_start` | `095` | Tạo lần thanh toán `vietqr`/`vnpay`, mã `H2H<booking>X<5 hex>`; VietQR cùng số tiền thì dùng lại mã đang mở | Transaction/savepoint; khóa listing → booking → payment; booking confirmed, chưa thu, VND; chặn khi đã báo chuyển khoản | feature_payment_test |
| `sp_payment_transaction_get` | `095` | Một lần thanh toán cho trang chuyển khoản | Chỉ đọc; Guest của booking hoặc Admin | feature_payment_test |
| `sp_payment_transfer_submit` | `095` | Khách báo đã chuyển khoản → `submitted`, thông báo mọi Admin | Transaction; khóa như trên; chỉ Guest, chỉ VietQR `pending`, booking confirmed chưa thu | feature_payment_test |
| `sp_admin_payment_transfer_decide` | `095` | Admin `confirm` (booking_payments `paid`/`vietqr`, event, thông báo khách, đóng các lần mở khác) hoặc `reject` (lý do ≥ 5 ký tự, thông báo khách); booking đã thu thì `reject` đóng khoản trùng (`cancelled`), trả `duplicate_closed` | Admin (`sp_admin_assert_role`); khóa như trên; một lần; audit `confirm_bank_transfer`/`reject_bank_transfer`/`close_duplicate_transfer` | feature_payment_test |
| `sp_payment_vnpay_result` | `095` | Ghi kết quả VNPay đã kiểm chữ ký: `ok` / `not_found` / `invalid_amount` / `already` (IPN trả 00/01/04/02); trả thêm trạng thái hiện tại cho thông báo của khách | Transaction; khóa như trên; đối chiếu số tiền; gọi lại an toàn; thành công có chữ ký sau một lần không thành công vẫn được ghi nhận; tiền về sau khi đã thanh toán (`payment_duplicate`) hoặc sau khi khách hủy (`payment_refund_needed`) thì báo Admin hoàn tiền và báo khách | feature_payment_test |
| `sp_payment_booking_transactions` | `095` | Lịch sử thanh toán online của một booking (20 lần gần nhất) | Chỉ đọc; `sp_quality_assert_booking` (Guest, Host chủ chỗ ở, Admin) | feature_payment_test |
| `sp_admin_payment_duplicate_resolve` | `095` | Admin ghi nhận đã hoàn một khoản VNPay trùng: chỉ đóng giao dịch đó (`cancelled`, ghi chú bắt buộc), trạng thái thanh toán của booking không đổi và được trả về; thông báo khách, audit log | Transaction/savepoint; Admin (`sp_admin_assert_role`); khóa như trên; một lần | feature_payment_test |
| `sp_admin_payment_transfers` | `095` | Hàng chờ Admin: chuyển khoản khách đã báo, rồi mã QR đang mở của booking chưa thu | Chỉ đọc; Admin | feature_payment_test |

Test fixture routines mới (9, chỉ cài trong DB test riêng): `testing_payment.sql` (`sp_test_payment_inspect`, `sp_test_payment_attempt`, `sp_test_payment_set_amount`, `sp_test_payment_listing_removed`, `sp_test_payment_old_snapshot`, `sp_test_payment_host_state`, `sp_test_payment_finish_stay`, `sp_test_payment_old_refund`, `sp_test_payment_cleanup` — chỉ xóa listing `Audit fixture PAY %` ở thành phố `Audit`).

## Đồng bộ giờ nhận phòng, huy hiệu xác minh và họ tên — 2026-10-17

Ba chỗ còn giữ bản sao cũ sau khi dữ liệu gốc đổi, cùng loại với số điện thoại liên hệ đã sửa ngày 2026-10-15. Không thêm routine hay bảng. Gate: installer 167 production / 41 test definitions, zero DROP; 248 CALL sites / 190 routine signatures; 278 dòng PASS, không có FAIL.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_booking_detail` | `050_quality_core.sql` | Trả thêm `current_check_in_time` (`HH:MM`) và `current_time_zone` của chỗ ở; `policy_snapshot` giữ nguyên | feature_conversation_test |
| `sp_checkin_reminders_run` | `080_conversation.sql` | Tin nhắc lịch ghi `listings.check_in_time` hiện tại thay cho giờ trong `policy_snapshot` | feature_conversation_test |
| `sp_trust_badges`, `sp_trust_review_badges` | `075_trust.sql` | `is_verified` / `reviewer_verified` chỉ bật khi có yêu cầu `approved` mà số điện thoại trùng `users.phone` hiện tại (bỏ khoảng trắng, chấm, gạch, ngoặc; `+84`/`84` đọc là `0`, như `Trust::normalizePhone`) | feature_trust_test |
| `sp_identity_verification_status` | `075` | Trả thêm `is_current`; yêu cầu đã duyệt nhưng số đã đổi được xếp theo ngày như các yêu cầu khác | feature_trust_test |
| `sp_identity_verification_submit` | `075` | Chỉ chặn gửi khi đang có xác minh còn hiệu lực với số hiện tại; số đã đổi thì gửi được yêu cầu mới | feature_trust_test |
| `sp_admin_identity_verification_decide` | `075` | Khi duyệt, ghi cả `full_name_on_card` vào `users.full_name` (trước đây chỉ ghi số điện thoại) | feature_trust_test |

Hạn hoàn tiền (`sp_booking_cancel`, `sp_booking_refund_preview`) vẫn tính từ giờ nhận phòng trong `policy_snapshot`: Host đổi giờ sau khi khách đặt không làm thay đổi quyền hoàn tiền của khách.

## Sửa lỗi danh mục ngừng sử dụng, báo cáo và phân trang — 2026-11-05

Các routine dưới đây sửa lỗi tại chỗ; không đổi schema.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_listing_update` | `010_models.sql` | Chấp nhận loại chỗ ở đã ngừng sử dụng chỉ khi đó là loại hiện tại của chính tin này | procedure_flow_test |
| `sp_listing_amenities_replace` | `030_atomic_writes.sql` | Không xóa hết rồi thêm lại: tiện nghi đã ngừng sử dụng được giữ nếu tin đang có, tiện nghi mới vẫn phải đang hoạt động | procedure_flow_test |
| `sp_report_create` | `060_quality_community.sql` | Thêm transaction/savepoint và khóa dòng listing; đã có báo cáo `open` cùng người gửi và cùng mục tiêu thì không thêm dòng mới, trả `__affected=0` kèm id cũ | procedure_flow_test, quality_core_test |
| `sp_admin_reviews` | `060_quality_community.sql` | Bỏ giới hạn 200 dòng: thêm `p_limit`, `p_offset`, trả `__total` như `sp_admin_articles` | quality_core_test |
| `sp_admin_reports` | `060_quality_community.sql` | Bỏ giới hạn 200 dòng: thêm `p_limit`, `p_offset`, trả `__total` như `sp_admin_articles` | procedure_flow_test, quality_core_test |

### Routine mới

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_listing_catalog_choices` | `060_quality_community.sql` | Lựa chọn trong form Host: mục đang hoạt động cộng với mục đã ngừng mà tin của chính Host vẫn dùng (`p_listing=0` là tin mới) | Chỉ đọc; mục đã ngừng chỉ hiện khi tin thuộc đúng `p_host` | procedure_flow_test, quality_core_test |

## Sửa lỗi tiền hoàn, doanh thu và thanh toán online — 2026-11-05

Các routine dưới đây sửa lỗi tại chỗ; không đổi schema, không thêm routine production. Số tiền đã hoàn thật lấy từ dòng audit `refund_payment` mới nhất (dòng cũ không có số thì dùng `amount_due`); `booking_cancellations.refund_amount` chỉ là số còn phải hoàn.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_listing_create` / `sp_listing_update` | `010_models.sql` | Từ chối giá mỗi đêm làm tròn dưới 1đ; phụ phí 0 vẫn hợp lệ | feature_booking_extras_test |
| `sp_admin_total_revenue` / `sp_host_stats` | `010_models.sql` | Doanh thu trừ số tiền đã hoàn thật, số đơn hoàn thành giữ nguyên | feature_booking_extras_test |
| `sp_booking_cancel` | `020_booking.sql` | `refund_amount` là số còn phải hoàn: 0 khi chưa thu hoặc đã hoàn trước khi hủy; thông báo đúng từng trường hợp | feature_payment_test |
| `sp_admin_listing_update` | `030_atomic_writes.sql` | Từ chối giá mỗi đêm làm tròn dưới 1đ | feature_booking_extras_test |
| `sp_booking_refund_preview` | `050_quality_core.sql` | Không hứa hoàn thêm cho booking đã hoàn | feature_payment_test |
| `sp_admin_booking_search` | `050_quality_core.sql` | `#N` là đúng booking N, vẫn theo bộ lọc trạng thái; số trần và `#abc` vẫn tìm như chữ | quality_core_test |
| `sp_host_monthly_revenue` | `050_quality_core.sql` | Doanh thu tháng trừ số tiền đã hoàn thật | feature_booking_extras_test |
| `sp_host_booking_settings_save` | `070_booking_extras.sql` | Từ chối giá cuối tuần/ngày lễ làm tròn dưới 1đ | feature_booking_extras_test |
| `sp_booking_extras_detail` | `070_booking_extras.sql` | Trả thêm `refunded_amount`; `can_pay_online` cho phép chuyến đã kết thúc trên tin đã gỡ | feature_payment_test |
| `sp_booking_payment_list` | `070_booking_extras.sql` | `can_pay_online` theo cùng quy tắc tin đã gỡ | feature_payment_test |
| `sp_booking_payment_refund` | `070_booking_extras.sql` | Chỉ thêm chú thích nơi đọc số tiền đã hoàn; không đổi hành vi | feature_payment_test |
| `sp_admin_payments` | `070_booking_extras.sql` | Trả thêm `refunded_amount`; `#N` là đúng booking N | feature_payment_test |
| `sp_admin_payment_totals` | `070_booking_extras.sql` | Tổng đã hoàn dùng số tiền hoàn thật; `paid_count` đếm mọi booking tạo nên số đã thu | feature_payment_test |
| `sp_payment_checkout` / `sp_payment_start` / `sp_payment_transaction_get` / `sp_payment_transfer_submit` | `095_online_payment.sql` | Tin đã gỡ chỉ chặn chuyến chưa kết thúc; chuyến đã kết thúc thanh toán bình thường | feature_payment_test |
| `sp_admin_payment_transfer_decide` | `095_online_payment.sql` | Booking đã thu: `reject` đóng khoản trùng (`cancelled`, event `payment_duplicate_transfer_closed`, audit `close_duplicate_transfer`), báo khách đúng sự thật và trả `duplicate_closed` | feature_payment_test |
| `sp_admin_payment_duplicate_resolve` | `095_online_payment.sql` | Trả `payment_status`; thông báo khách đúng cả khi khoản chính đã được hoàn trước đó | feature_payment_test |
| `sp_payment_vnpay_result` | `095_online_payment.sql` | Trả thêm `duplicate_refunded`, `payment_status`, `return_reason`, `refund_owed`; ghi nhận thành công sau một lần không thành công; báo khách khi tiền về booking đã hủy hoặc tin đã gỡ | feature_payment_test |

Test fixture routines mới (3, chỉ cài trong DB test riêng): `sp_test_booking_extras_refund_audit` (`testing_booking_extras.sql`), `sp_test_payment_finish_stay`, `sp_test_payment_old_refund` (`testing_payment.sql`). `sp_test_payment_inspect` trả thêm nội dung thông báo cho khách và số audit đóng khoản trùng.
## Vòng đời tin đăng và tài khoản — 2026-11-05

Các routine dưới đây sửa lỗi tại chỗ; không đổi schema.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_user_soft_delete` | `010_models.sql` | Thêm transaction/savepoint. Từ chối xóa kèm số lượng khi còn booking đã xác nhận chưa kết thúc (khách hoặc Host); nếu không, từ chối tự động yêu cầu đang chờ (event, thông báo bên còn lại) | procedure_flow_test, feature_trust_test |
| `sp_user_roles_replace` | `030_atomic_writes.sql` | Từ chối bỏ vai trò Host khi tài khoản còn chỗ ở chưa gỡ hoặc booking chưa kết thúc với tư cách Host | procedure_flow_test, feature_trust_test |
| `sp_booking_detail` | `050_quality_core.sql` | Trả thêm `listing_public` (cùng điều kiện với `sp_listing_get_public`) để trang booking chỉ hiện liên kết khi trang công khai tồn tại | feature_trust_test |
| `sp_booking_validate`, `sp_booking_create`, `sp_listing_calendar`, `sp_listing_search`, `sp_listing_search_filtered`, `sp_discovery_map_search` | `020`, `050`, `011`, `055`, `085` | Yêu cầu `pending` đã qua ngày nhận phòng không còn giữ ngày; `pending` còn hạn và `confirmed` giữ như cũ, khóa dòng listing không đổi | procedure_flow_test |
| `sp_report_create` | `060_quality_community.sql` | Báo cáo `investigating` cũng chặn báo cáo lặp, như `open` | procedure_flow_test |
| `sp_admin_listing_moderate` | `030_atomic_writes.sql` | Khi từ chối tin: từ chối tự động các yêu cầu đang chờ (event + thông báo khách), booking đã xác nhận giữ nguyên; báo cho Host kèm lý do trong cùng transaction | procedure_flow_test, feature_trust_test |
| `sp_admin_listing_soft_delete` | `030_atomic_writes.sql` | Dùng chung `sp_listing_reject_pending`; báo cho Host trong cùng transaction | feature_booking_extras_test, procedure_flow_test |
| `sp_booking_transition` | `020_booking.sql` | Không cho xác nhận yêu cầu trên tin bị từ chối kiểm duyệt (tin đang duyệt lại hoặc đang ẩn vẫn xác nhận được), hoặc khi khách/Host bị khóa hay đã xóa, với mọi người thao tác kể cả Admin | procedure_flow_test |
| `sp_listing_set_visible`, `sp_listing_get_by_host` | `010_models.sql` | Thêm transaction/savepoint và khóa dòng listing. Tin Admin đã ẩn (dòng audit cuối trong `hide_listing`/`restore_listing` là `hide_listing`) chỉ Admin hiện lại được; không có dòng audit thì xem như Host tự ẩn. Danh sách của Host trả thêm `admin_hidden` | procedure_flow_test |
| `sp_admin_listing_visibility` | `030_atomic_writes.sql` | Báo cho Host khi Admin thật sự ẩn hoặc hiện tin, trong cùng transaction; bấm lặp từ trang cũ không gửi thêm | procedure_flow_test |
| `sp_user_roles_replace` | `030_atomic_writes.sql` | Thêm tham số `p_3` (Admin thực hiện, NULL với fixture và lúc tạo tài khoản). Ghi audit `remove_host_role`/`grant_host_role` khi vai trò Host thật sự đổi | procedure_flow_test, feature_trust_test |
| `sp_user_grant_host` | `010_models.sql` | Thêm transaction/savepoint và khóa dòng users. Từ chối khi dòng audit cuối trong hai action trên là `remove_host_role` | procedure_flow_test, feature_trust_test |
| `sp_trust_badges` | `075_trust.sql` | Tỷ lệ Host từ chối/hủy chỉ tính yêu cầu có `booking_events` từ chối do chính Host; yêu cầu bị từ chối tự động hoặc do Admin không vào cả tử số lẫn mẫu số | feature_trust_test |
| `sp_booking_transition` | `020_booking.sql` | Lời từ chối ngày bị chặn theo người thao tác: Host giữ nguyên, Admin được bảo nhờ Host mở lại ngày hoặc từ chối yêu cầu | procedure_flow_test |
| `sp_notifications_list` | `050_quality_core.sql` | Trả thêm `can_open_booking` (cùng quy tắc với `sp_quality_assert_booking`) để không hiện liên kết dẫn tới 404 cho người không còn mở được booking | procedure_flow_test, quality_core_test |
| `sp_admin_listing_moderate` | `030_atomic_writes.sql` | Từ chối lý do hoặc ghi chú dài hơn 5000 ký tự | procedure_flow_test, feature_trust_test |
| `sp_booking_create` | `020_booking.sql` | Từ chối tài khoản không có vai trò Guest; booking đã có vẫn thuộc về người đặt (các routine xem, hủy, thanh toán kiểm tra `guest_id`) | procedure_flow_test, feature_trust_test |
| `sp_booking_message_state`, `sp_booking_message_send`, `sp_admin_message_thread` | `080_conversation.sql` | Tài khoản bên kia đã xóa: trả `other_deleted`, cuộc trò chuyện chỉ còn để xem, không gửi tin và không tạo thông báo; khôi phục tài khoản thì mở lại. Admin thấy `guest_deleted`/`host_deleted` | feature_conversation_test |
| `sp_booking_message_send` | `080_conversation.sql` | Bỏ ký tự điều khiển trừ xuống dòng và tab khỏi nội dung lưu; nội dung không có gì nhìn thấy được xem như rỗng. Emoji (kể cả chuỗi ZWJ) và tiếng Việt giữ nguyên | feature_conversation_test |

### Routine mới

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_user_host_role_removed` | `010_models.sql` | Cho trang tài khoản biết Admin đã gỡ vai trò Host để thay nút bằng lời giải thích; cùng quy tắc với `sp_user_grant_host` | Chỉ đọc; người gọi đã đăng nhập, chỉ đọc cho chính mình | procedure_flow_test, feature_trust_test |
| `sp_listing_reject_pending` | `050_quality_core.sql` | Từ chối mọi yêu cầu đang chờ của một tin, kèm event và thông báo khách; trả số yêu cầu qua tham số OUT | Nội bộ, chỉ gọi từ routine khác: bên gọi giữ transaction và khóa dòng listing, routine khóa các booking | procedure_flow_test, feature_booking_extras_test |

## Sửa lỗi giá, hoàn tiền và thanh toán online, vòng 3 — 2026-11-05

Giá giữ nguyên cách tính; các routine dưới đây sửa lỗi tại chỗ, không đổi schema. Thanh toán khi nhận phòng không đổi.

### Routine đã có bị thay đổi

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_booking_create` | `020_booking.sql` | Thêm `p_shown_total` (7 tham số): tổng khách thấy khác tổng tính dưới khóa listing thì không tạo gì và báo tổng mới; `NULL` (không có báo giá trên màn hình) giữ hành vi cũ | feature_booking_extras_test |
| `sp_booking_extras_price`, `sp_admin_promotions` | `070_booking_extras.sql` | Yêu cầu `pending` đã qua ngày nhận phòng không còn tính vào lượt dùng mã, ở cả báo giá, lúc tạo booking và số lượt Admin thấy | feature_booking_extras_test |
| `sp_payment_checkout`, `sp_payment_transaction_get`, `sp_booking_extras_detail`, `sp_booking_payment_list` | `095_online_payment.sql`, `070_booking_extras.sql` | Host bị khóa hoặc đã xóa được tính như chỗ ở đã gỡ khi báo có thể thanh toán online; chuyến đã kết thúc vẫn thanh toán bình thường | feature_payment_test |
| `sp_payment_start`, `sp_payment_transfer_submit`, `sp_admin_payment_transfer_decide`, `sp_payment_vnpay_result` | `095_online_payment.sql` | Sau khóa listing, đọc trạng thái Host bằng `LOCK IN SHARE MODE`; Host bị khóa hoặc đã xóa dùng chung nhánh `v_removed` (từ chối, hoặc ghi nhận rồi báo Admin hoàn tiền) | feature_payment_test |
| `sp_booking_cancel`, `sp_booking_refund_preview` | `020_booking.sql`, `050_quality_core.sql` | Cùng gọi `sp_booking_refund_terms`, nên bản xem trước có cùng fallback và cùng con số với lúc hủy thật; preview lấy giờ nhận phòng và múi giờ hiện tại của listing | feature_payment_test |

Vòng 4, Host bị khóa và lời hứa hoàn tiền:

| Routine | File | Thay đổi | Test |
|---|---|---|---|
| `sp_payment_checkout`, `sp_payment_transaction_get`, `sp_booking_extras_detail` | `095_online_payment.sql`, `070_booking_extras.sql` | Thêm `host_locked`: Host tạm khóa khác với chỗ ở đã gỡ. `listing_removed` chỉ còn chỗ ở đã gỡ hoặc Host đã xóa; `can_pay_online` không đổi | `feature_payment_test.php` |
| `sp_payment_start` | `095_online_payment.sql` | Host tạm khóa: từ chối bằng thông báo riêng, không nói “đã bị gỡ” | `feature_payment_test.php` |
| `sp_payment_transfer_submit` | `095_online_payment.sql` | Host tạm khóa không chặn việc báo chuyển khoản theo mã đã cấp trước đó, vì tiền có thể đã đi; chỗ ở đã gỡ hoặc Host đã xóa vẫn từ chối | `feature_payment_test.php` |
| `sp_payment_vnpay_result`, `sp_admin_payment_transfer_decide` | `095_online_payment.sql` | Tiền về khi Host tạm khóa là thanh toán bình thường, không hứa hoàn tiền; mọi Admin nhận thông báo `payment_host_suspended` để kiểm tra chuyến ở. Chỗ ở đã gỡ, Host đã xóa và chuyến đã kết thúc không đổi | `feature_payment_test.php` |
| `sp_booking_refund_terms`, `sp_booking_cancel`, `sp_booking_refund_preview` | `050_quality_core.sql`, `020_booking.sql` | Thêm `p_booking`. Booking đã có thông báo “Khoản thanh toán sẽ được hoàn lại” được hoàn đủ `amount_due` bất kể chính sách, kể cả sau khi chỗ ở hoặc Host được khôi phục, nên form hoàn tiền của Admin vẫn còn | `feature_payment_test.php` |

### Routine mới

| Routine | File | Mục đích | Transaction / khóa / quyền | Test |
|---|---|---|---|---|
| `sp_booking_refund_terms` | `050_quality_core.sql` | Tính phần trăm và cơ sở hoàn của booking đã xác nhận theo snapshot; thiếu múi giờ hoặc giờ nhận phòng thì lấy giá trị hiện tại của listing | Helper chỉ tính, không ghi; chạy trong khóa của `sp_booking_cancel`; quyền do routine gọi kiểm tra | feature_payment_test |

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
