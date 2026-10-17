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
| `sp_booking_payment_refund` | `070` | Hoàn tiền | Chỉ Admin; ghi chú bắt buộc; `paid` → `refunded`; thông báo `payment_refunded`; audit `refund_payment` | feature_booking_extras_test |
| `sp_admin_payments` / `sp_admin_payment_totals` | `070` | Danh sách thanh toán (lọc trạng thái/từ khóa, tối đa 200) và tổng tiền | `sp_admin_assert_role` | feature_booking_extras_test |
| `sp_identity_verification_submit` | `075_trust.sql` | Gửi xác minh họ tên, SĐT, CCCD | Khóa dòng user; SĐT `^0[35789]\d{8}`; CCCD 12 số; một yêu cầu chờ; không gửi lại sau khi đã duyệt | feature_trust_test |
| `sp_identity_verification_status` | `075` | Trạng thái của chính mình (CCCD đã che) | Chỉ actor | feature_trust_test |
| `sp_admin_identity_verifications` | `075` | Danh sách yêu cầu (tối đa 200); CCCD đầy đủ chỉ khi đang chờ | `sp_admin_assert_role` | feature_trust_test |
| `sp_admin_identity_verification_decide` | `075` | Duyệt/từ chối | Admin; `FOR UPDATE`; không tự duyệt chính mình; lý do từ chối ≥5 ký tự; che CCCD thành `********NNNN`; audit `approve_identity`/`reject_identity` không chứa CCCD; thông báo | feature_trust_test |
| `sp_trust_badges` | `075` | Huy hiệu “Đã xác minh” và “Host uy tín” (≥3 đánh giá hiển thị, TB ≥4,5, tỷ lệ Host từ chối/hủy ≤10% trong 12 tháng, chỉ xét từ 3 yêu cầu đã quyết định) | Chỉ đọc; số liệu chi tiết chỉ trả cho chính người đó hoặc Admin | feature_trust_test |
| `sp_trust_review_badges` | `075` | Đánh dấu người viết đánh giá đã xác minh | Chỉ đọc, chỉ đánh giá đang hiển thị | feature_trust_test |
| `sp_checkin_guide_for_host` / `sp_checkin_guide_save` | `075` | Đọc/lưu hướng dẫn nhận phòng của chỗ ở | Chủ chỗ ở; save `FOR UPDATE` | feature_trust_test |
| `sp_checkin_guide_for_booking` | `075` | Hướng dẫn hiển thị trên booking | Booking `confirmed`/`completed` và actor là Guest của booking, Host chủ chỗ ở hoặc Admin | feature_trust_test |
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

Test fixture routines mới (22, chỉ cài bằng `--include-tests` trong DB test riêng): 8 `testing_booking_extras.sql`, 4 `testing_trust.sql`, 4 `testing_conversation.sql`, 4 `testing_discovery.sql`, 2 `testing_content.sql`.

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
| `sp_admin_payment_transfer_decide` | `095` | Admin `confirm` (booking_payments `paid`/`vietqr`, event, thông báo khách, đóng các lần mở khác) hoặc `reject` (lý do ≥ 5 ký tự, thông báo khách) | Admin (`sp_admin_assert_role`); khóa như trên; một lần; audit `confirm_bank_transfer`/`reject_bank_transfer` | feature_payment_test |
| `sp_payment_vnpay_result` | `095` | Ghi kết quả VNPay đã kiểm chữ ký: `ok` / `not_found` / `invalid_amount` / `already` (IPN trả 00/01/04/02) | Transaction; khóa như trên; đối chiếu số tiền; gọi lại an toàn; tiền về sau khi đã thanh toán (`payment_duplicate`) hoặc sau khi khách hủy (`payment_refund_needed`) thì báo Admin hoàn tiền | feature_payment_test |
| `sp_payment_booking_transactions` | `095` | Lịch sử thanh toán online của một booking (20 lần gần nhất) | Chỉ đọc; `sp_quality_assert_booking` (Guest, Host chủ chỗ ở, Admin) | feature_payment_test |
| `sp_admin_payment_transfers` | `095` | Hàng chờ Admin: chuyển khoản khách đã báo, rồi mã QR đang mở của booking chưa thu | Chỉ đọc; Admin | feature_payment_test |

Test fixture routines mới (3, chỉ cài trong DB test riêng): `testing_payment.sql` (`sp_test_payment_inspect`, `sp_test_payment_attempt`, `sp_test_payment_cleanup` — chỉ xóa listing `Audit fixture PAY %` ở thành phố `Audit`).

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
