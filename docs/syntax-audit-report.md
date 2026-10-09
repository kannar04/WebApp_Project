# Home2Home — Source, Syntax and Database Image Audit

Ngày thực hiện: 2026-10-09. Phạm vi: source PHP OOP/MVC hiện tại, routing,
JavaScript/AJAX, CSS/HTML Bootstrap 4.6.2, PDO/MySQL và ảnh listing.

## 1. Baseline và số file kiểm tra

Đã đọc toàn bộ `skill_web.md`, `skill_UIUX.md`, `skill_user.md`,
`File_Ideas/Functions.txt`, config, routes, Controllers, Models, Services và Views.
Giữ kiến trúc PHP MVC và stack hiện tại. Không thêm framework hoặc thay đổi gitignore.

- Baseline: 50 file PHP; không tìm thấy lỗi cú pháp qua PHP lint.
- Sau sửa: 55 file PHP được lint, gồm 18 PHP Views.
- 1 file JavaScript tự viết, 1 stylesheet tự viết, 2 script SQL chính.
- 40 route handlers được xác minh class autoload và public method.
- 5 JPEG demo đã kiểm tra nội dung bằng mắt và kiểm tra MIME/kích thước.

Không xem thư viện CDN là source cần refactor. Không ghi đè các thay đổi Git ngoài
phạm vi audit; không commit, push hoặc reset repository.

## 2. Lỗi phát hiện và sửa

Không phát hiện lỗi thiếu dấu ngoặc, dấu chấm phẩy hoặc khai báo PHP. JavaScript
được parse bằng Chrome V8 thay cho Node.js CLI không có trong môi trường.

| ID | Loại / lỗi trước sửa | Sửa chữa | Xác minh |
|---|---|---|---|
| SRC-01 | SQL runtime: moderation ghi `approved/rejected` vào event, trái CHECK `approve/reject` của DB ERD | Tách status và event verb; đồng bộ enum trong schema cài mới | PASS query DB thực + HTTP approve/reject/approve |
| SRC-02 | Host lưu listing/amenities rồi upload lỗi; dữ liệu có thể cập nhật dở | Validate catalog/range/time; transaction chứa listing, bảng nối và photo reference; rollback khi upload sai | PASS multipart README giả JPEG; DB và amenities giữ nguyên |
| SRC-03 | Admin form userUpdate cho phép tự bỏ quyền Admin | Giữ vai trò hiện tại khi sửa chính mình | PASS HTTP trên tài khoản fixture Admin |
| SRC-04 | Admin userUpdate email trùng gây 500; cập nhật hồ sơ/vai trò không atomic | Validate độ dài/mật khẩu; catch duplicate email; transaction cho profile/roles/audit; replaceRoles tôn trọng transaction bên ngoài | PASS HTTP và rollback integration |
| SRC-05 | Wishlist ID không tồn tại gây FK error; phiên hết hạn trả HTML cho fetch JSON | Validate listing; trả 404 JSON; JSON auth trả 401; fetch gửi Accept JSON nên CSRF trả 419 | PASS 401/404/419 HTTP + browser toggle/restore |
| SRC-06 | Quote cũ trả chậm ghi đè ngày mới; màu lỗi không reset; xóa ngày vẫn giữ tổng cũ | AbortController, revision check, reset trạng thái; render bằng textContent | PASS browser mô phỏng response đảo thứ tự, invalid→valid→clear |
| SRC-07 | Fetch Wishlist dùng APP_URL không được khởi tạo | Đọc base URL từ meta được server render | PASS browser; helper được review cho app base path |
| SRC-08 | Booking Admin xác nhận bỏ qua ngày chặn; booking tương lai vẫn có thể hoàn thành qua backend | Admin kiểm tra availability; Host/Admin chặn completion trước checkout; Review thêm điều kiện checkout | PASS booking/calendar integration; Review condition code review |
| SRC-09 | Calendar và booking dùng lock khác nhau, có thể race | Khóa listing trước booking; calendar cũng khóa listing trong transaction | PASS hành vi tuần tự; concurrency stress NOT_VERIFIED |
| SRC-10 | Pending refund có thể trừ phí dù chưa được xác nhận; giờ arrival hardcode 14:00 | Hoàn toàn bộ pending; snapshot giờ nhận phòng/timezone; cancellation dùng snapshot | PASS pending refund + snapshot; cutoff-boundary timing NOT_VERIFIED |
| SRC-11 | Rating search tính cả review đã hidden | Join review chỉ với moderation visible | SQL/code review; fixture hidden-review riêng NOT_VERIFIED |
| SRC-12 | 500 production là dòng chữ không có đường phục hồi; một số catch lộ PDO details | HTML error view độc lập DB/auth; API JSON 500; các catch nghiệp vụ trả thông báo an toàn | PASS server riêng giả lập DB unavailable |
| SRC-13 | Search arrays/null-byte/giá hoặc số khách sai đi xuống model; bộ lọc type/sort làm mất ngày/số khách | Validate scalar, ngày chính xác, integer/range; giữ hidden date/guest fields | PASS malformed arrays HTTP; date/guest validation regression |
| SRC-14 | Ảnh DB dùng đường dẫn gốc chưa hỗ trợ app base; gallery 1/2 ảnh để trống cột; chi tiết luôn ghi Nguyên căn | image_url helper; gallery theo số ảnh; hiển thị property_type thật; giải thích demo provenance | PASS browser ảnh/detail/responsive |
| SRC-15 | Importer và schema test không nạp .env; schema test drop tên DB test cố định | Nạp bootstrap; importer chỉ chạy demo local; test DB dùng suffix random | PASS schema + seed lặp hai lần |
| SRC-16 | Form Host thất bại làm mất input; label numeric/time không liên kết input | Session old input, checkbox hydrate và label/ID tương ứng | PASS HTTP preserved input; HTML review/lint |

