# Audit đồng bộ `test` → `dev`

Ngày: 2026-10-20 · Người thực hiện: Nguyễn Tuấn Khôi (31241020025)

## Cập nhật sau PR #38

Theo phản hồi của nhóm trưởng, PR tiếp theo đã làm những việc sau:

- **Đồng bộ file đã có code:**
  - 37 file code ở mục 4 (loại A, B và C, trừ `README.md` và `.gitignore`) đã được thay bằng bản giống từng byte với `test`.
  - Trong đó có `app/Views/admin/user-edit.php`, nay đã là form sửa tài khoản.
  - `README.md` và `.gitignore` giữ bản của `dev`.
- **Database không để trong repo:** dữ liệu, schema và stored procedure nằm trên MySQL/phpMyAdmin của nhóm trưởng và được cập nhật tại đó.
  - `.gitignore` có lại dòng `*.sql`.
  - Các file `.sql` đã bị xóa khỏi `dev`.
  - Các script trong `database/` trở lại rỗng như trước khi sync.
  - `database/checkin_reminders.php` là file code mới nên để rỗng. Tổng số file code mới để rỗng là 65.

Mục 3 và mục 4 bên dưới mô tả trạng thái lúc làm PR #38.

## 1. Phạm vi

- **`test`** = commit `13711892` (đã gộp toàn bộ `test-2`: Nice-to-have, giao diện EN/VI, thanh toán online VietQR + VNPay sandbox). Từ nay chỉ gọi là `test`.
- **`dev`** = commit `dd71eaaa` (Merge PR #37), trước khi gộp PR này.
- **"test cũ"** = commit `f47e45f5` (bản `test` ngày 09/10). Đây là bản các thành viên đã chép code sang `dev`.

Nguyên tắc sync theo yêu cầu của nhóm trưởng:

1. File code mới so với `dev` chỉ được thêm dưới dạng **file rỗng**.
2. Database được sync bằng **nội dung thật**.
3. File đã có code trên `dev` **không sửa**. Mọi khác biệt với `test` được ghi lại ở mục 4.

Cách so sánh: đối chiếu từng file giữa hai nhánh. Với mỗi file khác nhau, so thêm sau khi bỏ hết khoảng trắng và xuống dòng, để tách khác biệt chỉ do định dạng khỏi khác biệt thật về code.

## 2. Tổng quan

| Nhóm file | Số file | Xử lý trong PR này |
|---|---:|---|
| Có code ở cả hai nhánh, giống hệt | 10 | Không đổi |
| Có code ở cả hai nhánh, khác nhau | 39 | Không sửa, ghi chi tiết ở mục 4 |
| Chỉ có ở `dev` (`File_Ideas/`) | 4 | Giữ nguyên |
| Database: file mới so với `dev` | 27 | Thêm nội dung thật |
| Database: file đang rỗng trên `dev` | 8 | Điền nội dung thật |
| File code mới so với `dev` | 64 | Thêm file rỗng |
| File đang rỗng trên `dev`, ngoài database | 89 | Giữ rỗng (xem phụ lục A) |
| Ảnh `docs/quality/` mới | 73 | Không thêm (không phải code) |
| `tests/` mới | 8 | Không thêm (`.gitignore` của `dev` bỏ qua `tests`) |

`test` có 318 file, `dev` có 150 file. Hai nhánh chỉ chung commit gốc `Initial_UI commit` (`9e8ab62a`).

## 3. Database

**Trước PR này, `dev` chưa có database.**

- Trên `dev` không có file `.sql` nào, vì `.gitignore` có dòng `*.sql`. Dòng này chặn `schema.sql`, `seed.sql` và toàn bộ `database/procedures/*.sql`.
- Mọi file PHP trong `database/` đều rỗng (0 byte).

**`test` dùng database thật**: MySQL `db_home2home`. Mọi truy vấn đi qua stored procedure (`CALL sp_*`). Không có dữ liệu giả lập trong code chạy. Phần "giả lập" duy nhất là dữ liệu demo trong seed: tài khoản kiểm thử, chỗ ở mẫu và ngày lễ mẫu do Admin xác nhận.

**Đã sync trong PR này** (nội dung giống hệt `test`):

- `.gitignore`: bỏ dòng `*.sql` và gợi ý cũ `!database/sample.sql`, thay bằng ghi chú lý do. Các dòng khác giữ nguyên như trên `dev`.
- `database/schema.sql` (36 bảng) và `database/seed.sql`.
- `database/procedures/`:
  - 17 file migration đánh số `001`–`095`;
  - 7 file `testing*.sql`, chỉ bộ test cài;
  - `install.php`.
- `database/import.php`, `install_procedures.php`, `checkin_reminders.php`.
- `database/seeds/` và `database/tools/`.

**Lưu ý:** các script trong `database/` cần những file sau, mà trên `dev` vẫn **rỗng** vì thuộc phần việc của thành viên:

- `core/bootstrap.php`
- `core/Database.php`
- `core/ProcedureConnection.php`
- `core/ProcedureStatement.php`
- `config/app.php`
- `config/database.php`

Vì vậy, `php database\import.php` và ứng dụng trên `dev` chỉ chạy được sau khi các file này được điền.

## 4. Audit các file đã có code trên `dev`

### 4.1. Kết luận chung

Sau khi bỏ khoảng trắng, code của **35/39 file** trên `dev` trùng khớp với test cũ. Thành viên đã chép đúng, chỉ định dạng lại: xuống dòng, thụt lề, CRLF/LF. Như vậy:

- 9 file chỉ khác định dạng so với `test` (loại **A**).
- 26 file khác vì `test` đã có thêm chức năng sau ngày 09/10 (loại **B**).
- 4 file `dev` có sửa riêng (loại **C**). Trong đó có **1 lỗi cần sửa**: `app/Views/admin/user-edit.php`.

| Loại | Ý nghĩa | Số file |
|---|---|---:|
| A | Chỉ khác khoảng trắng hoặc xuống dòng, code giống hệt `test` | 9 |
| B | `dev` = test cũ (đã định dạng lại); `test` có thêm chức năng mới | 26 |
| C | `dev` có thay đổi riêng so với test cũ | 4 |

Khi điền code cho file loại B, có thể lấy nguyên bản từ `test` mà không mất gì của `dev`, vì phần khác của `dev` chỉ là định dạng.

> ⚠ **Lỗi trên `dev`:** `app/Views/admin/user-edit.php` đang chứa form **"Chỉnh sửa chỗ ở"**, tức nội dung của `admin/listing-edit.php`, chứ không phải form **"Sửa tài khoản"**. Trang Admin → Sửa tài khoản sẽ lỗi vì view dùng biến `$listing`, trong khi controller truyền `$managedUser`. Cần thay bằng bản từ `test`.

### 4.2. Loại C: `dev` có thay đổi riêng

| File | Bên `dev` | Bên `test` |
|---|---|---|
| `.gitignore` | Bản riêng của nhóm trưởng: OS/IDE, `vendor/`, `node_modules/`, logs, cache, `uploads/*`, `xampp/`, `dist/`, `build/`, `graph/`, `tests`, `.kilo/`. Từng có `*.sql`, đã bỏ trong PR này. | Bản chi tiết: giữ mọi `.sql` của nhóm, chỉ bỏ qua backup/dump (`*.sql.gz`, `/database/backups/`…). Không bỏ qua `tests/`. |
| `README.md` | README ngắn: tên đề tài và bảng thành viên. | README đầy đủ: stack, cấu trúc thư mục, chức năng Guest/Host/Admin/Nice-to-have/thanh toán online, cài đặt và chạy, tài khoản kiểm thử, lệnh test, giới hạn hiện tại, cấu hình email, cấu hình thanh toán online (chỉ tên biến `.env`, không có giá trị). Có cùng bảng thành viên. |
| `app/Views/admin/user-edit.php` | **Lỗi**: nội dung là form sửa chỗ ở (xem cảnh báo ở 4.1). | Form sửa tài khoản: họ tên, email, SĐT, mật khẩu mới, vai trò. Có thêm huy hiệu "Đã xác minh"/"Host uy tín", liên kết "Xem yêu cầu xác minh danh tính", tên vai trò viết hoa chữ đầu và giao diện EN/VI. |
| `app/Views/listings/show.php` | Định dạng lại nhiều dòng, thêm dấu phẩy cuối mảng, bỏ một cặp ngoặc thừa. Không đổi hành vi. | Như loại B: xem dòng `listings/show.php` ở 4.4. |

### 4.3. Loại A: chỉ khác định dạng

Code giống hệt `test`, chỉ khác khoảng trắng, thụt lề, CRLF/LF hoặc dòng trống cuối file:

- `app/Controllers/CatalogController.php`
- `app/Models/AdminRepository.php`
- `app/Models/Booking.php`
- `app/Models/Catalog.php`
- `app/Models/HostRepository.php`
- `app/Models/Notification.php`
- `app/Models/PasswordReset.php`
- `app/Models/Report.php`
- `app/Models/Review.php`

### 4.4. Loại B: thay đổi của `test` so với `dev`

Bảng này gồm 26 file loại B, cộng với phần thay đổi bên `test` của `listings/show.php` (loại C).

"i18n" nghĩa là chuỗi giao diện tiếng Việt được bọc bằng `__()` để hiển thị tiếng Anh khi chọn EN; nội dung tiếng Việt không đổi. Đi kèm thường có:

- `status_label()`: hiển thị tên trạng thái thay vì mã trạng thái;
- `display_date()`: hiển thị ngày theo dạng `d/m/Y`;
- `BookingService::money()`: định dạng giá.

**Controller và Model**

| File | Thay đổi trong `test` |
|---|---|
| `app/Controllers/AdminController.php` | Tiêu đề dashboard dịch được (`Quản trị {brand}`). Truyền `trustBadges` (huy hiệu) cho dashboard và trang sửa tài khoản. Khi bỏ chọn hết vai trò, nhật ký Admin ghi `guest` cho khớp với `User::replaceRoles`. |
| `app/Controllers/AuthController.php` | Lỗi kiểm tra form đăng ký được dịch từng câu rồi mới nối thành một thông báo. |
| `app/Controllers/BookingController.php` | Trang "Chuyến đi" nhận thêm tình trạng thanh toán (`BookingExtras::paymentMap`). Chi tiết booking nhận thêm khung tin nhắn (`ConversationService::bookingPanel`), huy hiệu Guest/Host, hướng dẫn nhận phòng, chi tiết giá/khuyến mãi/thanh toán (`BookingExtras::bookingDetail`) và lịch sử giao dịch online (`Payment::bookingAttempts`). Đặt chỗ chuyển sang `BookingService::book()`, nhận thêm mã khuyến mãi; với Đặt ngay (Instant Book), booking được xác nhận ngay và có thông báo riêng. |
| `app/Controllers/HomeController.php` | Ghi lịch sử tìm kiếm (`DiscoveryService::recordSearch`). Thêm kệ gợi ý trên trang chủ cho người đã đăng nhập (`homeShelf`). Thêm huy hiệu "Host uy tín" trên thẻ kết quả, cả trang chủ lẫn tìm kiếm AJAX. `validatedFilters()` đổi từ `private` sang `public static` để trang bản đồ dùng chung. Lỗi bộ lọc được dịch từng câu. |
| `app/Controllers/HostController.php` | Dashboard Host nhận thêm trạng thái uy tín (`trustStatus`) và tình trạng thanh toán. Form chỗ ở nhận thêm vĩ độ/kinh độ (`coordinateInput`, `assertCoordinates`): phải nhập cả hai hoặc để trống cả hai; vĩ độ từ -90 đến 90, kinh độ từ -180 đến 180. |
| `app/Controllers/ListingController.php` | Ghi lịch sử xem chỗ ở (`DiscoveryService::recordView`). Trang chi tiết nhận thêm huy hiệu Host, huy hiệu đánh giá đã xác minh, quy tắc giá (`bookingRules`) và các ngày lễ sắp tới. API báo giá (`quote`) nhận thêm `promo_code`. |
| `app/Controllers/NotificationController.php` | Khi mở trang thông báo, gọi `ConversationService::remindOpportunistically()` để tạo nhắc lịch check-in nếu đến hạn. |
| `app/Controllers/ProfileController.php` | Trang tài khoản nhận thêm trạng thái xác minh danh tính, dữ liệu form xác minh cũ, huy hiệu và tóm tắt lịch sử xem/tìm kiếm (`DiscoveryService::summary`). |
| `app/Models/Listing.php` | `sp_listing_create` tăng từ 18 lên 20 tham số và `sp_listing_update` từ 19 lên 21, để thêm `latitude`/`longitude`. Thêm hàm `coordinate()` làm tròn tọa độ đến 6 chữ số thập phân. Procedure tương ứng nằm trong `database/procedures/` của PR này. |

**View**

| File | Thay đổi trong `test` |
|---|---|
| `app/Views/admin/catalog.php` | i18n tiêu đề, nhãn, nút và hộp xác nhận. |
| `app/Views/admin/dashboard.php` | i18n. Thêm các nút điều hướng tới Thanh toán, Khuyến mãi, Ngày lễ, Tin nhắn, Xác minh danh tính và Cẩm nang (`dev` chỉ có Danh mục, Báo cáo, Đánh giá). Danh sách người dùng có huy hiệu và trạng thái Host uy tín (`trust-host-state.php`). Trạng thái người dùng, chỗ ở và booking dùng `status_label()`. Ngày dùng `display_date()`. |
| `app/Views/admin/listing-edit.php` | i18n. |
| `app/Views/auth/forgot.php` | i18n. |
| `app/Views/auth/login.php` | i18n. Mật khẩu kiểm thử được chèn vào câu dịch qua tham số `{password}`. |
| `app/Views/auth/register.php` | i18n. |
| `app/Views/auth/reset.php` | i18n. |
| `app/Views/bookings/index.php` | i18n, `status_label()`, `display_date()`. Mỗi booking có dòng thanh toán: trạng thái, nhãn "⚡ Đặt ngay", phương thức, số tiền đã giảm. Thêm nút "Thanh toán online", hoặc dòng "Đã báo chuyển khoản, chờ Admin xác nhận". Ẩn form đánh giá nếu đã đánh giá. |
| `app/Views/bookings/show.php` | i18n. Tách nhãn nút hành động (Xác nhận/Từ chối/Hoàn tất) khỏi nhãn trạng thái. Thêm: nhãn Đặt ngay; huy hiệu Guest và Host; nút "Liên hệ qua Zalo"; hướng dẫn nhận phòng (`checkin-guide.php`); bảng giá có giảm giá theo tuần/tháng và mã khuyến mãi; khu "Tình trạng thanh toán" (thời điểm và người thu tiền, hoàn tiền, ghi chú, form Host/Admin ghi nhận đã thu, form Admin hoàn tiền); bảng giao dịch online (VietQR/VNPay, mã VNPay, người xử lý); chính sách hủy hiển thị theo giờ nhận phòng hiện tại và giờ lúc đặt; khung tin nhắn theo booking (`messages/thread.php`). |
| `app/Views/home.php` | i18n. Thêm kệ gợi ý (`components/discovery-home.php`), liên kết sang bản đồ (`components/discovery-map-link.php`), nhãn "Mới" khi chỗ ở chưa có đánh giá, huy hiệu "Host uy tín". Giá dùng `BookingService::money()`. |
| `app/Views/layouts/auth.php` | `lang` theo ngôn ngữ đang chọn. Nạp `theme.js`. Thêm nút EN/VI và nút chế độ tối. Thông báo flash được dịch. |
| `app/Views/layouts/main.php` | `lang` theo ngôn ngữ đang chọn. Nạp `theme.js`. Menu thêm "Cẩm nang" (`/articles`) và "Tin nhắn" (`/messages`) kèm số tin chưa đọc. Thêm nút EN/VI, nút chế độ tối và liên kết "Cẩm nang du lịch và tin tức" ở footer. Nhúng từ điển JS (`#h2h-i18n`). Hỗ trợ `$pageScripts` để mỗi trang nạp script riêng. |
| `app/Views/listings/show.php` | i18n. Nạp `booking-extras.js`. Huy hiệu Host (có giải thích) và huy hiệu "Đã xác minh" trên từng đánh giá. Bản đồ nhỏ (`components/discovery-mini-map.php`). Quy tắc giá: đêm thứ Sáu/thứ Bảy, đêm ngày lễ, giảm theo tuần/tháng. Ô mã khuyến mãi. Nút "Đặt ngay" khi chỗ ở bật Instant Book. Ghi chú thanh toán khi nhận phòng hoặc online. Điểm sao chỉ hiện khi đã có đánh giá. |
| `app/Views/listings/wishlist.php` | i18n. Nút tim có `type="button"`, `aria-label`, `aria-pressed`. Tên loại chỗ ở được dịch. Giá dùng `BookingService::money()`. |
| `app/Views/notifications/index.php` | i18n. Nhãn loại "Nhắc lịch"/"Tin nhắn". Ngày dùng `display_date()`. Thông báo tin nhắn mở thẳng tới `#booking-messages` với nút "Xem tin nhắn". |
| `app/Views/profile/show.php` | i18n. Huy hiệu dưới tên. Thêm mục và liên kết "Xác minh danh tính" (`profile/verification.php`). Ghi chú rằng đổi SĐT đã xác minh sẽ ẩn huy hiệu. Thêm lịch sử xem/tìm kiếm (`components/discovery-history.php`). `display_date()` và `status_label()`. |
| `app/Views/reports/index.php` | i18n nhãn trạng thái và loại báo cáo (gom thành `$categoryLabels`). Danh sách hiển thị tên loại thay vì mã. Ngày dùng `display_date()`. |
| `app/Views/reviews/manage.php` | i18n. Trạng thái dùng `status_label()`. |

Các view mới mà những file trên `require` (`components/trust-badges.php`, `discovery-*.php`, `bookings/checkin-guide.php`, `messages/thread.php`, `profile/verification.php`, `admin/trust-host-state.php`) đã được thêm dưới dạng file rỗng trong PR này.

### 4.5. File giống hệt nhau (10)

Nội dung giống từng byte:

- `File_Ideas/Functions.txt`
- `app/Controllers/ReportController.php`
- `app/Controllers/ReviewController.php`
- `app/Controllers/WishlistController.php`
- `app/Models/User.php`
- `app/Models/Wishlist.php`
- 4 sơ đồ trong `image/`: `Home2Home_UI.png`, `erd-home2home.drawio.png`, `so-do-lop_web.png`, `so-do-phan-ra-chuc-nang_web.png`

### 4.6. Chỉ có ở `dev` (4)

Giữ nguyên, `test` không có:

- `File_Ideas/31241020025_Nguyen_Tuan_Khoi.txt`
- `File_Ideas/31241020790_DoSonThanh`
- `File_Ideas/Hoshiyomi's`
- `File_Ideas/Phong_Function_Suggested`

## Phụ lục A. File đang rỗng trên `dev`, giữ rỗng (89)

`test` đã có code cho các file này. Đây là phần việc còn lại khi điền code vào `dev`.

- **Gốc:** `.env.example`, `skill_UIUX.md`, `skill_user.md`, `skill_web.md`
- **`app/Services/`:** `BookingService.php`, `PasswordRecoveryService.php`, `UploadService.php`
- **`app/Views/`:** `components/calendar.php`, `errors/403.php`, `errors/404.php`, `errors/500.php`, `host/availability.php`, `host/dashboard.php`, `host/listing-form.php`
- **`config/`:** `app.php`, `database.php`, `mail.php`
- **`core/`:** `Auth.php`, `Calendar.php`, `Controller.php`, `Csrf.php`, `Database.php`, `ProcedureConnection.php`, `ProcedureStatement.php`, `Router.php`, `Session.php`, `Validator.php`, `bootstrap.php`
- **`public/`:**
  - `.htaccess`, `index.php`, `router.php`, `uploads/.gitkeep`
  - `assets/css/app.css`, `assets/js/app.js`
  - `assets/images/placeholder.svg`, 5 ảnh demo trong `assets/images/demo/`
- **`routes/`:** `web.php`
- **`docs/`:** 34 file tài liệu và ảnh chụp:
  - `architecture.md`, `database-setup.md`, `progress.md`, `requirements-matrix.md`, `stored-procedure-audit.md`, `testing-report.md`, `user-journey-audit.md`, `user-test-matrix.md`…
  - `docs/quality/*.md`
  - `docs/screenshots/`
- **`tests/`:** 14 file. Hiện `.gitignore` của `dev` bỏ qua thư mục này.

## Phụ lục B. File code mới, thêm rỗng (64)

- **`app/Controllers/` (8):** `AdminArticleController`, `ArticleController`, `BookingExtrasController`, `DiscoveryController`, `LocaleController`, `MessageController`, `PaymentController`, `TrustController`
- **`app/Models/` (7):** `Article`, `BookingExtras`, `BookingMessage`, `CheckInReminder`, `Discovery`, `Payment`, `Trust`
- **`app/Services/` (6):** `ArticleText`, `ConversationService`, `DiscoveryService`, `VietQr`, `VnpayGateway`, `ZaloLink`
- **`app/Views/` (27):**
  - `admin/`: `articles/form`, `articles/index`, `holidays`, `messages`, `payments`, `promotions`, `trust-host-state`, `verifications`
  - `articles/`: `index`, `pagination`, `show`
  - `bookings/checkin-guide`
  - `components/`: `discovery-history`, `discovery-home`, `discovery-map-link`, `discovery-mini-map`, `trust-badges`
  - `discovery/map`
  - `host/`: `checkin-guide`, `pricing`, `trust-status`
  - `messages/`: `index`, `thread`
  - `payments/`: `checkout`, `decide-form`, `transfer`
  - `profile/verification`
- **Cấu hình và core:** `config/payment.php`, `core/I18n.php`
- **`lang/en/` (10):** `admin`, `catalog`, `common`, `guest`, `host`, `js`, `messages`, `patterns_messages`, `patterns_procedures`, `procedures`
- **`public/assets/js/` (4):** `booking-extras.js`, `map.js`, `messages.js`, `theme.js`
