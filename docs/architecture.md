# Kiến trúc Home2Home

## Phạm vi

Ứng dụng dùng PHP 8 OOP, MVC, Bootstrap 4.6.2, JavaScript thuần/AJAX và MariaDB/MySQL. `public/` là web root; không có Node.js hay frontend build step.

## Thành phần

- `public/index.php`: front controller và error boundary.
- `routes/web.php`: khai báo route GET/POST.
- `core/`: Router, Controller, Database, Session, Auth, CSRF và bootstrap/autoload.
- `app/Controllers/`: HTTP input, validation, authorization, response.
- `app/Models/`: PDO CALL qua ProcedureConnection và mapping dữ liệu; không SQL nghiệp vụ trực tiếp.
- `app/Services/`: kiểm tra request/điều phối CALL booking và lưu upload; transaction nghiệp vụ nằm trong routines.
- `app/Views/`: HTML/Bootstrap, không truy vấn SQL. View có thể khai báo `$pageScripts[]='js/…'` để layout chỉ nạp JS riêng của trang đó.
- `database/`: schema chuẩn 36 bảng (22 bảng gốc + 13 bảng Nice-to-have + `payment_transactions` của thanh toán online), seed UTF-8, routines/migrations, importer CLI và job CLI `checkin_reminders.php`; không DDL trong request.

### Thành phần Nice-to-have (2026-10-13)

| Chức năng | Controller | Model / Service | View chính | JS | Routine file |
|---|---|---|---|---|---|
| Giá linh hoạt, mã khuyến mãi, Instant Book, thanh toán khi nhận phòng | `BookingExtrasController` (+ `BookingController`, `ListingController`, `HostController`) | `BookingExtras`, `BookingService` | `host/pricing`, `admin/holidays`, `admin/promotions`, `admin/payments`, `listings/show`, `bookings/show` | `booking-extras.js` | `070_booking_extras.sql` |
| Xác minh danh tính, huy hiệu, hướng dẫn nhận phòng | `TrustController` (+ `ProfileController`, `BookingController`) | `Trust` | `profile/verification`, `admin/verifications`, `host/checkin-guide`, `bookings/checkin-guide`, `components/trust-badges`, `host/trust-status` | — | `075_trust.sql` |
| Tin nhắn, Zalo, quản lý tin nhắn, nhắc lịch | `MessageController`, `NotificationController` | `BookingMessage`, `CheckInReminder`, `ConversationService`, `ZaloLink` | `messages/index`, `messages/thread`, `admin/messages` | `messages.js` | `080_conversation.sql` |
| Bản đồ, lịch sử, gợi ý | `DiscoveryController` (+ `HomeController`, `ListingController`, `ProfileController`) | `Discovery`, `DiscoveryService` | `discovery/map`, `components/discovery-*` | `map.js` | `085_discovery.sql` |
| Bài viết/cẩm nang | `ArticleController`, `AdminArticleController` | `Article`, `ArticleText`, `UploadService` | `articles/*`, `admin/articles/*` | — | `090_content.sql` |
| Thanh toán online: VietQR (Admin xác nhận), VNPay sandbox (2026-10-15) | `PaymentController` (+ `BookingController`, `BookingExtrasController`) | `Payment`, `VietQr`, `VnpayGateway`, `config/payment.php` (chỉ đọc `.env`) | `payments/checkout`, `payments/transfer`, `payments/decide-form`, `bookings/show`, `bookings/index`, `admin/payments` | — | `095_online_payment.sql` |
| Chế độ tối | — (layout) | — | `layouts/main`, `layouts/auth` | `theme.js` (nạp trong `<head>`) | — |
| Giao diện tiếng Việt/tiếng Anh | `LocaleController` (`POST /locale`) | `Core\I18n`, hàm `__()` trong `core/bootstrap.php` | `layouts/main`, `layouts/auth` (nút EN/VI); mọi view gọi `__()` | `h2hT()` trong `app.js` | — (không đổi routine; thông báo của routine dịch bằng mẫu) |

