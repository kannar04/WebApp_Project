# Thiết lập cơ sở dữ liệu

## Cấu hình

Sao chép `.env.example` thành `.env` và đặt các biến `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Không commit `.env`.

Database mặc định là `db_home2home`, charset `utf8mb4`.

## Import

Trong PowerShell tại thư mục dự án:

```powershell
C:\xampp\php\php.exe database\import.php
```

Importer đọc SQL bằng PHP để giữ nguyên tiếng Việt UTF-8. Nó chạy `schema.sql` rồi `seed.sql`; seed dùng upsert và có thể chạy lại:

```powershell
C:\xampp\php\php.exe database\import.php --seed-only
```

Tài khoản seed dùng chung mật khẩu `Password123!` và chỉ dành cho local/test:

- `admin@home2home.test`
- `host@home2home.test`
- `guest@home2home.test`

## Nguyên tắc dữ liệu

- Không nhận tổng tiền từ client.
- `booking_nights` lưu giá từng đêm; `policy_snapshot` lưu chính sách tại lúc đặt.
- `booking_events` là lịch sử trạng thái; `notifications` là outbox/thông báo trong ứng dụng.
- Mọi kiểm tra overlap dùng khoảng nửa mở `[check_in, check_out)`.
- Không chạy test phá hủy trên DB chia sẻ. `schema_test.php` chỉ tạo/xóa đúng database tạm `db_home2home_schema_test`.

