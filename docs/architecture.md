# Kiến trúc Home2Home

## Phạm vi

Ứng dụng dùng PHP 8 OOP, MVC, Bootstrap 4.6.2, JavaScript thuần/AJAX và MariaDB/MySQL. `public/` là web root; không có Node.js hay frontend build step.

## Thành phần

- `public/index.php`: front controller và error boundary.
- `routes/web.php`: khai báo route GET/POST.
- `core/`: Router, Controller, Database, Session, Auth, CSRF và bootstrap/autoload.
- `app/Controllers/`: HTTP input, validation, authorization, response.
- `app/Models/`: prepared query và mapping dữ liệu.
- `app/Services/`: nghiệp vụ nhiều bảng/transaction như booking và upload.
- `app/Views/`: HTML/Bootstrap, không truy vấn SQL.
- `database/`: schema, seed UTF-8 và importer CLI.

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

## Ranh giới bảo mật

- Password hash bằng API chuẩn của PHP; session regenerate khi login.
- Mọi mutation dùng CSRF; mọi output HTML được escape.
- Prepared statement cho dữ liệu người dùng.
- Host cần role và ownership; Admin cần role; Guest booking/review cần ownership.
- Upload giới hạn 5 MB, kiểm tra MIME và đổi tên ngẫu nhiên.

## Quyết định quan trọng

- Dùng schema hiện hữu trong ERD (`base_nightly_rate`, `policy_snapshot`, event tables) làm nguồn đúng.
- Booking nằm trong Service vì thay đổi nhiều bảng cần atomicity.
- Không tích hợp payment/map/email khi chưa có credential và yêu cầu nghiệp vụ đủ chi tiết.