### Giao diện tiếng Việt/tiếng Anh

- Câu tiếng Việt trong code là khóa: view gọi `__('Đăng nhập')`, JS gọi `h2hT('Đang gửi…')`. Từ điển `lang/en/*.php` ánh xạ sang tiếng Anh, chia theo khu vực: `common` (layout, trang chủ, chi tiết chỗ ở, đăng nhập, bản đồ), `guest`, `host`, `admin`, `messages` (thông báo runtime của PHP), `procedures` (thông báo SIGNAL của routine), `catalog` (loại chỗ ở, chính sách hủy, tiện nghi, ngày lễ trong dữ liệu mẫu), `js`. Thiếu khóa thì hiện lại câu tiếng Việt gốc.
- Câu có phần thay đổi (mã booking, tên chỗ ở, ngày) dùng mẫu `{chỗ trống}` trong `lang/en/patterns_messages.php` và `patterns_procedures.php`; `I18n::matchPattern` khớp mẫu và dịch tiếp giá trị bắt được nếu đó cũng là câu trong từ điển (ví dụ nhãn trạng thái).
- Ngôn ngữ lưu trong cookie `h2h_locale` (1 năm, HttpOnly, SameSite=Lax), mặc định `vi`, không lưu vào tài khoản nên không cần bảng mới. `POST /locale` kiểm tra CSRF và chỉ quay lại đường dẫn tương đối cùng site (`I18n::safeReturnPath`).
- Flash message được dịch khi layout in ra; `Controller::json` và `Csrf` dịch message/lỗi trong JSON. Layout in từ điển `js.php` thành `<script type="application/json" id="h2h-i18n">` cho `h2hT()`; `theme.js` chạy trước `app.js` nên nút chế độ tối mang sẵn nhãn theo ngôn ngữ trong `data-label-dark-on`/`data-label-dark-off`.
- Không dịch dữ liệu người dùng nhập: tên/mô tả/địa chỉ chỗ ở, đánh giá, tin nhắn, bài viết, tên người. Giá vẫn dạng `1.250.000đ`, ngày dạng `d/m/Y` ở cả hai ngôn ngữ. Hàm dùng chung trong `core/bootstrap.php`: `status_label()` đổi mã trạng thái trong CSDL (`pending`, `confirmed`, `visible`…) thành nhãn đã dịch, `display_date()` in ngày `d/m/Y` (thêm `H:i` khi cần giờ, bỏ micro giây), `display_decimal()` in số thập phân (điểm, phần trăm) với dấu phẩy khi xem tiếng Việt và dấu chấm khi xem tiếng Anh.

Dataflow dữ liệu: `Browser → Router → Controller (Auth/CSRF/validation) → Model/Service → ProcedureConnection → PDO CALL → routine → tables → result sets drained/closed → View/JSON`. Migration `040` giữ lại nhưng không chạy mặc định; chỉ review/executed riêng khi có xác nhận.

## Dataflow chính

### Tìm chỗ ở

`Search form → HomeController → Listing::search → listings/property_types/photos/reviews → HTML`

Khi có ngày, query loại listing có ngày bị chặn hoặc booking `pending/confirmed` giao nhau. Chỉ listing `approved`, `visible`, chưa soft-delete được trả về.

### Quote và đặt chỗ

`Date/guest input → GET JSON quote → BookingService::quote → DB availability/overlap → JSON total`

`POST booking + CSRF → Auth/validation → transaction → lock listing → kiểm tra ngày/sức chứa/block/overlap → tính lại giá → bookings + booking_nights + booking_events + notifications → commit → redirect`

Browser không gửi tổng tiền. `policy_snapshot` và giá từng đêm được lưu ở thời điểm đặt để thay đổi giá/chính sách sau này không làm sai booking cũ.

### Host xác nhận

`POST transition → role host + ownership → lock booking → kiểm tra state machine → kiểm tra overlap confirmed và blocked date → update status + event + guest notification → commit`