Không dùng placeholder để che lỗi dữ liệu. Placeholder chỉ dùng khi listing thực sự
không có ảnh. Tổng tiền luôn được tính lại ở backend.

## 3. File chỉnh sửa / thêm

- Controllers: AdminController, AuthController, BookingController, HomeController,
  HostController, ListingController, ProfileController, WishlistController.
- Models: AdminRepository, Listing, Review, User, Wishlist.
- Service: BookingService.
- Core: Controller và bootstrap.
- Views: admin/dashboard, bookings/index, home, host/listing-form, layouts/main,
  listings/show, listings/wishlist, profile/show; thêm errors/500.
- Public: index.php, assets/js/app.js, assets/css/app.css và 5 ảnh dưới assets/images/demo.
- Database: import.php, schema.sql; thêm seeds/listing_images.php và seeds/seed_listing_images.php.
- Tests: sửa schema_test.php; thêm source_audit_test.php, http_audit_fixture.php,
  http_audit.ps1, browser_audit.ps1, db_outage_audit.ps1.
- Docs: database-setup.md, demo-image-sources.md, báo cáo này và cập nhật bằng chứng audit/progress.

Code liên quan lỗi được viết tường minh hơn: Host persistence, Admin profile update,
Wishlist mutation, moderation transaction và JavaScript. Không refactor toàn bộ
project chỉ vì khác phong cách.

## 4. Database thực tế và ảnh bổ sung

PASS kết nối PDO `db_home2home`, UTF-8/utf8mb4, MariaDB 10.4.32/XAMPP local;
PHP CLI 8.2.12 có PDO MySQL, mbstring, fileinfo và DOM. Credentials không ghi trong báo cáo.

DB hiện có 22 bảng. Đối chiếu `listing_photos`, FK listing, sort-order uniqueness,
CHECK moderation, các bảng booking/event/notification và config đọc environment.

DB ERD có một số constraint/type/FK khác schema cài mới; không reset hoặc ALTER DB
hiện hữu. Code được sửa để chạy với constraint thực tế. `CREATE TABLE IF NOT EXISTS`
không nâng cấp schema có sẵn: các thay đổi schema.sql chỉ dành cho cài mới.

Đã thêm 5 bản ghi `listing_photos` trỏ tới JPEG local:

| Listing demo | Ảnh local mới |
|---|---|
| Ngôi nhà thông Đà Lạt | 1449158743715-0a90ebb6d2d8.jpg |
| Villa An Nhiên Hội An | 1600607687920-4e2a09cf159d.jpg |
| Villa An Nhiên Hội An | 1600585154340-be6161a56a0c.jpg |
| Villa An Nhiên Hội An | 1600566753086-00f18fb6b3ea.jpg |
| Sea Breeze Homestay | 1499793983690-e29da59ef1c2.jpg |

