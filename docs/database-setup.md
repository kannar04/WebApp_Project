# Thiết lập cơ sở dữ liệu

## Cấu hình

Sao chép `.env.example` thành `.env` và đặt các biến `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Không commit `.env`.

Database mặc định là `db_home2home`, charset `utf8mb4`.

## Import

Trong PowerShell tại thư mục dự án:

```powershell
C:\xampp\php\php.exe database\import.php
```

Importer nạp `.env` qua bootstrap và đọc SQL bằng PHP để giữ nguyên tiếng Việt UTF-8. Nó chạy `schema.sql` rồi `seed.sql`; seed dùng upsert và có thể chạy lại:

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
- Không chạy test phá hủy trên DB chia sẻ. `schema_test.php` chỉ tạo/xóa đúng database tạm `db_home2home_schema_test_<random>` được tạo riêng trong mỗi lần chạy.

## Stored Procedure-only setup

Ứng dụng yêu cầu routines từ database/procedures. Với database db_home2home hiện có, **chỉ cài migration routine**, không chạy import/seed để sửa lỗi thiếu routine:

```powershell
C:\xampp\php\php.exe database\install_procedures.php
```

Installer chỉ chạy local/loopback db_home2home, không ALTER/drop/reset bảng, không đổi grants. Migrations bảo toàn 16 routine nhóm, sửa identifiers/collation và mở rộng projection tương thích. Routine source nhắm tới MariaDB 10.4 của XAMPP; không tự khẳng định tương thích Oracle MySQL.

Fresh database: database/import.php hiện nạp schema.sql → seed.sql → routines. Với phpMyAdmin có thể import SQL routine files theo thứ tự 001, 005, 010, 011, 020, 030, 040 sau schema/seed; delimiter directives đã có trong các SQL files. Không import testing.sql vào production.

Chạy PHP built-in server (MySQL vẫn phải chạy trong XAMPP):

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public/router.php
```

Mở http://127.0.0.1:8090/. Apache/XAMPP có thể tiếp tục trỏ DocumentRoot vào public theo cấu hình cũ.

Test fixtures riêng cần opt-in:

```powershell
C:\xampp\php\php.exe database\install_procedures.php --include-tests
C:\xampp\php\php.exe tests\stored_procedure_audit_test.php
C:\xampp\php\php.exe tests\procedure_flow_test.php
```

Không cấp EXECUTE trên sp_test_* hoặc sp_demo_* cho web account production. Dùng account DB riêng với EXECUTE cho runtime routines, không dùng root ở production; thay đổi grants chưa được thực hiện trên DB dùng chung.

SQL parameters dùng utf8mb4_unicode_ci phù hợp cột hiện hữu. Transaction multi-step nằm trong routine; caller transaction được giữ bằng savepoint, không commit lồng. CALL result sets được gateway drain/close trước request tiếp theo.

Chi tiết: [stored-procedure-audit.md](stored-procedure-audit.md), [user-test-matrix.md](user-test-matrix.md).