State machine hiện có:

- `pending → confirmed | rejected | cancelled`
- `confirmed → completed | cancelled`

### Hủy booking

`Guest POST cancel → ownership + state check → đọc policy_snapshot → tính refund trên server → booking_cancellations + event + notification → commit`.

### Kiểm duyệt

`Admin POST → role check → listings moderation fields + listing_moderation_events + admin_audit_logs` trong transaction.

### Giá linh hoạt, mã khuyến mãi và Đặt ngay

`Ngày + mã → GET JSON quote → sp_booking_quote → sp_booking_extras_price (không khóa; mã sai thành thông báo) → JSON bảng giá`

`POST booking + promo_code → sp_booking_create → lock listing → kiểm tra trùng/ngày chặn → sp_booking_extras_price (strict: khóa cài đặt, mã khuyến mãi, đếm lượt dùng) → booking_nights theo giá từng đêm + booking_price_breakdowns + booking_payments (unpaid) → Instant Book ? confirmed + event hệ thống + thông báo Guest/Host : pending → commit`

Giá một đêm: ngày lễ > cuối tuần (đêm thứ Sáu/thứ Bảy) > giá gốc; giảm theo tháng (28+ đêm) thay thế giảm theo tuần (7+ đêm).

### Thanh toán khi nhận phòng

`Host POST /host/bookings/{id}/payment hoặc Admin POST /admin/payments/{id}/paid → sp_booking_payment_mark_paid (chủ chỗ ở/Admin, từ ngày nhận phòng, một lần) → booking_payments + booking_payment_events + notification`; `Admin POST refund + ghi chú → sp_booking_payment_refund → refunded + notification + audit`. Booking đã thanh toán online ghi `method` = `vietqr`/`vnpay` trên cùng dòng `booking_payments`.

### Thanh toán online

- **VietQR:** `Guest GET /bookings/{id}/pay → POST /bookings/{id}/pay/vietqr → sp_payment_start (khóa listing → booking → payment; booking confirmed, chưa thu, VND; dùng lại mã đang mở cùng số tiền) → GET /payments/{id}` hiển thị ảnh QR `img.vietqr.io` theo tài khoản trong `.env` và nội dung `H2H<booking>X<5 hex>`. `POST /payments/{id}/transferred → sp_payment_transfer_submit → submitted + thông báo mọi Admin`. `Admin POST /admin/payments/transfers/{id} (confirm | reject + lý do ≥ 5 ký tự) → sp_admin_payment_transfer_decide → booking_payments paid/vietqr + booking_payment_events + thông báo khách + audit`; từ chối thì khách lấy mã mới.
- **VNPay:** `POST /bookings/{id}/pay/vnpay → sp_payment_start → VnpayGateway::paymentUrl` (v2.1.0, số tiền ×100, giờ GMT+7, hết hạn 15 phút, HMAC-SHA512) `→ redirect cổng VNPay`. Kết quả về `GET /payments/vnpay/return` (trình duyệt) và `GET /payments/vnpay/ipn` (máy chủ VNPay, trả JSON `RspCode`): PHP kiểm tra chữ ký + mã merchant trước, rồi `sp_payment_vnpay_result` đối chiếu mã tham chiếu và số tiền, ghi một lần (gọi lại trả `already`), tiền về sau khi booking đã thanh toán hoặc đã hủy thì báo Admin hoàn tiền. Khóa bí mật chỉ nằm trong `.env`, không vào DB.

### Xác minh, huy hiệu và hướng dẫn nhận phòng

`Hồ sơ POST /profile/verification → sp_identity_verification_submit → identity_verifications (pending)`; `Admin POST decide → sp_admin_identity_verification_decide → che CCCD + notification + audit`. Huy hiệu tính trực tiếp bằng `sp_trust_badges` mỗi lần tải trang. Hướng dẫn nhận phòng chỉ trả về qua `sp_checkin_guide_for_booking` khi booking `confirmed`/`completed` và người xem là Guest của booking, Host chủ chỗ ở hoặc Admin.

