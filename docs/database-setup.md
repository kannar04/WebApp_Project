# Thiết lập cơ sở dữ liệu

## Cấu hình

Sao chép `.env.example` thành `.env` và đặt các biến `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Không commit `.env`.

Database mặc định là `db_home2home`, charset `utf8mb4`.

## Import

Trong PowerShell tại thư mục dự án:

```powershell
C:\xampp\php\php.exe database\import.php
```

Importer nạp `.env` qua bootstrap và đọc SQL bằng PHP để giữ nguyên tiếng Việt UTF-8. Nó chạy `schema.sql` rồi `seed.sql` **chỉ khi chưa có bảng**. `IF NOT EXISTS` không kiểm tra/tự migrate schema cũ. Nếu DB hiện hữu, dùng installer routine bên dưới.

Seed dùng upsert, chạy lại không tạo thêm bản ghi nhưng **có thể ghi đè dữ liệu mang ID demo**. Chỉ sau khi review SQL và xác nhận DB local dùng thử có thể seed lại:

```powershell
C:\xampp\php\php.exe database\import.php --seed-only --confirm-demo-seed
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

Fresh database: database/import.php nạp schema.sql → seed.sql → routines. Schema khởi tạo đã đối chiếu chính xác DDL của 22 bảng hiện hữu (loại bỏ bộ đếm AUTO_INCREMENT); từ 2026-10-13 schema có thêm 13 bảng Nice-to-have, tổng **35 bảng**. Với phpMyAdmin có thể import SQL routine files theo thứ tự 001, 005, 010, 011, 020, 030, 050, 055, 060, 065, 070, 075, 080, 085, 090 sau schema/seed (bỏ qua 040, giống installer); delimiter directives đã có trong các SQL files. Không import `testing.sql` hay `testing_*.sql` vào production.