Nguồn là các ảnh Unsplash đã được seed cũ tham chiếu. Điều kiện sử dụng đã được
đối chiếu với [Unsplash License](https://unsplash.com/license); thông tin nguồn,
mapping và hướng dẫn có tại [demo-image-sources.md](demo-image-sources.md).
Đây là ảnh minh họa, không xác nhận địa điểm/cơ sở thực tế và không phải ảnh AI.

Seeder giới hạn môi trường local/loopback và db_home2home; kiểm tra file JPEG,
match title/city/demo owner thay vì hardcode ID; bỏ qua listing có ảnh không phải
demo; khóa listing, chống trùng đường dẫn, tuân thủ sort_order unique và transaction.

Kết quả thật:

- Dry run: 5 ảnh có thể insert, rollback.
- Apply lần 1: 5 insert.
- Apply lần 2: 0 insert, 5 skip.
- DB sau test: 3 listing gốc, 10 ảnh (5 cũ + 5 mới), 0 booking, 0 fixture user tồn đọng.
- Bảng thay đổi lâu dài: chỉ `listing_photos`. Không sửa nội dung listing hoặc ảnh upload.

Fixture integration/HTTP được tạo riêng và dọn theo ID/marker chính xác sau test;
không xóa dữ liệu người dùng. Schema test chỉ tạo rồi dọn DB ngẫu nhiên của chính nó,
không tác động schema hoặc database chính.

## 5. Kết quả kiểm thử

| Kiểm tra thực hiện | Trạng thái |
|---|---|
| PHP lint tất cả 55 file | PASS |
| Autoload/public handlers cho 40 routes | PASS |
| SQL schema sạch 22 bảng + seed chạy hai lần | PASS |
| Existing booking flow/snapshot/events/notifications/double booking | PASS |
| Source audit integration: moderation thật, calendar, refund, ownership, nested-role transaction | PASS |
| HTTP home/detail/quote smoke | PASS |
| HTTP role 403, 404, JSON 401/404/419, malformed filter | PASS |
| HTTP invalid Host input/amenity/upload và dữ liệu không đổi | PASS |
| HTTP Admin moderation/self-demotion/email trùng | PASS |
| Chrome V8 parse JavaScript; CSS.supports các declaration tự viết | PASS |
| Chrome home/detail tại 375, 768, 1024, 1440px | PASS |
| Chrome login/profile/bookings/wishlist/Host dashboard/edit/calendar/Admin dashboard/user-edit/listing-edit | PASS |
| DOM duplicate IDs, ảnh hỏng, page overflow, PHP error text trên các trang kiểm tra | PASS — không phát hiện |
| Browser AJAX quote, response ordering, error recovery/reset, Wishlist toggle/restore | PASS |
| JavaScript exceptions trên các lượt browser đã kiểm tra | PASS — không phát hiện |
| Mất kết nối DB: HTML 500 có recovery link / JSON 500 không lộ SQL | PASS |
| Image seed repeat + MIME/file reference/FK | PASS |
| git diff --check; ảnh demo không bị ignore | PASS |

Các script đã chạy:

```powershell
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\source_audit_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
powershell -ExecutionPolicy Bypass -File tests\http_audit.ps1
powershell -ExecutionPolicy Bypass -File tests\browser_audit.ps1
powershell -ExecutionPolicy Bypass -File tests\db_outage_audit.ps1
C:\xampp\php\php.exe database\seeds\seed_listing_images.php --apply
```

Chrome được điều khiển thật qua DevTools Protocol; không gọi HTTP test là browser
test. Không tạo screenshot mới trong lượt này. Browser profiles tạm được giữ tại
thư mục TEMP ngoài repository để kiểm tra, không đưa vào Git.

## 6. Giới hạn / phần chưa xác minh

- NOT_VERIFIED: stress test nhiều tiến trình đồng thời cho booking/calendar.
- NOT_VERIFIED: toàn bộ boundary thời gian hoàn tiền/chuyển timezone, file-system
  failure ngay sau khi move upload thành công, và deployment trong thư mục con.
- NOT_VERIFIED: browser Firefox/Safari, keyboard/screen-reader đầy đủ, network
  offline/CDN outage và screenshot regression. CDN Bootstrap/font và 5 URL ảnh cũ
  vẫn được giữ; ảnh cover demo mới dùng local assets.
- Không có Node CLI: kiểm tra cú pháp JS bằng Chrome V8 đã PASS, không cài framework.
- Không có blocker môi trường đối với các kiểm thử đã thực hiện.
- Không còn lỗi syntax/runtime đã xác nhận trong phạm vi các case kiểm tra ở trên.
  Không suy diễn thành mọi tính năng đã PASS 100%.