### Tin nhắn và nhắc lịch

`POST /bookings/{id}/messages + CSRF → MessageController → sp_booking_message_send (khóa booking, kiểm tra người tham gia/trạng thái/1–2000 ký tự) → booking_messages + tối đa một thông báo chưa đọc mỗi booking`. Không real-time: tin mới hiện khi tải lại trang.

`checkin_reminders.php (CLI, Task Scheduler/cron) hoặc ConversationService::remindOpportunistically (khi tải trang, mỗi người tối đa 30 phút/lần) → sp_checkin_reminders_run → INSERT IGNORE booking_reminders + notifications`.

### Bản đồ và gợi ý

`map.js kéo/thu phóng → GET /api/listings/map?north&south&east&west → DiscoveryController → sp_discovery_map_search (tối đa 100, hỗ trợ vượt kinh tuyến 180) → JSON → marker + danh sách`. Tile lấy từ OpenStreetMap bằng script nội bộ (không Leaflet/CDN).

`Xem chi tiết/tìm kiếm (đã đăng nhập) → DiscoveryService → listing_views/search_history (giữ 20 lần tìm gần nhất) → sp_discovery_recommendations → kệ “Gợi ý cho bạn” trên trang chủ`. Lỗi lịch sử/gợi ý chỉ được ghi log, không làm hỏng trang.

### Bài viết

`Admin form + ảnh bìa + CSRF → AdminArticleController → UploadService → sp_admin_article_save/set_status/delete → articles + admin_audit_logs`; `GET /articles, /articles/{slug} → sp_article_public_* (chỉ bài published, chưa xóa) → ArticleText::toHtml (escape trước, chỉ sinh <p>, <br>, <h2>)`.

## Ranh giới bảo mật

- Password hash bằng API chuẩn của PHP; session regenerate khi login.
- Mọi mutation dùng CSRF; mọi output HTML được escape.
- Prepared statement cho dữ liệu người dùng.
- Host cần role và ownership; Admin cần role; Guest booking/review cần ownership.
- Upload giới hạn 5 MB, kiểm tra MIME và đổi tên ngẫu nhiên.
- Nice-to-have: quyền được kiểm tra lại trong routine (người tham gia booking, chủ chỗ ở, Admin); CCCD bị che sau khi Admin quyết định và không ghi vào audit; lịch sử xem/tìm kiếm chỉ chủ tài khoản đọc/xóa được; mã cửa/mật khẩu Wi-Fi chỉ được trả về cho người có quyền và được che trên giao diện cho đến khi bấm “Hiện”.

## Quyết định quan trọng

- Dùng schema hiện hữu trong ERD (`base_nightly_rate`, `policy_snapshot`, event tables) làm nguồn đúng.
- BookingService là facade; routine booking giữ atomicity/locking/snapshot và dùng savepoint để không commit transaction của caller.
- Không tích hợp payment/map/email khi chưa có credential và yêu cầu nghiệp vụ đủ chi tiết. Cập nhật 2026-10-13: thanh toán chỉ là “thanh toán khi nhận phòng”, VNPay/MoMo chờ thông tin merchant (điểm tích hợp: `booking_payments.method`, `sp_booking_payment_mark_paid`). Cập nhật 2026-10-15: thêm chuyển khoản VietQR và VNPay sandbox (mục “Thanh toán online” ở trên); thông tin tài khoản nhận tiền và khóa VNPay nằm trong `.env`, chưa có merchant VNPay thật; bản đồ dùng tile OpenStreetMap công khai, không cần credential nhưng cần Internet; nhắc lịch và tin nhắn chỉ trong ứng dụng, chưa gửi email.
- Tọa độ được thêm bằng cách sửa trực tiếp `sp_listing_create/update` (một đường ghi duy nhất) thay vì tạo routine song song.