Cách cài mới ở trên **không đổi** sau khi gộp Nice-to-have: DB trống vẫn chỉ cần `database\import.php`. DB `db_home2home` đã có dữ liệu thì làm theo mục [Nâng cấp db_home2home đang dùng](#nâng-cấp-db_home2home-đang-dùng-lên-bản-nice-to-have-2026-10-13).

Migration lịch sử `040_prune_transition_helpers.sql` **không chạy tự động**. Không DROP routine trên DB chia sẻ khi chưa kiểm tra mọi consumer và có xác nhận của nhóm; việc không thấy CALL trong PHP không đủ để kết luận dư thừa. Không xóa lịch sử migration.

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

## Kiểm thử thiết lập và parity

```powershell
C:\xampp\php\php.exe tests\schema_test.php --compare-live
C:\xampp\php\php.exe tests\fresh_setup_test.php
```

Hai test chỉ tạo/xóa đúng DB ngẫu nhiên riêng `db_home2home_schema_test_<12 hex>` trên loopback/local. Test fresh setup dùng server PHP cổng riêng, kiểm tra home/detail/assets/quote và login Guest/Host/Admin. Test đã chạy từ bản sao độc lập gồm các file đủ điều kiện Git, không có `.env`/`.git`/upload người dùng; không phải kiểm chứng quyền clone remote GitHub hay cấu hình Apache trên máy đồng đội.

## Nâng cấp db_home2home đang dùng lên bản Nice-to-have (2026-10-13)

Dùng khi máy đã có `db_home2home` (22 bảng, có dữ liệu) và vừa lấy code mới có các chức năng Nice-to-have. Mục tiêu: thêm 13 bảng mới và cài routine mới **mà không xóa hay sửa dữ liệu cũ**. Không dùng `database\import.php` cho việc này: importer từ chối DB đã có bảng, còn `--seed-only --confirm-demo-seed` chạy lại toàn bộ seed và có thể ghi đè các dòng mang ID demo.

13 bảng mới: `listing_booking_settings`, `public_holidays`, `promotions`, `booking_price_breakdowns`, `booking_payments`, `booking_payment_events`, `identity_verifications`, `listing_checkin_guides`, `booking_messages`, `booking_reminders`, `listing_views`, `search_history`, `articles`. `schema.sql` chỉ có `CREATE DATABASE IF NOT EXISTS`, `USE db_home2home` và `CREATE TABLE IF NOT EXISTS`, không có ALTER/DROP, nên chạy lại trên DB cũ chỉ tạo các bảng còn thiếu.

Nên sao lưu `db_home2home` trước (phpMyAdmin → Export) và bật MySQL trong XAMPP. Mở web **sau** khi làm xong các bước, vì code mới gọi bảng/routine mới.

**Bước 1 — tạo 13 bảng mới.** Chọn một trong hai cách:

- phpMyAdmin → tab **Import** → chọn `database/schema.sql` → bộ ký tự `utf-8` → **Go**.
- Hoặc dòng lệnh, tại thư mục dự án (thêm `-p` nếu tài khoản MySQL có mật khẩu):

```powershell
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "source database/schema.sql"
```

Không dùng `Get-Content ... | mysql` trong PowerShell vì pipe làm hỏng tiếng Việt. MySQL có thể in Note 1007/1050 (database/bảng đã tồn tại): đó là bình thường.

**Bước 2 (không bắt buộc) — dữ liệu demo Nice-to-have.** Chỉ chạy phần seed từ dòng `-- Nice-to-have booking extras` đến hết `database/seed.sql`, **không** chạy lại cả file. Phần này thêm: 11 ngày lễ mẫu năm 2027 (`is_demo`, Admin cần xác nhận lại), mã `CHAOHE10`, cài đặt giá cho listing 2–3 (listing 3 bật Đặt ngay), hướng dẫn nhận phòng cho listing 1, tọa độ cho listing 1–3 (chỉ khi cả hai cột đang trống) và 4 bài viết. Cần listing 1–3 và user id 1 của seed demo.

- phpMyAdmin: chọn `db_home2home` → tab **SQL** → dán đoạn seed đó → **Go**.
- Hoặc PowerShell, tại thư mục dự án:

```powershell
$seed = Get-Content database\seed.sql -Raw -Encoding UTF8
$start = $seed.IndexOf('-- Nice-to-have booking extras')
$out = Join-Path $env:TEMP 'h2h-nice-seed.sql'
[IO.File]::WriteAllText($out, "USE db_home2home;`n" + $seed.Substring($start), (New-Object Text.UTF8Encoding $false))
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "source $out"
Remove-Item $out
```

Chạy lại phần này an toàn với dữ liệu đã sửa: ngày lễ, mã khuyến mãi, cài đặt giá, hướng dẫn nhận phòng dùng `INSERT IGNORE` (có thể in Warning 1062 trùng khóa, bình thường); tọa độ chỉ điền khi đang trống. Riêng 4 bài viết id 1–4 dùng `ON DUPLICATE KEY UPDATE`: chạy lại sẽ ghi đè chuyên mục, tiêu đề, tóm tắt, nội dung, ảnh bìa của 4 bài này (giữ trạng thái xuất bản và slug).

**Bước 3 — cài routine mới** (53 routine mới, cập nhật `sp_booking_quote`, `sp_booking_create`, `sp_listing_create`, `sp_listing_update`):

```powershell
C:\xampp\php\php.exe database\install_procedures.php
```

Installer chỉ chạy khi `.env` trỏ tới `db_home2home` local/loopback; không đổi dữ liệu bảng, không cài `sp_test_*`.

**Bước 4 — kiểm tra:**

```powershell
C:\xampp\php\php.exe tests\schema_test.php --compare-live
```

Sau khi nâng cấp, lệnh này phải PASS với 36 bảng, DDL khớp chính xác `schema.sql` (35 bảng của bản này cộng `payment_transactions` của bản thanh toán online bên dưới).

Đã thử toàn bộ đường nâng cấp ngày 2026-10-13 trên một DB riêng dựng lại từ schema/seed/routine **trước** khi gộp (22 bảng, 106 định nghĩa routine), bằng `mysql.exe ... source` và cùng các lệnh trên (đổi tên DB): 22 bảng cũ giữ nguyên số dòng và CHECKSUM; 13 bảng mới được tạo; seed Nice-to-have chạy hai lần không nhân đôi dữ liệu và giữ chữ tiếng Việt; installer cài 159 định nghĩa, không có `sp_test_*`, không đổi dữ liệu bảng; `schema_test.php --compare-live` PASS 35 bảng. Báo giá thử listing 3, 14–21/04/2027, mã `CHAOHE10` ra 5.747.000đ (không mã 6.247.000đ), listing 1 hai đêm vẫn 2.650.000đ. Cách qua giao diện phpMyAdmin chưa được chạy thử.

## Nâng cấp cho thanh toán online (2026-10-15)

Dùng khi `db_home2home` đã có 35 bảng (đã làm phần nâng cấp ở trên) và vừa lấy code có thanh toán VietQR/VNPay. Không xóa hay sửa dữ liệu cũ:

- Thêm bảng thứ 36 `payment_transactions` (mỗi lần thanh toán online: mã nội dung chuyển khoản, số tiền, trạng thái, mã giao dịch VNPay, Admin xác nhận).
- Cho phép `booking_payments.method` nhận thêm `vietqr`, `vnpay` (thay CHECK `chk_booking_payments_method`) và cập nhật comment cột `paid_by`, `booking_payment_events.actor_id`. Các lệnh `ALTER TABLE` cuối `schema.sql` chạy lại nhiều lần vẫn an toàn.

**Bước 1:** chạy lại `database/schema.sql` như Bước 1 ở trên (phpMyAdmin Import hoặc `mysql.exe ... -e "source database/schema.sql"`).

**Bước 2:** cài routine (8 routine mới trong `095_online_payment.sql`; `sp_booking_extras_detail`, `sp_booking_payment_list` trả thêm cột thanh toán online; `sp_payment_vnpay_result` không bao giờ nhận khóa bí mật VNPay, PHP kiểm tra chữ ký trước khi gọi):

```powershell
C:\xampp\php\php.exe database\install_procedures.php
```

**Bước 3:** điền phần thanh toán trong `.env` local (xem README › Cấu hình thanh toán online), rồi kiểm tra bằng `tests\schema_test.php --compare-live` (36 bảng).

## Nhắc lịch check-in tự động

`database/checkin_reminders.php` tạo một thông báo trong ứng dụng cho Guest và một cho Host của mỗi booking `confirmed` có ngày nhận phòng từ hôm nay đến hôm nay + N ngày (theo ngày Việt Nam). Chạy lại không tạo trùng (khóa chính `booking_reminders`). Không gửi email/SMS.

```powershell
C:\xampp\php\php.exe database\checkin_reminders.php --days=1
```

- `--days` từ 0 đến 30, mặc định 1. Tham số sai → in `Usage: php database/checkin_reminders.php [--days=0..30]`, exit 2. Lỗi DB → in `Check-in reminders failed: …` ra STDERR, exit 1. Thành công → `Check-in reminders: N created for check-ins within D day(s).`
- Chỉ chạy được từ dòng lệnh (gọi qua web trả 404). `.env` được đọc từ thư mục dự án nên không phụ thuộc thư mục hiện tại.
- Lần chạy toàn hệ thống nhắc cả các booking demo đủ điều kiện.
- Không có tác vụ định kỳ, nhắc lịch vẫn được tạo khi người dùng đã đăng nhập tải trang (tối đa mỗi 30 phút một lần cho mỗi người); tác vụ định kỳ là lớp dự phòng.

**Windows Task Scheduler** (chạy hằng ngày 07:00; thay `C:\path\to\project` bằng thư mục dự án; MySQL của XAMPP phải đang chạy lúc đó):

```powershell
schtasks /Create /TN "Home2Home check-in reminders" /SC DAILY /ST 07:00 /F /TR "C:\xampp\php\php.exe C:\path\to\project\database\checkin_reminders.php --days=1"
schtasks /Run /TN "Home2Home check-in reminders"
schtasks /Delete /TN "Home2Home check-in reminders" /F
```

Dòng 2 chạy thử ngay; dòng 3 gỡ tác vụ.

**Linux cron** (chạy hằng ngày 07:00; thay đường dẫn PHP và dự án):

```cron
0 7 * * * php /path/to/project/database/checkin_reminders.php --days=1 >> $HOME/home2home-checkin-reminders.log 2>&1
```

Đã chạy thử script trên DB nâng cấp ở trên: lần đầu `2 created` cho một booking Đặt ngay nhận phòng ngày hôm sau, lần hai `0 created`; `--days=31` và tham số lạ trả exit 2. Bản thân lệnh `schtasks` và dòng cron **chưa được chạy thử** (cron chưa thử trên Linux).

